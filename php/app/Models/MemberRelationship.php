<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BinaryColumn;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per relationship, not per member. A member can hold several at the same organisation --
 * employee and client at once, say -- and each carries its own independent key.
 *
 * That independence is the point rather than a convenience: presenting one relationship's key reveals
 * nothing about any other the member holds, because there is nothing shared between the rows to reveal.
 * A single key per member would have made every relationship presentable by anyone who had seen any one
 * of them.
 *
 * Deliberately holds no names and no PII beyond what the organisation's own IAM system already has.
 *
 * @property int $id
 * @property string $iam_subject_id the organisation's own IAM identifier; never exposed to verifiers
 * @property string|null $public_key raw Ed25519 key, 32 bytes, not base64 text; null until the member sets one
 * @property string $relationship_type a value from the governed enum; never free text
 * @property string|null $relationship_subtype optional, ungoverned free text; informational only
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $revoked_at
 */
class MemberRelationship extends Model
{
    protected $table = 'member_relationships';

    // The table has no created_at/updated_at. The data model is seven columns, and Astrana Trusted
    // Attestation has no use for a row's age. When it was granted is the audit log's business, not this table's.
    public $timestamps = false;

    protected $fillable = [
        'iam_subject_id',
    ];

    // relationship_type, relationship_subtype, expires_at and revoked_at are deliberately absent from
    // $fillable. The application never writes any of them: the type and subtype are fixed when the
    // organisation grants the relationship, and standing is changed only by the stored procedures. A
    // member must not be able to lift a revocation by re-registering a key.

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * The raw 32 bytes, or null when the member has not set a key yet.
     *
     * Queries select the column as hex (PDO cannot portably return raw binary), so the attribute that
     * actually arrives is public_key_hex; this decodes it back. Callers only ever see raw bytes.
     */
    public function getPublicKeyAttribute(): ?string
    {
        return BinaryColumn::fromHex($this->attributes['public_key_hex'] ?? null);
    }

    /**
     * The relationship's standing at a given moment.
     *
     * Computed rather than stored, so there is no second copy of the truth to drift from the timestamps
     * it was derived from -- and re-evaluated on every check rather than settled once.
     *
     * Where two states could both apply, revocation is reported first: it is the deliberate act, where
     * expiry is only the absence of anyone renewing. A revoked relationship reports revoked even with no
     * key set, because telling the member it is "unkeyed" would invite them to set a key that would not
     * restore anything.
     */
    public function statusAt(CarbonInterface $now): RelationshipStatus
    {
        if ($this->revoked_at !== null) {
            return RelationshipStatus::Revoked;
        }

        if ($this->expires_at !== null && ! $this->expires_at->greaterThan($now)) {
            return RelationshipStatus::Expired;
        }

        return $this->public_key === null ? RelationshipStatus::Unkeyed : RelationshipStatus::Active;
    }

    /**
     * Whether a verifying peer should treat this relationship as currently attested. Exactly
     * RelationshipStatus::Active, and nothing else.
     */
    public function isValidAt(CarbonInterface $now): bool
    {
        return $this->statusAt($now) === RelationshipStatus::Active;
    }
}
