using System.Net;
using Astrana.TrustedAttestation.Server.Configuration;
using Sustainsys.Saml2.WebSso;

namespace Astrana.TrustedAttestation.Server.Security;

/// <summary>
/// Where a failed sign-in lands, and the one refusal both protocols share.
///
/// <para>A provider error at the callback, a state that matches no request this browser made, an assertion
/// that fails validation, and a token or assertion that carries no usable subject all end the same way: no
/// session is established and the member is sent to the self-service page carrying an error marker, which
/// is where the PHP implementation's AuthController lands them. One marker for every cause, because the
/// reason goes to the log for the operator, and a caller is not owed an explanation of which check
/// failed.</para>
///
/// <para>The subject is checked at sign-in rather than left to the first request, because a session with
/// no usable subject is a session nothing can act for: every page and every API call would refuse it, and
/// the member would be signed in and locked out at once. Refusing the sign-in says so at the moment it
/// happens.</para>
/// </summary>
public static class SignInFailure
{
    /// <summary>The page, with enough for it to say the sign-in failed. Identical in all three implementations.</summary>
    public const string Path = "/me?error=login";

    /// <summary>
    /// The hook on the SAML assertion consumer: when the validated assertion names no usable subject, the
    /// command result that would have signed the member in is turned into the failure redirect instead. The
    /// library signs in only when the result carries a principal, so clearing it is what withholds the
    /// session.
    /// </summary>
    public static void RefuseAssertionWithoutSubject(CommandResult result, IamOptions iam)
    {
        if (result.Principal is null || MemberIdentityResolver.ResolveSubject(result.Principal, iam) is not null)
        {
            return;
        }

        result.Principal = null;
        result.HttpStatusCode = HttpStatusCode.SeeOther;
        result.Location = new Uri(Path, UriKind.Relative);
    }
}
