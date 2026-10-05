using System.Security.Claims;
using Astrana.TrustedAttestation.Server.Configuration;
using Microsoft.Extensions.Options;

namespace Astrana.TrustedAttestation.Server.Security;

/// <summary>
/// A member as the organisation's IAM system describes them, for the duration of one request.
///
/// Only <see cref="IamSubjectId"/> is ever stored, and only as the key the relationship rows hang from.
/// <see cref="Name"/> exists solely so the self-service page can show the member who they are logged in
/// as, and is never written anywhere.
/// </summary>
/// <param name="IamSubjectId">
/// The organisation's own IAM identifier. Never leaves Astrana Trusted Attestation.
/// </param>
/// <param name="Name">Display name from the IAM session. Never stored.</param>
public sealed record MemberIdentity(string IamSubjectId, string Name);

/// <summary>
/// Resolves the authenticated session into a member, and nothing more than that.
///
/// It deliberately does not resolve a relationship type from a claim. A relationship exists only because
/// the organisation created it with <c>grant_member_relationship</c>, and the database is the only place
/// that records one. Reading a relationship from a token would let whoever controls the identity system's
/// claim mapping hand a member a relationship the organisation never granted. That is the hole that
/// keeping grant out of the API closes, reopened one layer down, where it would be much harder to notice.
///
/// So the session answers exactly one question here: who is this? What they hold is a database lookup.
/// </summary>
public sealed class MemberIdentityResolver(IOptions<TrustedAttestationOptions> options)
{
    private readonly IamOptions _iam = options.Value.Iam;

    /// <summary>
    /// The authenticated member, or false when the session carries no usable subject.
    ///
    /// There is no second failure mode. "Authenticated but holding nothing" is not a failure at
    /// all: it is an ordinary member before their first grant, and they get a 200 with an empty list.
    /// </summary>
    public bool TryResolve(ClaimsPrincipal principal, out MemberIdentity member)
    {
        member = null!;

        var subject = ResolveSubject(principal);
        if (string.IsNullOrWhiteSpace(subject))
        {
            return false;
        }

        var name = SingleValue(principal, _iam.NameClaim) ?? subject;

        member = new MemberIdentity(subject, name);
        return true;
    }

    /// <summary>
    /// The subject alone, for the operations that do not need a display name.
    /// </summary>
    public bool TryGetSubject(ClaimsPrincipal principal, out string subject)
    {
        subject = ResolveSubject(principal) ?? string.Empty;

        return !string.IsNullOrWhiteSpace(subject);
    }

    /// <summary>
    /// The member's subject identifier.
    ///
    /// Under SAML this falls back to the assertion's NameID when no claim of the configured name is
    /// present, which is the usual case: NameID is SAML's equivalent of OIDC's <c>sub</c>, and the
    /// subject normally lives there rather than in an attribute of its own.
    ///
    /// This is the value the organisation granted against, so getting it wrong does not produce an
    /// error -- it produces a member who signs in successfully and appears to hold nothing.
    /// </summary>
    private string? ResolveSubject(ClaimsPrincipal principal) => ResolveSubject(principal, _iam);

    /// <summary>
    /// The same resolution, for the sign-in handlers, which run before there is a request scope to resolve
    /// this class from and must refuse a sign-in that would produce a session nothing can act for.
    /// </summary>
    internal static string? ResolveSubject(ClaimsPrincipal principal, IamOptions iam)
    {
        return SingleValue(principal, iam.SubjectClaim)
            ?? SingleValue(principal, ClaimTypes.NameIdentifier);
    }

    /// <summary>
    /// A claim's single value, or <c>null</c> when the claim is absent, blank, or carries more than one
    /// value.
    ///
    /// A multivalued claim, which is what a subject or name arrives as when the provider sends a JSON
    /// array, is a shape Astrana Trusted Attestation does not expect. Rather than pick the first (keying
    /// the member by an arbitrary half of an ambiguous identity, or showing one of two names), it is
    /// treated as absent. The subject then resolves to none and the member is refused, and the name falls
    /// back to the subject. That is the safe reading the other two implementations take.
    /// </summary>
    private static string? SingleValue(ClaimsPrincipal principal, string claimType)
    {
        string? value = null;
        foreach (var claim in principal.FindAll(claimType))
        {
            if (value is not null)
            {
                return null;
            }

            value = claim.Value;
        }

        return string.IsNullOrWhiteSpace(value) ? null : value;
    }
}
