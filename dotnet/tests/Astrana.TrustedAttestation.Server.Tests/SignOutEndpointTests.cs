using System.Security.Claims;
using Astrana.TrustedAttestation.Server.Endpoints;
using Astrana.TrustedAttestation.Server.Tests.Support;
using Microsoft.AspNetCore.Authentication.Cookies;
using Microsoft.AspNetCore.Authentication.OpenIdConnect;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Http.HttpResults;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// Sign-out, which always lands on the signed-out page when there is nothing to end, and otherwise needs
/// the anti-forgery token and ends the session here and, when it offers a way to, at the identity system.
/// </summary>
public class SignOutEndpointTests
{
    private const string Challenge = OpenIdConnectDefaults.AuthenticationScheme;

    private static HttpContext Anonymous() => new DefaultHttpContext();

    private static HttpContext SignedIn() => new DefaultHttpContext
    {
        User = new ClaimsPrincipal(new ClaimsIdentity([new Claim("sub", "alice")], "test")),
    };

    private static Func<CancellationToken, Task<bool>> IdentitySystemSignsOut(bool signsOut) =>
        _ => Task.FromResult(signsOut);

    [Fact]
    public async Task With_no_live_session_it_redirects_to_signed_out_whatever_the_token_says()
    {
        // An expired session is the usual case. There is nothing to end, so the token is not even read.
        var antiforgery = new FakeAntiforgery(valid: false);

        var result = await SignOutEndpoint.SignOutAsync(
            Anonymous(), antiforgery, Challenge, IdentitySystemSignsOut(true));

        Assert.Equal(SignOutEndpoint.SignedOutPath, Assert.IsType<RedirectHttpResult>(result).Url);
        Assert.False(antiforgery.Checked);
    }

    [Fact]
    public async Task With_a_live_session_a_missing_or_wrong_token_is_403_with_no_body_and_no_content_type()
    {
        // The same refusal in all three implementations, and nothing beyond the status, like every other error.
        var result = await SignOutEndpoint.SignOutAsync(
            SignedIn(), new FakeAntiforgery(valid: false), Challenge, IdentitySystemSignsOut(true));

        var response = await ExecutedResult.Of(result);
        Assert.Equal(StatusCodes.Status403Forbidden, response.StatusCode);
        Assert.Equal(0, response.Body.Length);
        Assert.Null(response.ContentType);
    }

    [Fact]
    public async Task With_a_live_session_it_ends_the_session_here_and_at_the_identity_system()
    {
        var result = await SignOutEndpoint.SignOutAsync(
            SignedIn(), new FakeAntiforgery(valid: true), Challenge, IdentitySystemSignsOut(true));

        var signOut = Assert.IsType<SignOutHttpResult>(result);
        Assert.Equal([CookieAuthenticationDefaults.AuthenticationScheme, Challenge], signOut.AuthenticationSchemes);
        Assert.Equal(SignOutEndpoint.SignedOutPath, signOut.Properties!.RedirectUri);
    }

    [Fact]
    public async Task An_identity_system_with_no_way_to_sign_out_leaves_a_local_sign_out()
    {
        var result = await SignOutEndpoint.SignOutAsync(
            SignedIn(), new FakeAntiforgery(valid: true), Challenge, IdentitySystemSignsOut(false));

        var signOut = Assert.IsType<SignOutHttpResult>(result);
        Assert.Equal([CookieAuthenticationDefaults.AuthenticationScheme], signOut.AuthenticationSchemes);
    }

    [Fact]
    public async Task A_provider_whose_discovery_document_cannot_be_read_offers_no_logout()
    {
        Assert.False(await SignOutEndpoint.SupportsRpLogout(new OpenIdConnectOptions(), CancellationToken.None));
    }
}
