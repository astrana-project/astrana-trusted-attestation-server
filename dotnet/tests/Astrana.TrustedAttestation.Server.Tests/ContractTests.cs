using Astrana.TrustedAttestation.Server.Contract;
using Astrana.TrustedAttestation.Server.Security;

namespace Astrana.TrustedAttestation.Server.Tests;

public class RelationshipTypeCatalogTests
{
    private readonly RelationshipTypeCatalog _catalog = RelationshipTypeCatalog.Load();

    [Fact]
    public void Loads_the_governed_vocabulary_from_the_contract_file()
    {
        // Twenty-five values as of schema_version 1. This assertion is meant to fail when the contract
        // file changes: extending the enum is a change to the shared file and a feature release of all three
        // (decision record 14 in docs/adr), and every implementation and the definitions in
        // docs/relationship-types.md have to move together.
        Assert.Equal(25, _catalog.Ids.Count);
        Assert.Equal(1, _catalog.SchemaVersion);

        Assert.Contains("employee", _catalog.Ids);
        Assert.Contains("licensed_professional", _catalog.Ids);
    }

    [Fact]
    public void Rejects_values_outside_the_vocabulary()
    {
        Assert.False(_catalog.IsGoverned("patient"));      // deliberately excluded: normally private
        Assert.False(_catalog.IsGoverned("other"));        // there is no fallback value, ever
        Assert.False(_catalog.IsGoverned("board_member")); // a named subclass, not an atomic relationship
        Assert.False(_catalog.IsGoverned(null));
        Assert.False(_catalog.IsGoverned(""));
    }

    [Fact]
    public void Matching_is_case_sensitive()
    {
        // The identifiers are machine-readable keys, not display text. "Employee" is not "employee".
        Assert.True(_catalog.IsGoverned("employee"));
        Assert.False(_catalog.IsGoverned("Employee"));
    }

    [Fact]
    public void Labels_fall_back_from_region_to_language_to_english()
    {
        Assert.Equal("Employee", _catalog.Label("employee", "en"));
        Assert.Equal("Employee", _catalog.Label("employee", "en-GB"));

        // A locale the contract translates is returned in that language, and a region variant falls back to
        // it: fr-CA has no entry of its own, so it resolves to the fr label rather than to English.
        Assert.Equal("Employé", _catalog.Label("employee", "fr"));
        Assert.Equal("Employé", _catalog.Label("employee", "fr-CA"));

        // A locale the contract does not carry at all falls back to English rather than inventing a
        // translation. (Welsh is deliberately not in the shipped set.)
        Assert.Equal("Employee", _catalog.Label("employee", "cy"));
        Assert.Equal("Employee", _catalog.Label("employee", null));
    }

    [Fact]
    public void An_unknown_id_labels_as_itself_rather_than_throwing()
    {
        Assert.Equal("nonsense", _catalog.Label("nonsense", "en"));
    }
}

public class PublicKeyTests
{
    [Fact]
    public void Accepts_a_32_byte_key()
    {
        var bytes = Enumerable.Range(1, 32).Select(i => (byte)i).ToArray();

        Assert.True(PublicKey.TryParse(Convert.ToBase64String(bytes), out var parsed));
        Assert.Equal(bytes, parsed);
    }

    [Theory]
    [InlineData(0)]
    [InlineData(16)]
    [InlineData(31)]
    [InlineData(33)]
    [InlineData(64)]
    public void Rejects_any_length_but_32(int length)
    {
        var bytes = Enumerable.Range(1, length).Select(i => (byte)i).ToArray();

        Assert.False(PublicKey.TryParse(Convert.ToBase64String(bytes), out _));
    }

    [Theory]
    [InlineData(null)]
    [InlineData("")]
    [InlineData("   ")]
    [InlineData("not base64 at all!!")]
    [InlineData("AAAA%%%%")]
    public void Rejects_anything_that_is_not_base64(string? value)
    {
        Assert.False(PublicKey.TryParse(value, out _));
    }

    [Fact]
    public void Rejects_the_all_zero_key()
    {
        // Not a valid Ed25519 point, and the likeliest artefact of a client that "successfully" produced
        // an empty key.
        Assert.False(PublicKey.TryParse(Convert.ToBase64String(new byte[32]), out _));
    }

    [Fact]
    public void Round_trips_through_base64()
    {
        var bytes = Enumerable.Range(1, 32).Select(i => (byte)(i * 7)).ToArray();
        var encoded = PublicKey.ToBase64(bytes);

        Assert.True(PublicKey.TryParse(encoded, out var parsed));
        Assert.Equal(bytes, parsed);
    }

    [Fact]
    public void High_bytes_survive_the_round_trip()
    {
        // A guard against anything treating the key as text on the way through. 0xFF bytes are not
        // valid UTF-8, so an implementation that decoded and re-encoded as a string would mangle them
        // here and nowhere else in this file.
        var original = Enumerable.Repeat((byte)0xFF, PublicKey.Length).ToArray();

        Assert.True(PublicKey.TryParse(PublicKey.ToBase64(original), out var parsed));
        Assert.Equal(original, parsed);
    }

    [Fact]
    public void A_key_that_is_almost_all_zero_is_accepted()
    {
        // The boundary of the all-zero rule above. Rejecting anything mostly-zero would be this
        // implementation inventing a cryptographic opinion decision record 28 does not give it: beyond
        // the length and the all-zero case, any 32 bytes are the verifying peer's business.
        var almost = new byte[PublicKey.Length];
        almost[^1] = 1;

        Assert.True(PublicKey.TryParse(Convert.ToBase64String(almost), out var parsed));
        Assert.Equal(almost, parsed);
    }

    [Fact]
    public void Whitespace_alone_is_refused()
    {
        Assert.False(PublicKey.TryParse("   ", out _));
    }

    [Fact]
    public void A_refused_key_never_yields_a_usable_value()
    {
        // The out parameter is what the caller goes on to store. A refusal that left a partially filled
        // buffer there would be worse than an exception: the caller checked the return value and would
        // have no reason to look again.
        Assert.False(PublicKey.TryParse("YWJj", out var parsed));
        Assert.Empty(parsed);
    }

    [Fact]
    public void Only_the_canonical_encoding_of_a_key_is_accepted()
    {
        // One key, several encodings a decoder might tolerate. Only the padded, standard-alphabet form
        // the system itself emits is a key here -- the same rule the Java and PHP implementations
        // enforce, so a key accepted by one is accepted by all three. Convert would otherwise skip the
        // internal space and accept the unpadded form, letting a key in that the other two refuse.
        var bytes = Enumerable.Range(1, 32).Select(i => (byte)(i * 7)).ToArray();
        var canonical = Convert.ToBase64String(bytes);

        Assert.True(PublicKey.TryParse(canonical, out _));

        var unpadded = canonical.TrimEnd('=');
        var urlSafe = canonical.Replace('+', '-').Replace('/', '_');
        var internalSpace = canonical.Insert(4, " ");
        var leadingSpace = " " + canonical;

        Assert.False(PublicKey.TryParse(unpadded, out _));
        Assert.False(PublicKey.TryParse(urlSafe, out _));
        Assert.False(PublicKey.TryParse(internalSpace, out _));
        Assert.False(PublicKey.TryParse(leadingSpace, out _));
    }
}
