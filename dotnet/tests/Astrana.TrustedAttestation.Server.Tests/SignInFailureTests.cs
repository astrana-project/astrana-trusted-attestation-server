using System.Net;
using System.Security.Claims;
using Astrana.TrustedAttestation.Server.Configuration;
using Astrana.TrustedAttestation.Server.Security;
using Sustainsys.Saml2.WebSso;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The SAML half of "a sign-in with no usable subject is refused". The OpenID Connect half is in
/// <see cref="OidcRelyingPartyTests"/>. Here the hook is the library's command result for the assertion
/// consumer: the library signs in only when the result carries a principal, so clearing it and pointing the
/// result at the failure page is what withholds the session.
/// </summary>
public class SignInFailureTests
{
    private static CommandResult SignInResultFor(params Claim[] claims) => new()
    {
        Principal = new ClaimsPrincipal(new ClaimsIdentity(claims, "saml")),
        HttpStatusCode = HttpStatusCode.SeeOther,
        Location = new Uri("/me", UriKind.Relative),
    };

    [Fact]
    public void An_assertion_with_a_name_id_signs_in_as_before()
    {
        var result = SignInResultFor(new Claim(ClaimTypes.NameIdentifier, "alice"));

        SignInFailure.RefuseAssertionWithoutSubject(result, new IamOptions());

        Assert.NotNull(result.Principal);
        Assert.Equal("/me", result.Location!.OriginalString);
    }

    [Fact]
    public void An_assertion_with_the_configured_attribute_signs_in_as_before()
    {
        var result = SignInResultFor(new Claim("employee_number", "E1234"));

        SignInFailure.RefuseAssertionWithoutSubject(result, new IamOptions { SubjectClaim = "employee_number" });

        Assert.NotNull(result.Principal);
    }

    public static TheoryData<Claim[]> NoUsableSubject => new()
    {
        Array.Empty<Claim>(),
        new[] { new Claim(ClaimTypes.NameIdentifier, "") },
        new[] { new Claim(ClaimTypes.NameIdentifier, "  ") },
        new[] { new Claim(ClaimTypes.NameIdentifier, "a"), new Claim(ClaimTypes.NameIdentifier, "b") },
        new[] { new Claim("department", "Finance") },
    };

    [Theory]
    [MemberData(nameof(NoUsableSubject))]
    public void An_assertion_with_no_usable_subject_is_refused_with_no_session(Claim[] claims)
    {
        var result = SignInResultFor(claims);

        SignInFailure.RefuseAssertionWithoutSubject(result, new IamOptions());

        Assert.Null(result.Principal);
        Assert.Equal(HttpStatusCode.SeeOther, result.HttpStatusCode);
        Assert.Equal(SignInFailure.Path, result.Location!.OriginalString);
    }

    [Fact]
    public void A_result_that_was_not_a_sign_in_is_left_alone()
    {
        // A refused assertion never produces a principal in the first place, and the failure middleware
        // answers it. There is nothing here to refuse.
        var result = new CommandResult { HttpStatusCode = HttpStatusCode.OK };

        SignInFailure.RefuseAssertionWithoutSubject(result, new IamOptions());

        Assert.Equal(HttpStatusCode.OK, result.HttpStatusCode);
        Assert.Null(result.Location);
    }

    [Fact]
    public void Both_protocols_land_on_the_same_page_as_the_php_implementation()
    {
        Assert.Equal("/me?error=login", SignInFailure.Path);
    }
}
