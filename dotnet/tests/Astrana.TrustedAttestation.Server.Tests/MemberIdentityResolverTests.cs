using System.Security.Claims;
using Astrana.TrustedAttestation.Server.Configuration;
using Astrana.TrustedAttestation.Server.Security;
using Microsoft.Extensions.Options;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// What the session is allowed to decide.
///
/// One question only: who is this? A relationship is deliberately never read from a claim. It exists
/// only because the organisation created it with grant_member_relationship, and anything read from a
/// token could be handed to a member by whoever controls the identity provider's claim mapping. Keeping
/// grant out of the API would count for little if a claim could still conjure one.
/// </summary>
public class MemberIdentityResolverTests
{
    private static MemberIdentityResolver Resolver()
    {
        var options = new TrustedAttestationOptions
        {
            Iam = new IamOptions
            {
                Authority = "https://idp.example",
                ClientId = "trusted-attestation",
            },
        };

        return new MemberIdentityResolver(Options.Create(options));
    }

    private static ClaimsPrincipal Principal(params (string Type, string Value)[] claims) =>
        new(new ClaimsIdentity(claims.Select(c => new Claim(c.Type, c.Value)), "test"));

    [Fact]
    public void Reads_the_subject_and_name_from_the_session()
    {
        Assert.True(Resolver().TryResolve(
            Principal(("sub", "abc-123"), ("name", "Alice Anderson")), out var member));

        Assert.Equal("abc-123", member.IamSubjectId);
        Assert.Equal("Alice Anderson", member.Name);
    }

    [Fact]
    public void No_subject_is_treated_as_unauthenticated()
    {
        Assert.False(Resolver().TryResolve(Principal(("name", "Alice Anderson")), out _));
    }

    [Fact]
    public void A_member_holding_nothing_still_resolves()
    {
        // Authenticated but granted nothing is an ordinary member with an empty list, not a 403, because
        // that is what everyone looks like before their first grant. Refusing them would mean a member
        // could not even see the page that tells them there is nothing yet.
        Assert.True(Resolver().TryResolve(Principal(("sub", "dave")), out var member));

        Assert.Equal("dave", member.IamSubjectId);
    }

    [Fact]
    public void A_relationship_claim_on_the_session_is_ignored_entirely()
    {
        // Whoever controls the identity provider's claim mapping must not be able to hand a member a
        // relationship the organisation never granted, and the only way to be sure of that is for the
        // resolver to have nowhere to put one.
        Assert.True(Resolver().TryResolve(
            Principal(("sub", "abc"), ("relationship_type", "director"),
                      ("relationship_subtype", "Fellow")),
            out var member));

        // The resolved member is exactly the subject and its name fallback -- the relationship claims did
        // not become the subject, and did not leak into the name (which falls back to the subject here,
        // rather than picking up "director"). A ToString() check would pass whatever the resolver did,
        // because the record has only these two fields; asserting the fields themselves is the real test.
        Assert.Equal("abc", member.IamSubjectId);
        Assert.Equal("abc", member.Name);
    }

    [Fact]
    public void Falls_back_to_the_subject_when_the_idp_sends_no_name()
    {
        Assert.True(Resolver().TryResolve(Principal(("sub", "abc-123")), out var member));

        Assert.Equal("abc-123", member.Name);
    }

    [Fact]
    public void A_multivalued_subject_is_treated_as_absent_not_coerced()
    {
        // A provider that sends sub as a JSON array surfaces as more than one "sub" claim. Picking the
        // first would key the member by an arbitrary half of an ambiguous identity; the safe reading is
        // that there is no usable subject, so the member is refused. The other two implementations agree.
        Assert.False(Resolver().TryResolve(Principal(("sub", "a"), ("sub", "b")), out _));
        Assert.False(Resolver().TryGetSubject(Principal(("sub", "a"), ("sub", "b")), out _));
    }

    [Fact]
    public void A_multivalued_name_falls_back_to_the_subject()
    {
        Assert.True(Resolver().TryResolve(
            Principal(("sub", "abc"), ("name", "Ann"), ("name", "Bob")), out var member));

        Assert.Equal("abc", member.Name);
    }

    [Fact]
    public void A_blank_name_falls_back_to_the_subject()
    {
        // A present-but-empty name claim is not a name. Showing the member a blank where their name
        // should be is worse than showing the subject the page already falls back to when there is none.
        Assert.True(Resolver().TryResolve(Principal(("sub", "abc"), ("name", "   ")), out var member));

        Assert.Equal("abc", member.Name);
    }

    // ------------------------------------------------------------------------------------------------
    // SAML. The same resolver, the same configured claim names, a different protocol underneath.
    // A SAML handler surfaces the assertion's NameID as the NameIdentifier claim, so the resolver sees
    // one shape regardless of protocol.
    // ------------------------------------------------------------------------------------------------

    [Fact]
    public void The_subject_falls_back_to_the_name_id_when_no_claim_carries_it()
    {
        // NameID is SAML's equivalent of "sub", and is where the subject normally lives. It also has to
        // be the same value the organisation granted against, or the member signs in successfully and
        // appears to hold nothing at all -- a failure with no error anywhere.
        Assert.True(Resolver().TryResolve(
            Principal((ClaimTypes.NameIdentifier, "persistent-name-id")), out var member));

        Assert.Equal("persistent-name-id", member.IamSubjectId);
    }

    [Fact]
    public void A_claim_named_as_the_subject_claim_wins_over_the_name_id()
    {
        Assert.True(Resolver().TryResolve(
            Principal(("sub", "claim-subject"), (ClaimTypes.NameIdentifier, "persistent-name-id")),
            out var member));

        Assert.Equal("claim-subject", member.IamSubjectId);
    }

    [Fact]
    public void The_subject_alone_is_readable_without_a_display_name()
    {
        // What DELETE and self-revoke rely on: neither needs to show the member anything, so neither
        // should fail because the IdP sent no name claim.
        Assert.True(Resolver().TryGetSubject(
            Principal((ClaimTypes.NameIdentifier, "persistent-name-id")), out var subject));

        Assert.Equal("persistent-name-id", subject);
    }

    [Fact]
    public void No_subject_means_no_subject_for_the_lighter_path_too()
    {
        Assert.False(Resolver().TryGetSubject(Principal(("name", "Alice Anderson")), out _));
    }
}
