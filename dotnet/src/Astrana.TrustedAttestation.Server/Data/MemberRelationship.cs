namespace Astrana.TrustedAttestation.Server.Data;

/// <summary>
/// What a relationship's standing is, as the contract spells it on the wire.
/// </summary>
public enum RelationshipStatus
{
    /// <summary>
    /// Granted by the organisation, but the member has not set a key for it yet.
    ///
    /// The normal state immediately after a grant, and the reason
    /// <see cref="MemberRelationship.PublicKey"/> is nullable at all. Never appears in an attest
    /// response: a key has to exist before anyone can ask about one.
    /// </summary>
    Unkeyed,

    Active,
    Revoked,
    Expired,
}

public static class RelationshipStatusExtensions
{
    /// <summary>
    /// The contract's enum values. Lower-cased deliberately rather than by convention: these strings go
    /// to every verifying peer, so changing one is a breaking change, not a rename.
    /// </summary>
    public static string ToWireValue(this RelationshipStatus status) => status switch
    {
        RelationshipStatus.Unkeyed => "unkeyed",
        RelationshipStatus.Active => "active",
        RelationshipStatus.Revoked => "revoked",
        RelationshipStatus.Expired => "expired",
        _ => throw new ArgumentOutOfRangeException(nameof(status), status, null),
    };
}

/// <summary>
/// One row per relationship, not per member. A member can hold several at the same organisation --
/// employee and client at once, say -- and each carries its own independent key.
///
/// That independence is the point rather than a convenience: presenting one relationship's key reveals
/// nothing about any other the member holds, because there is nothing shared between the rows to reveal
/// (decision record 3 in docs/adr). A single key per member would have made every relationship
/// presentable by anyone who had seen any one of them.
///
/// Deliberately holds no names and no PII beyond what the organisation's own IAM system already has.
/// </summary>
public sealed class MemberRelationship
{
    public long Id { get; set; }

    /// <summary>
    /// The organisation's own IAM identifier (OIDC <c>sub</c>, SAML NameID). Indexed but not unique: a
    /// member holding several relationships has a row for each. Never exposed to verifying peers.
    /// </summary>
    public string IamSubjectId { get; set; } = string.Empty;

    /// <summary>
    /// Raw Ed25519 public key, 32 bytes, stored as binary rather than base64 text.
    ///
    /// Null until the member sets one. Unique across the whole table when present, which is a security
    /// property and not merely data hygiene: keys are not secret, so without it anyone who saw a key
    /// during a connection could register it against a relationship of their own.
    /// </summary>
    public byte[]? PublicKey { get; set; }

    /// <summary>A value from the governed enum. Never free text.</summary>
    public string RelationshipType { get; set; } = string.Empty;

    /// <summary>
    /// Optional, ungoverned free text (for example "Fellow"). Informational only: never validated,
    /// never indexed, and never affects whether a key is valid.
    /// </summary>
    public string? RelationshipSubtype { get; set; }

    public DateTime? ExpiresAt { get; set; }

    public DateTime? RevokedAt { get; set; }

    /// <summary>
    /// The relationship's standing at a given moment.
    ///
    /// Computed rather than stored, so there is no second copy of the truth to drift from the timestamps
    /// it was derived from -- and re-evaluated on every check rather than settled once (decision record 4, and the
    /// README, How it works).
    ///
    /// Where two states could both apply, revocation is reported first: it is the deliberate act, where
    /// expiry is only the absence of anyone renewing. A revoked relationship reports revoked even with no
    /// key set, because telling the member it is "unkeyed" would invite them to set a key that would not
    /// restore anything.
    /// </summary>
    public RelationshipStatus StatusAt(DateTime utcNow)
    {
        if (RevokedAt is not null)
        {
            return RelationshipStatus.Revoked;
        }

        if (ExpiresAt is not null && ExpiresAt <= utcNow)
        {
            return RelationshipStatus.Expired;
        }

        return PublicKey is null ? RelationshipStatus.Unkeyed : RelationshipStatus.Active;
    }

    /// <summary>
    /// Whether a verifying peer should treat this relationship as currently attested. Exactly
    /// <see cref="RelationshipStatus.Active"/>, and nothing else.
    /// </summary>
    public bool IsValidAt(DateTime utcNow) => StatusAt(utcNow) == RelationshipStatus.Active;
}
