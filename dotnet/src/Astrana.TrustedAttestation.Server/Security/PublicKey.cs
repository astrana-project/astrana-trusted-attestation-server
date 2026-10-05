using System.Buffers.Text;

namespace Astrana.TrustedAttestation.Server.Security;

/// <summary>
/// Parsing and formatting for Ed25519 public keys.
///
/// Stored and compared as 32 raw bytes, and base64 only ever appears at the API boundary, because binary
/// is a smaller index than base64 text and keeps more of it in memory at scale (decision records 28 and 29 in
/// docs/adr).
/// </summary>
public static class PublicKey
{
    /// <summary>Ed25519 public keys are exactly 32 bytes (RFC 8032).</summary>
    public const int Length = 32;

    // The only characters a blank key may hold. Any other whitespace, a non-breaking space or a form feed
    // among them, makes the key malformed rather than blank, the same test in all three implementations.
    private const string BlankCharacters = " \t\r\n";

    /// <summary>
    /// Whether a submitted key is blank, which clears the key: empty, or nothing but spaces, tabs,
    /// carriage returns and line feeds.
    /// </summary>
    public static bool IsBlank(string value) => !value.AsSpan().ContainsAnyExcept(BlankCharacters);

    public static bool TryParse(string? base64, out byte[] key)
    {
        key = [];

        if (string.IsNullOrWhiteSpace(base64))
        {
            return false;
        }

        Span<byte> buffer = stackalloc byte[Length];
        if (!Convert.TryFromBase64String(base64, buffer, out var written) || written != Length)
        {
            return false;
        }

        // Canonical form only: re-encoding has to reproduce the input exactly. Convert quietly skips
        // whitespace and the decoders disagree on unpadded input and on non-canonical trailing bits --
        // one accepts what another rejects. Requiring a round-trip pins all three to the single padded,
        // standard-alphabet encoding the system itself emits, so a key that is valid here is valid
        // everywhere. See PublicKeys in the Java and PHP implementations, which do the same.
        if (!string.Equals(Convert.ToBase64String(buffer), base64, StringComparison.Ordinal))
        {
            return false;
        }

        // The all-zero key is not a valid Ed25519 point, and is the likeliest artefact of a client bug
        // that "successfully" produced an empty key. Beyond this, no point validation is done. Any other
        // 32-byte string is syntactically well-formed, and Astrana Trusted Attestation never verifies
        // signatures itself, because the verifying Astrana instance does that. A key that is on record but
        // cryptographically useless harms only the member who registered it.
        if (!buffer.ContainsAnyExcept((byte)0))
        {
            return false;
        }

        key = buffer.ToArray();
        return true;
    }

    public static string ToBase64(byte[] key) => Convert.ToBase64String(key);
}
