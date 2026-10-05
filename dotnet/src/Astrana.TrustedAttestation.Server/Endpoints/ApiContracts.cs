using System.Text.Json;
using System.Text.Json.Serialization;
using Astrana.TrustedAttestation.Server.Contract;
using Astrana.TrustedAttestation.Server.Data;

namespace Astrana.TrustedAttestation.Server.Endpoints;

/// <summary>
/// One relationship the member holds, with its own key and its own standing.
/// </summary>
public sealed record RelationshipWithKey
{
    [JsonPropertyName("relationship_type")]
    public required string RelationshipType { get; init; }

    [JsonPropertyName("relationship_subtype")]
    public string? RelationshipSubtype { get; init; }

    /// <summary><c>unkeyed</c>, <c>active</c>, <c>revoked</c> or <c>expired</c>.</summary>
    [JsonPropertyName("status")]
    public required string Status { get; init; }

    /// <summary>
    /// This relationship's own key, base64-encoded, or null when none is registered yet. Never shared
    /// with any other relationship the member holds.
    /// </summary>
    [JsonPropertyName("public_key")]
    public string? PublicKey { get; init; }

    /// <summary>ISO 8601 UTC with a trailing <c>Z</c> (see <see cref="WireTimestamp"/>), or null when the relationship does not expire.</summary>
    [JsonPropertyName("expires_at")]
    public string? ExpiresAt { get; init; }

    public static RelationshipWithKey From(MemberRelationship row, DateTime utcNow) => new()
    {
        RelationshipType = row.RelationshipType,
        RelationshipSubtype = row.RelationshipSubtype,
        Status = row.StatusAt(utcNow).ToWireValue(),
        PublicKey = row.PublicKey is null ? null : Security.PublicKey.ToBase64(row.PublicKey),
        ExpiresAt = WireTimestamp.Format(row.ExpiresAt),
    };
}

/// <summary>
/// Request and response shapes, matching <c>contract/openapi.yaml</c> exactly. A verifying peer cannot
/// tell which of the three stacks it is talking to, so these must not drift.
/// </summary>
public sealed record MeResponse
{
    /// <summary>From the organisation's IAM session. Astrana Trusted Attestation does not store this.</summary>
    [JsonPropertyName("name")]
    public required string Name { get; init; }

    /// <summary>
    /// Every relationship the member holds here, each with its own key status. Always an array, even
    /// when it holds one entry and even when it holds none: a member the organisation has granted
    /// nothing is an ordinary state rather than an error, and the page still has to render for them.
    /// </summary>
    [JsonPropertyName("relationships")]
    public required IReadOnlyList<RelationshipWithKey> Relationships { get; init; }
}

public sealed record SetKeyRequest
{
    [JsonPropertyName("public_key")]
    public string? PublicKey { get; init; }

    /// <summary>
    /// Read leniently, for the same reason as <see cref="AttestRequest"/>: a public_key that is a number,
    /// an object or an array must reach the handler as "no usable key" and be refused there with a clean
    /// 400, not rejected by model binding with a framework exception in the body. Errors on this contract
    /// are status-code-only; a leaked binding failure would break that, and only in the environment that
    /// happens to have detailed errors switched on.
    /// </summary>
    public static async ValueTask<SetKeyRequest?> BindAsync(HttpContext context) =>
        new SetKeyRequest { PublicKey = await PublicKeyBody.ReadAsync(context) };
}

/// <summary>
/// Reads a request body's <c>public_key</c> as a string, or nothing. Shared by the two anonymous- and
/// member-facing bodies that both carry only that one field and both must treat a wrong-typed or
/// unparseable body as an absent key rather than as a reason to fail before the handler runs.
///
/// <para>The body is read whatever its Content-Type says, and it is malformed, so read as carrying no key,
/// when it starts with a byte order mark, when anything follows the JSON value, or when it is not valid
/// UTF-8, inside the string or outside it. The three implementations agree on each of those, so the same
/// body is a 400 on the key endpoint and <c>{"valid":false}</c> on attestation whichever stack receives it.
/// Above <see cref="MaxBytes"/> the body is refused outright with HTTP 413 and no body.</para>
/// </summary>
internal static class PublicKeyBody
{
    /// <summary>
    /// The most an API body may hold: 64 kilobytes, the same cap in all three implementations. A valid body
    /// is under a hundred bytes, so the cap bounds what an anonymous caller can make the server buffer
    /// without ever touching a real request.
    /// </summary>
    public const int MaxBytes = 64 * 1024;

    private static readonly byte[] ByteOrderMark = [0xEF, 0xBB, 0xBF];

    public static async ValueTask<string?> ReadAsync(HttpContext context)
    {
        var body = await ReadCappedAsync(context.Request.Body, context.RequestAborted);

        // The stream parser would skip a byte order mark silently. JSON text (RFC 8259, section 8.1) must
        // not begin with one, and the other two implementations refuse it, so it is checked by hand.
        if (body.AsSpan().StartsWith(ByteOrderMark))
        {
            return null;
        }

        try
        {
            using var document = JsonDocument.Parse(body);
            if (document.RootElement.ValueKind == JsonValueKind.Object
                && document.RootElement.TryGetProperty("public_key", out var value)
                && value.ValueKind == JsonValueKind.String)
            {
                return value.GetString();
            }
        }
        catch (JsonException)
        {
            // Not JSON, invalid UTF-8 outside a string, or something after the value: no key.
        }
        catch (InvalidOperationException)
        {
            // Invalid UTF-8 inside the string. The reader validates the string's bytes only when it
            // decodes them, and reports it this way rather than as a JsonException.
        }

        return null;
    }

    /// <summary>
    /// The whole body, or a refusal once it passes the cap. Kestrel enforces the same limit through the
    /// endpoints' request-size metadata, so the refusal below is reached only on a server that does not.
    /// </summary>
    private static async Task<byte[]> ReadCappedAsync(Stream stream, CancellationToken cancellationToken)
    {
        using var buffer = new MemoryStream();
        var chunk = new byte[8192];
        int read;

        while ((read = await stream.ReadAsync(chunk, cancellationToken)) > 0)
        {
            if (buffer.Length + read > MaxBytes)
            {
                throw new BadHttpRequestException(
                    $"The request body exceeds {MaxBytes} bytes.", StatusCodes.Status413PayloadTooLarge);
            }

            buffer.Write(chunk, 0, read);
        }

        return buffer.ToArray();
    }
}

/// <summary>
/// The request-size metadata on every API endpoint. Routing hands it to Kestrel when the endpoint is
/// matched, so a body over the cap is cut off at the server rather than buffered first.
/// </summary>
internal sealed class ApiBodyLimit : Microsoft.AspNetCore.Http.Metadata.IRequestSizeLimitMetadata
{
    public static readonly ApiBodyLimit Instance = new();

    public long? MaxRequestBodySize => PublicKeyBody.MaxBytes;
}

public sealed record SetKeyResponse
{
    /// <summary>
    /// The key now on record, base64-encoded, or null when the member cleared it. Registering a key echoes
    /// it back; clearing one returns null, so the page can show the field empty and the standing back at
    /// "waiting for your key".
    /// </summary>
    [JsonPropertyName("public_key")]
    public required string? PublicKey { get; init; }

    [JsonPropertyName("relationship_type")]
    public required string RelationshipType { get; init; }

    [JsonPropertyName("relationship_subtype")]
    public string? RelationshipSubtype { get; init; }

    /// <summary>
    /// The relationship's standing after the key was set.
    ///
    /// Present because setting a key never clears an organisation's revocation. A member who registers a
    /// key on a revoked relationship gets a 200 -- the key really was stored -- and would otherwise have
    /// every reason to believe they had restored themselves. This is what tells them they have not.
    /// </summary>
    [JsonPropertyName("status")]
    public required string Status { get; init; }
}

public sealed record AttestRequest
{
    [JsonPropertyName("public_key")]
    public string? PublicKey { get; init; }

    /// <summary>
    /// Bound by hand rather than by the framework's JSON model binding, because /attest must answer 200
    /// whatever arrives -- valid or not is the only distinction it draws, and a malformed body is simply
    /// not valid. Default binding would reject a public_key that is a number, an object or an array with
    /// a 400 before the handler ever runs, and inconsistently at that: a boolean coerces to a string
    /// where an object does not. Reading the field leniently -- a JSON string or nothing -- keeps that
    /// decision inside the handler, where "not a string" means the same as "unknown key".
    /// </summary>
    public static async ValueTask<AttestRequest?> BindAsync(HttpContext context) =>
        new AttestRequest { PublicKey = await PublicKeyBody.ReadAsync(context) };
}

/// <summary>
/// The answer to "is this key on record here, and what is its standing?".
///
/// A key that was never registered gets exactly <c>{ "valid": false }</c> and nothing else, so an
/// anonymous caller fishing for keys learns nothing at all. A caller holding a real key is in a
/// different position: they can only have it because the member presented it to them, so telling them
/// the relationship has been revoked is the point of their asking rather than a leak.
///
/// Astrana's own three outcomes -- valid, invalid, unreachable -- are derived from both fields together.
/// Any status other than <c>active</c> is invalid to the peer, even though the lookup itself succeeded.
/// </summary>
public sealed record AttestResponse
{
    [JsonPropertyName("valid")]
    public required bool Valid { get; init; }

    [JsonPropertyName("relationship_type")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public string? RelationshipType { get; init; }

    [JsonPropertyName("relationship_subtype")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public string? RelationshipSubtype { get; init; }

    [JsonPropertyName("status")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public string? Status { get; init; }

    public static readonly AttestResponse Invalid = new() { Valid = false };

    public static AttestResponse For(MemberRelationship row, DateTime utcNow) => new()
    {
        Valid = true,
        RelationshipType = row.RelationshipType,
        RelationshipSubtype = row.RelationshipSubtype,
        Status = row.StatusAt(utcNow).ToWireValue(),
    };
}
