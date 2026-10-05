using Astrana.TrustedAttestation.Server.Data;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// What a relationship's standing is at a given moment.
///
/// Point-in-time and re-evaluated on every check, never a proof established once and assumed forever
/// (decision record 4 in docs/adr, and the README, How it works). The status is computed from the row rather than
/// stored, so there is no second copy of the truth to fall out of step with the timestamps it was derived from.
/// </summary>
public class MemberRelationshipTests
{
    private static readonly DateTime Now = new(2026, 8, 26, 12, 0, 0, DateTimeKind.Utc);

    private static MemberRelationship Row(
        byte[]? publicKey = null,
        DateTime? expiresAt = null,
        DateTime? revokedAt = null) => new()
        {
            IamSubjectId = "abc-123",
            PublicKey = publicKey,
            RelationshipType = "employee",
            ExpiresAt = expiresAt,
            RevokedAt = revokedAt,
        };

    private static byte[] SomeKey => Enumerable.Repeat((byte)7, 32).ToArray();

    // -------------------------------------------------------------------------------------------
    // The ordinary states
    // -------------------------------------------------------------------------------------------

    [Fact]
    public void A_keyed_relationship_with_no_expiry_and_no_revocation_is_active()
    {
        Assert.Equal(RelationshipStatus.Active, Row(SomeKey).StatusAt(Now));
    }

    [Fact]
    public void A_granted_relationship_the_member_has_not_keyed_yet_is_unkeyed()
    {
        // The normal state immediately after grant_member_relationship, and the reason public_key is
        // nullable at all: the organisation grants the relationship before the member has ever logged in.
        Assert.Equal(RelationshipStatus.Unkeyed, Row(publicKey: null).StatusAt(Now));
    }

    [Fact]
    public void A_revoked_relationship_is_revoked()
    {
        Assert.Equal(RelationshipStatus.Revoked, Row(SomeKey, revokedAt: Now.AddDays(-1)).StatusAt(Now));
    }

    [Fact]
    public void A_lapsed_relationship_is_expired()
    {
        Assert.Equal(RelationshipStatus.Expired, Row(SomeKey, expiresAt: Now.AddSeconds(-1)).StatusAt(Now));
    }

    [Fact]
    public void A_relationship_expiring_in_the_future_is_still_active()
    {
        Assert.Equal(RelationshipStatus.Active, Row(SomeKey, expiresAt: Now.AddDays(1)).StatusAt(Now));
    }

    [Fact]
    public void Expiry_is_exclusive_at_the_boundary()
    {
        Assert.Equal(RelationshipStatus.Expired, Row(SomeKey, expiresAt: Now).StatusAt(Now));
    }

    // -------------------------------------------------------------------------------------------
    // Where two states could both apply
    // -------------------------------------------------------------------------------------------

    [Fact]
    public void Revocation_beats_a_future_expiry()
    {
        Assert.Equal(
            RelationshipStatus.Revoked,
            Row(SomeKey, expiresAt: Now.AddYears(1), revokedAt: Now.AddDays(-1)).StatusAt(Now));
    }

    [Fact]
    public void Revocation_beats_a_past_expiry_too()
    {
        // Both are true of the row. Revocation is reported because it is the deliberate act: someone
        // decided this relationship should end, where expiry is only the absence of anyone renewing it.
        Assert.Equal(
            RelationshipStatus.Revoked,
            Row(SomeKey, expiresAt: Now.AddYears(-1), revokedAt: Now.AddDays(-1)).StatusAt(Now));
    }

    [Fact]
    public void A_revoked_relationship_reports_revoked_even_with_no_key_set()
    {
        // An organisation can revoke a relationship the member never got around to keying. Reporting
        // "unkeyed" here would invite them to set a key that would not restore anything.
        Assert.Equal(
            RelationshipStatus.Revoked,
            Row(publicKey: null, revokedAt: Now.AddDays(-1)).StatusAt(Now));
    }

    [Fact]
    public void An_unkeyed_relationship_whose_grant_has_lapsed_reports_expired()
    {
        Assert.Equal(
            RelationshipStatus.Expired,
            Row(publicKey: null, expiresAt: Now.AddDays(-1)).StatusAt(Now));
    }

    // -------------------------------------------------------------------------------------------
    // What a verifying peer is told
    // -------------------------------------------------------------------------------------------

    [Fact]
    public void Only_an_active_relationship_counts_as_currently_valid()
    {
        Assert.True(Row(SomeKey).IsValidAt(Now));
        Assert.False(Row(SomeKey, revokedAt: Now.AddDays(-1)).IsValidAt(Now));
        Assert.False(Row(SomeKey, expiresAt: Now.AddDays(-1)).IsValidAt(Now));
        Assert.False(Row(publicKey: null).IsValidAt(Now));
    }

    [Fact]
    public void The_wire_names_are_the_ones_the_contract_uses()
    {
        // These strings are the contract's enum, not a display concern. A rename here is a breaking
        // change for every verifying peer, so it is asserted rather than left to the enum's spelling.
        Assert.Equal("unkeyed", RelationshipStatus.Unkeyed.ToWireValue());
        Assert.Equal("active", RelationshipStatus.Active.ToWireValue());
        Assert.Equal("revoked", RelationshipStatus.Revoked.ToWireValue());
        Assert.Equal("expired", RelationshipStatus.Expired.ToWireValue());
    }
}
