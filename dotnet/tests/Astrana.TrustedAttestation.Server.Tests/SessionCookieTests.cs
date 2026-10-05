using System.Security.Claims;
using Astrana.TrustedAttestation.Server.Configuration;
using Astrana.TrustedAttestation.Server.Security;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Authentication.Cookies;
using Microsoft.AspNetCore.Http;
using Sustainsys.Saml2;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// What the session cookie keeps of a sign-in, and how long it lives.
///
/// The cookie keeps the subject, the display name, the locale and what sign-out needs, and nothing else,
/// so a directory attribute the identity system sends is not held for the life of the session. These run
/// the same <see cref="SessionCookie.Configure"/> startup uses, so a regression in the wiring fails here
/// rather than only in a live sign-in.
/// </summary>
public class SessionCookieTests
{
    private static readonly AuthenticationScheme Scheme =
        new(CookieAuthenticationDefaults.AuthenticationScheme, null, typeof(CookieAuthenticationHandler));

    private static CookieAuthenticationOptions Configured(IamOptions? iam = null)
    {
        var options = new CookieAuthenticationOptions();
        SessionCookie.Configure(options, iam ?? new IamOptions());
        return options;
    }

    private static ClaimsPrincipal SignIn(params Claim[] claims) =>
        new(new ClaimsIdentity(claims, "oidc", "name", "role"));

    [Fact]
    public void Only_the_subject_the_name_and_the_locale_survive_an_OpenID_Connect_sign_in()
    {
        var kept = SessionCookie.KeepOnly(
            SignIn(
                new Claim("sub", "alice"),
                new Claim("name", "Alice Anderson"),
                new Claim("locale", "fr"),
                new Claim("email", "alice@example.org"),
                new Claim("groups", "finance"),
                new Claim("relationship_type", "employee")),
            new IamOptions());

        Assert.Equal(["sub", "name", "locale"], kept.Claims.Select(claim => claim.Type));
    }

    [Fact]
    public void The_configured_subject_and_name_claims_are_the_ones_kept()
    {
        var kept = SessionCookie.KeepOnly(
            SignIn(
                new Claim("sub", "opaque-pseudonym"),
                new Claim("employee_number", "E1234"),
                new Claim("display_name", "Alice Anderson")),
            new IamOptions { SubjectClaim = "employee_number", NameClaim = "display_name" });

        Assert.Equal(["employee_number", "display_name"], kept.Claims.Select(claim => claim.Type));
    }

    [Fact]
    public void What_SAML_single_logout_needs_is_kept_with_its_issuer()
    {
        // The SAML library reads the logout NameID's issuer to know which identity provider to sign out of,
        // so filtering must not lose it.
        const string identityProvider = "https://idp.example/saml";

        var kept = SessionCookie.KeepOnly(
            SignIn(
                new Claim(ClaimTypes.NameIdentifier, "alice", null, identityProvider),
                new Claim(Saml2ClaimTypes.LogoutNameIdentifier, ",,,,alice", null, identityProvider),
                new Claim(Saml2ClaimTypes.SessionIndex, "session-1", null, identityProvider),
                new Claim("department", "Finance", null, identityProvider)),
            new IamOptions());

        Assert.Equal(
            [ClaimTypes.NameIdentifier, Saml2ClaimTypes.LogoutNameIdentifier, Saml2ClaimTypes.SessionIndex],
            kept.Claims.Select(claim => claim.Type));
        Assert.All(kept.Claims, claim => Assert.Equal(identityProvider, claim.Issuer));
    }

    [Fact]
    public void The_identity_keeps_its_authentication_type_and_claim_types()
    {
        var kept = SessionCookie.KeepOnly(SignIn(new Claim("sub", "alice")), new IamOptions());
        var identity = Assert.Single(kept.Identities);

        Assert.True(identity.IsAuthenticated);
        Assert.Equal("oidc", identity.AuthenticationType);
        Assert.Equal("name", identity.NameClaimType);
        Assert.Equal("role", identity.RoleClaimType);
    }

    [Fact]
    public async Task Signing_in_writes_the_reduced_principal_and_keeps_the_ID_token_sign_out_sends()
    {
        // RP-initiated logout sends the ID token back as id_token_hint, read from the authentication
        // properties. Reducing the claims must leave those properties alone.
        var options = Configured();
        var properties = new AuthenticationProperties();
        properties.StoreTokens([new AuthenticationToken { Name = "id_token", Value = "the-id-token" }]);

        var context = new CookieSigningInContext(
            new DefaultHttpContext(),
            Scheme,
            options,
            SignIn(new Claim("sub", "alice"), new Claim("email", "alice@example.org")),
            properties,
            new CookieOptions());

        await options.Events.SigningIn(context);

        Assert.Equal(["sub"], context.Principal!.Claims.Select(claim => claim.Type));
        Assert.Equal("the-id-token", context.Properties.GetTokenValue("id_token"));
    }

    [Fact]
    public async Task The_session_is_renewed_on_every_request_so_it_ends_after_eight_idle_hours()
    {
        // The framework renews only once half the window has passed, which would end an idle session
        // anywhere between four and eight hours. Renewing every time makes it eight.
        var options = Configured();
        var ticket = new AuthenticationTicket(SignIn(new Claim("sub", "alice")), Scheme.Name);
        var context = new CookieSlidingExpirationContext(
            new DefaultHttpContext(),
            Scheme,
            options,
            ticket,
            elapsedTime: TimeSpan.FromMinutes(1),
            remainingTime: TimeSpan.FromHours(8) - TimeSpan.FromMinutes(1))
        {
            ShouldRenew = false,
        };

        await options.Events.CheckSlidingExpiration(context);

        Assert.True(context.ShouldRenew);
        Assert.Equal(TimeSpan.FromHours(8), options.ExpireTimeSpan);
        Assert.True(options.SlidingExpiration);
    }

    [Theory]
    [InlineData("/api/v1/me", StatusCodes.Status401Unauthorized)]
    [InlineData("/api/me", StatusCodes.Status401Unauthorized)]
    public async Task An_unauthenticated_API_call_gets_a_status_code_not_a_redirect(string path, int status)
    {
        var options = Configured();
        var http = new DefaultHttpContext();
        http.Request.Path = path;

        await options.Events.RedirectToLogin(new RedirectContext<CookieAuthenticationOptions>(
            http, Scheme, options, new AuthenticationProperties(), "https://idp.example/login"));

        Assert.Equal(status, http.Response.StatusCode);
        Assert.False(http.Response.Headers.ContainsKey("Location"));
    }

    [Fact]
    public async Task An_unauthenticated_page_request_is_redirected_to_sign_in()
    {
        var options = Configured();
        var http = new DefaultHttpContext();
        http.Request.Path = "/me";

        await options.Events.RedirectToLogin(new RedirectContext<CookieAuthenticationOptions>(
            http, Scheme, options, new AuthenticationProperties(), "https://idp.example/login"));

        Assert.Equal(StatusCodes.Status302Found, http.Response.StatusCode);
        Assert.Equal("https://idp.example/login", http.Response.Headers.Location);
    }
}
