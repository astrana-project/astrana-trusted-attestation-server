using Astrana.TrustedAttestation.Server.Security;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Http;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.IdentityModel.Tokens;
using Sustainsys.Saml2.Exceptions;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// What happens when a SAML assertion is refused.
///
/// The assertion consumer service takes unauthenticated input by nature -- the IdP posts to it
/// cross-site, so anyone can -- and refusing bad input without falling over is its main job. The
/// conformance suite exercises this from outside on a SAML deployment; these tests pin the
/// classification underneath, which the suite cannot see: WHICH exceptions read as "refused" and which
/// still escape.
///
/// The escape half matters as much. A handler that swallowed everything on this path would hide exactly
/// the defects worth knowing about, behind a redirect that looks like a member mistyping.
/// </summary>
public class SamlFailureHandlingTests
{
    private const string ModulePath = "/Saml2";

    /// <summary>
    /// Runs one request through the middleware with the terminal step throwing, and reports what came
    /// out: a redirect, or the exception propagating.
    /// </summary>
    private static async Task<(int Status, string? Location, Exception? Escaped)> RunAsync(
        string path, Exception thrown)
    {
        var services = new ServiceCollection()
            .AddSingleton<Microsoft.Extensions.Logging.ILoggerFactory>(NullLoggerFactory.Instance)
            .BuildServiceProvider();

        var builder = new ApplicationBuilder(services);
        builder.UseSamlFailureHandling(ModulePath);
        builder.Run(_ => throw thrown);
        var pipeline = builder.Build();

        var context = new DefaultHttpContext { RequestServices = services };
        context.Request.Path = path;

        try
        {
            await pipeline(context);
        }
        catch (Exception escaped)
        {
            return (context.Response.StatusCode, null, escaped);
        }

        return (context.Response.StatusCode, context.Response.Headers.Location, null);
    }

    // -------------------------------------------------------------------------------------------
    // Refusals become an answer
    // -------------------------------------------------------------------------------------------

    public static TheoryData<Exception> Rejections => new()
    {
        // The library's own validation: bad format, bad signature, an InResponseTo that matches no
        // request this browser made.
        new Saml2ResponseFailedValidationException("refused"),

        // Replay detection lives in Microsoft's token stack, not the SAML library.
        new SecurityTokenReplayDetectedException("replayed"),

        // A SAMLResponse that is not base64 fails before either of the above sees it...
        new FormatException("not base64"),

        // ...and one that decodes but is not XML fails just after.
        new System.Xml.XmlException("not xml"),
    };

    [Theory]
    [MemberData(nameof(Rejections))]
    public async Task A_refused_assertion_redirects_to_the_page_with_an_error_marker(Exception refusal)
    {
        var (status, location, escaped) = await RunAsync($"{ModulePath}/Acs", refusal);

        Assert.Null(escaped);
        Assert.Equal(StatusCodes.Status302Found, status);
        Assert.Equal("/me?error=login", location);
    }

    [Fact]
    public async Task The_redirect_does_not_say_why_the_assertion_was_refused()
    {
        // The reason goes to the log for the operator; the caller is not owed an explanation of which
        // check failed. A distinct marker per failure would hand a probing script an oracle.
        var (_, location, _) = await RunAsync($"{ModulePath}/Acs",
            new Saml2ResponseFailedValidationException("signature did not verify"));

        Assert.DoesNotContain("signature", location, StringComparison.OrdinalIgnoreCase);
    }

    // -------------------------------------------------------------------------------------------
    // Everything else still escapes
    // -------------------------------------------------------------------------------------------

    public static TheoryData<Exception> GenuineFaults => new()
    {
        // The database being down is not a refused assertion, and a member redirected to "check your
        // login" would be debugging the wrong thing entirely.
        new InvalidOperationException("the database is unreachable"),
        new NullReferenceException("a genuine defect"),
        new TaskCanceledException("shutdown mid-request"),
    };

    [Theory]
    [MemberData(nameof(GenuineFaults))]
    public async Task A_genuine_fault_on_the_saml_path_still_propagates(Exception fault)
    {
        var (_, _, escaped) = await RunAsync($"{ModulePath}/Acs", fault);

        Assert.Same(fault, escaped);
    }

    [Fact]
    public async Task Off_the_saml_path_even_a_rejection_category_propagates()
    {
        // The scope is the module path, not the exception type. A FormatException from parsing a key
        // in the API has nothing to do with SAML, and turning it into a login redirect would bury it.
        var refusalShaped = new FormatException("not from SAML at all");

        var (_, _, escaped) = await RunAsync("/api/v1/attest", refusalShaped);

        Assert.Same(refusalShaped, escaped);
    }
}
