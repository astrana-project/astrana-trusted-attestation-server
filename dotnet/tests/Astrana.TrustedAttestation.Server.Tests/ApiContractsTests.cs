using System.Text.Json;
using Astrana.TrustedAttestation.Server.Data;
using Astrana.TrustedAttestation.Server.Endpoints;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// How a database row becomes what goes on the wire.
///
/// The mapping functions decide two things the contract cares about deeply: which fields appear at all,
/// and what the status says. A verifying peer cannot tell which of the three stacks it is talking to, so
/// a field that serialises differently here is a divergence every peer sees.
///
/// Serialisation is asserted as JSON text, not as object equality, because the contract's rules are
/// about the wire: "when valid is false the response is exactly {"valid": false}" is a claim about which
/// keys exist, and object comparison cannot see the difference between a null property and an absent
/// one.
/// </summary>
public class ApiContractsTests
{
    private static readonly DateTime Now = new(2026, 8, 27, 12, 0, 0, DateTimeKind.Utc);

    private static readonly JsonSerializerOptions Options = new(JsonSerializerDefaults.Web);

    private static MemberRelationship Row(
        byte[]? key = null, string? subtype = null, DateTime? revokedAt = null) => new()
        {
            IamSubjectId = "abc-123",
            PublicKey = key,
            RelationshipType = "employee",
            RelationshipSubtype = subtype,
            RevokedAt = revokedAt,
        };

    private static byte[] SomeKey => Enumerable.Repeat((byte)7, 32).ToArray();

    // -------------------------------------------------------------------------------------------
    // The attest response
    // -------------------------------------------------------------------------------------------

    [Fact]
    public void The_invalid_answer_is_exactly_valid_false_and_nothing_else()
    {
        // The contract's strongest wire rule. Any extra key -- even a null one -- gives a caller probing
        // unregistered keys something to distinguish responses by.
        Assert.Equal("""{"valid":false}""", JsonSerializer.Serialize(AttestResponse.Invalid, Options));
    }

    [Fact]
    public void A_relationship_on_record_reports_its_type_and_standing()
    {
        var json = JsonSerializer.Serialize(AttestResponse.For(Row(SomeKey), Now), Options);

        Assert.Equal("""{"valid":true,"relationship_type":"employee","status":"active"}""", json);
    }

    [Fact]
    public void A_null_subtype_is_absent_from_the_wire_not_null_on_it()
    {
        var json = JsonSerializer.Serialize(AttestResponse.For(Row(SomeKey), Now), Options);

        Assert.DoesNotContain("relationship_subtype", json);
    }

    [Fact]
    public void A_subtype_that_exists_is_carried()
    {
        var json = JsonSerializer.Serialize(AttestResponse.For(Row(SomeKey, "Fellow"), Now), Options);

        Assert.Contains("""'relationship_subtype':'Fellow'""".Replace('\'', '"'), json);
    }

    [Fact]
    public void A_revoked_relationship_reports_revoked_while_valid_stays_true()
    {
        // valid means "on record"; status carries the standing. A caller holding a real key received it
        // from the member, and being told it is revoked is what they came to find out. Collapsing this
        // to valid:false would make revocation indistinguishable from never-registered.
        var response = AttestResponse.For(Row(SomeKey, revokedAt: Now.AddDays(-1)), Now);

        Assert.True(response.Valid);
        Assert.Equal("revoked", response.Status);
    }

    // -------------------------------------------------------------------------------------------
    // The relationship listing
    // -------------------------------------------------------------------------------------------

    [Fact]
    public void An_unkeyed_relationship_lists_with_no_key_and_the_unkeyed_status()
    {
        var entry = RelationshipWithKey.From(Row(), Now);

        Assert.Equal("unkeyed", entry.Status);
        Assert.Null(entry.PublicKey);
    }

    [Fact]
    public void A_keyed_relationship_lists_its_own_key_in_base64()
    {
        var entry = RelationshipWithKey.From(Row(SomeKey), Now);

        Assert.Equal(Convert.ToBase64String(SomeKey), entry.PublicKey);
        Assert.Equal("active", entry.Status);
    }

    [Fact]
    public void The_wire_names_are_snake_case_whatever_the_serializer_defaults_to()
    {
        // The property names are pinned with attributes rather than left to a serializer policy,
        // because the contract spells them snake_case and a host swapping the global JSON options must
        // not be able to rename fields every peer depends on.
        var json = JsonSerializer.Serialize(RelationshipWithKey.From(Row(SomeKey, "Fellow"), Now));

        Assert.Contains("relationship_type", json);
        Assert.Contains("relationship_subtype", json);
        Assert.Contains("public_key", json);
        Assert.DoesNotContain("relationshipType", json);
    }

    [Theory]
    [InlineData(DateTimeKind.Utc)]
    [InlineData(DateTimeKind.Unspecified)]
    public void An_expiry_is_iso_8601_utc_with_a_trailing_z_whatever_kind_the_driver_stamped(DateTimeKind kind)
    {
        // PostgreSQL hands the instant back as Utc, SQL Server and MySQL as Unspecified. The wire must not
        // tell them apart, and the other two implementations always write the Z.
        var row = Row(SomeKey);
        row.ExpiresAt = new DateTime(2027, 3, 1, 9, 30, 0, kind);

        var json = JsonSerializer.Serialize(RelationshipWithKey.From(row, Now), Options);

        Assert.Contains("""'expires_at':'2027-03-01T09:30:00Z'""".Replace('\'', '"'), json);
    }

    [Fact]
    public void An_expiry_with_a_fraction_carries_it_trimmed_of_trailing_zeros()
    {
        var row = Row(SomeKey);
        row.ExpiresAt = new DateTime(2027, 3, 1, 9, 30, 0, DateTimeKind.Utc).AddMilliseconds(120);

        var json = JsonSerializer.Serialize(RelationshipWithKey.From(row, Now), Options);

        Assert.Contains("""'expires_at':'2027-03-01T09:30:00.12Z'""".Replace('\'', '"'), json);
    }

    [Fact]
    public void A_relationship_without_an_expiry_carries_a_null_expires_at()
    {
        var json = JsonSerializer.Serialize(RelationshipWithKey.From(Row(SomeKey), Now), Options);

        Assert.Contains("""'expires_at':null""".Replace('\'', '"'), json);
    }

    [Fact]
    public void The_me_response_serialises_an_empty_list_as_a_list()
    {
        // A member the organisation has granted nothing gets an empty array, not null and not a missing
        // key: the page's script iterates this directly.
        var json = JsonSerializer.Serialize(
            new MeResponse { Name = "Dave", Relationships = [] }, Options);

        Assert.Contains("""'relationships':[]""".Replace('\'', '"'), json);
    }

    // Note: the "setting a key on a revoked relationship returns status 'revoked', not restored" behaviour
    // is exercised where it actually lives -- the SetKey handler -- in SetKeyHandlerTests, not by
    // re-implementing the mapping against a hand-built DTO here.
}
