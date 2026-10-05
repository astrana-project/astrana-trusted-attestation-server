using Astrana.TrustedAttestation.Server.Configuration;
using Microsoft.AspNetCore.Antiforgery;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Authentication.Cookies;
using Microsoft.AspNetCore.Authentication.OpenIdConnect;
using Microsoft.Extensions.Options;

namespace Astrana.TrustedAttestation.Server.Endpoints;

/// <summary>
/// Sign-out. It ends the session here and then at the identity system, so "sign out" means what a member
/// expects rather than leaving them signed straight back in on the next visit. It lands on the landing
/// page at <see cref="SignedOutPath"/>, which carries a confirmation, so the member can see it worked.
/// </summary>
public static class SignOutEndpoint
{
    public const string Path = "/signout";

    /// <summary>The fixed address sign-out lands on in all three implementations.</summary>
    public const string SignedOutPath = "/signed-out";

    public static void MapSignOut(this IEndpointRouteBuilder app, IamProtocol protocol, string challengeScheme)
    {
        app.MapPost(Path, (HttpContext http, IAntiforgery antiforgery, IOptionsMonitor<OpenIdConnectOptions> oidc) =>
            SignOutAsync(http, antiforgery, challengeScheme, protocol == IamProtocol.Saml
                ? _ => Task.FromResult(true)
                : cancellationToken => SupportsRpLogout(oidc.Get(challengeScheme), cancellationToken)));
    }

    /// <param name="identitySystemSignsOut">
    /// Whether the identity system is asked to end its own session too. Under SAML it always is, and the
    /// SAML handler degrades on its own when the identity provider publishes no single logout address.
    /// </param>
    internal static async Task<IResult> SignOutAsync(
        HttpContext http,
        IAntiforgery antiforgery,
        string challengeScheme,
        Func<CancellationToken, Task<bool>> identitySystemSignsOut)
    {
        // With no live session there is nothing to end, so the answer is the same redirect whatever the
        // anti-forgery token says. The usual case is a member signing out of a page left open past the
        // idle timeout, whose token no longer matches anything.
        if (http.User.Identity?.IsAuthenticated != true)
        {
            return Results.Redirect(SignedOutPath);
        }

        // A live session needs the anti-forgery token, matching the other two. SameSite=Lax already blocks
        // a cross-site form POST, and the token is defence in depth, so a forced sign-out cannot be
        // triggered the one way Lax does not cover. The self-service page renders it in the sign-out form.
        // A missing or wrong token is HTTP 403 with no body, as in the other two.
        if (!await antiforgery.IsRequestValidAsync(http))
        {
            return Results.StatusCode(StatusCodes.Status403Forbidden);
        }

        // The local session is always ended. The identity system is asked to end its own session only when
        // it offers a way to. An OpenID Connect provider that advertises no end_session_endpoint (Authelia,
        // for one) makes signing out of the OpenID Connect scheme throw, which would abort the response with
        // the session cookie still in place, signed out in name only. A local sign-out must never depend on
        // the identity system.
        var schemes = new List<string> { CookieAuthenticationDefaults.AuthenticationScheme };
        if (await identitySystemSignsOut(http.RequestAborted))
        {
            schemes.Add(challengeScheme);
        }

        return Results.SignOut(new AuthenticationProperties { RedirectUri = SignedOutPath }, [.. schemes]);
    }

    /// <summary>
    /// Whether the OpenID Connect provider offers RP-initiated logout, which is whether it advertises an
    /// end_session_endpoint in its discovery document. Reads the cached discovery document, so there is no
    /// extra network call on the sign-out path, and any failure to read it degrades to a local sign-out.
    /// </summary>
    internal static async Task<bool> SupportsRpLogout(OpenIdConnectOptions options, CancellationToken cancellationToken)
    {
        if (options.ConfigurationManager is null)
        {
            return false;
        }

        try
        {
            var configuration = await options.ConfigurationManager.GetConfigurationAsync(cancellationToken);
            return !string.IsNullOrEmpty(configuration.EndSessionEndpoint);
        }
        catch
        {
            return false;
        }
    }
}
