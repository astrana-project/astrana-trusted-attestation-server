using System.Security.Claims;
using Astrana.TrustedAttestation.Server.Configuration;
using Astrana.TrustedAttestation.Server.Services;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Authentication.Cookies;
using Sustainsys.Saml2;

namespace Astrana.TrustedAttestation.Server.Security;

/// <summary>
/// The session cookie: what it is called, how long it lives and what it keeps of a sign-in.
///
/// <para>The cookie represents "already signed in through the organisation's identity system", not a
/// bearer token Astrana Trusted Attestation issued. It keeps the subject, the display name, the locale and
/// what sign-out needs, and nothing else, so no other directory attribute the identity system sends is
/// held for the life of the session (decision record 10 in docs/adr).</para>
/// </summary>
public static class SessionCookie
{
    /// <summary>Named to match the contract's securityScheme.</summary>
    public const string Name = "ata_session";

    /// <summary>A session ends after this long without a request.</summary>
    public static readonly TimeSpan IdleTimeout = TimeSpan.FromHours(8);

    public static void Configure(CookieAuthenticationOptions options, IamOptions iam)
    {
        options.Cookie.Name = Name;
        options.Cookie.HttpOnly = true;
        options.Cookie.SecurePolicy = CookieSecurePolicy.Always;
        options.Cookie.SameSite = SameSiteMode.Lax;
        options.SlidingExpiration = true;
        options.ExpireTimeSpan = IdleTimeout;

        // Applied as the session is written, which is after the OpenID Connect handler has merged the
        // UserInfo claims, so the filter sees every claim either protocol produced. The authentication
        // properties pass through untouched, and they carry the ID token sign-out sends as id_token_hint.
        options.Events.OnSigningIn = context =>
        {
            context.Principal = KeepOnly(context.Principal!, iam);
            return Task.CompletedTask;
        };

        // Renewed on every request rather than only once half the window has passed, which is the
        // framework's default, so a session ends after exactly the idle timeout without a request.
        options.Events.OnCheckSlidingExpiration = context =>
        {
            context.ShouldRenew = true;
            return Task.CompletedTask;
        };

        // The API answers with status codes, and only the browser-facing pages redirect. Without this, an
        // unauthenticated API call would get a 302 to the identity system instead of the 401 the contract
        // defines.
        options.Events.OnRedirectToLogin = context => StatusCodeForApi(context, StatusCodes.Status401Unauthorized);
        options.Events.OnRedirectToAccessDenied = context => StatusCodeForApi(context, StatusCodes.Status403Forbidden);
    }

    /// <summary>
    /// The sign-in reduced to what the session needs. The configured subject and name claims, the locale,
    /// the SAML NameID the subject falls back to, and the SAML NameID and session index single logout
    /// sends back. Each identity keeps its authentication type and its name and role claim types, and each
    /// claim keeps its issuer, which the SAML library reads to know which identity provider to sign out of.
    /// </summary>
    internal static ClaimsPrincipal KeepOnly(ClaimsPrincipal principal, IamOptions iam)
    {
        var kept = new HashSet<string>(StringComparer.Ordinal)
        {
            iam.SubjectClaim,
            iam.NameClaim,
            ClaimsRequestCultureProvider.ClaimType,
            ClaimTypes.NameIdentifier,
            Saml2ClaimTypes.LogoutNameIdentifier,
            Saml2ClaimTypes.SessionIndex,
        };

        return new ClaimsPrincipal(principal.Identities.Select(identity => new ClaimsIdentity(
            identity.Claims.Where(claim => kept.Contains(claim.Type)),
            identity.AuthenticationType,
            identity.NameClaimType,
            identity.RoleClaimType)));
    }

    private static Task StatusCodeForApi<TOptions>(RedirectContext<TOptions> context, int statusCode)
        where TOptions : AuthenticationSchemeOptions
    {
        if (context.Request.Path.StartsWithSegments("/api"))
        {
            // Status code only, no body. Response bodies are for data, not error explanation.
            context.Response.StatusCode = statusCode;
            return Task.CompletedTask;
        }

        context.Response.Redirect(context.RedirectUri);
        return Task.CompletedTask;
    }
}
