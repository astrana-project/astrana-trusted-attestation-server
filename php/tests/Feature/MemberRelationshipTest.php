<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberRelationship;
use App\Models\RelationshipStatus;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a relationship's standing is at a given moment.
 *
 * Point-in-time and re-evaluated on every check, never a proof established once and assumed forever.
 * The status is computed from the row rather than stored, so there is no second copy of the truth to
 * fall out of step with the timestamps it was derived from.
 *
 * A Feature test rather than a Unit one because Eloquent resolves its date casts through the container,
 * so a bare PHPUnit case cannot even construct the model. It touches no database: the rows are built in
 * memory.
 */
final class MemberRelationshipTest extends TestCase
{
    private const NOW = '2026-08-26T12:00:00Z';

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::NOW);
    }

    private function row(bool $keyed = true, ?string $expiresAt = null, ?string $revokedAt = null): MemberRelationship
    {
        $row = new MemberRelationship;
        $row->iam_subject_id = 'abc-123';
        $row->relationship_type = 'employee';
        $row->expires_at = $expiresAt === null ? null : CarbonImmutable::parse($expiresAt);
        $row->revoked_at = $revokedAt === null ? null : CarbonImmutable::parse($revokedAt);

        // Set through the same hex attribute the query returns, so the test exercises the decode path
        // rather than a shape the database never produces.
        $row->setRawAttributes(
            array_merge($row->getAttributes(), ['public_key_hex' => $keyed ? str_repeat('07', 32) : null]),
            true
        );

        return $row;
    }

    // ---------------------------------------------------------------------------------------------
    // The ordinary states
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_keyed_relationship_with_no_expiry_and_no_revocation_is_active(): void
    {
        self::assertSame(RelationshipStatus::Active, $this->row()->statusAt($this->now()));
    }

    #[Test]
    public function a_granted_relationship_the_member_has_not_keyed_yet_is_unkeyed(): void
    {
        // The normal state immediately after grant_member_relationship, and the reason public_key is
        // nullable at all: the organisation grants the relationship before the member has ever logged in.
        self::assertSame(RelationshipStatus::Unkeyed, $this->row(keyed: false)->statusAt($this->now()));
    }

    #[Test]
    public function a_revoked_relationship_is_revoked(): void
    {
        self::assertSame(
            RelationshipStatus::Revoked,
            $this->row(revokedAt: '2026-08-25T12:00:00Z')->statusAt($this->now())
        );
    }

    #[Test]
    public function a_lapsed_relationship_is_expired(): void
    {
        self::assertSame(
            RelationshipStatus::Expired,
            $this->row(expiresAt: '2026-08-26T11:59:59Z')->statusAt($this->now())
        );
    }

    #[Test]
    public function a_relationship_expiring_in_the_future_is_still_active(): void
    {
        self::assertSame(
            RelationshipStatus::Active,
            $this->row(expiresAt: '2026-08-27T12:00:00Z')->statusAt($this->now())
        );
    }

    #[Test]
    public function expiry_is_exclusive_at_the_boundary(): void
    {
        self::assertSame(
            RelationshipStatus::Expired,
            $this->row(expiresAt: self::NOW)->statusAt($this->now())
        );
    }

    // ---------------------------------------------------------------------------------------------
    // Where two states could both apply
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function revocation_beats_a_future_expiry(): void
    {
        self::assertSame(
            RelationshipStatus::Revoked,
            $this->row(expiresAt: '2027-08-26T12:00:00Z', revokedAt: '2026-08-25T12:00:00Z')
                ->statusAt($this->now())
        );
    }

    #[Test]
    public function revocation_beats_a_past_expiry_too(): void
    {
        // Both are true of the row. Revocation is reported because it is the deliberate act, where
        // expiry is only the absence of anyone renewing it.
        self::assertSame(
            RelationshipStatus::Revoked,
            $this->row(expiresAt: '2025-08-26T12:00:00Z', revokedAt: '2026-08-25T12:00:00Z')
                ->statusAt($this->now())
        );
    }

    #[Test]
    public function a_revoked_relationship_reports_revoked_even_with_no_key_set(): void
    {
        // An organisation can revoke a relationship the member never got around to keying. Reporting
        // "unkeyed" here would invite them to set a key that would not restore anything.
        self::assertSame(
            RelationshipStatus::Revoked,
            $this->row(keyed: false, revokedAt: '2026-08-25T12:00:00Z')->statusAt($this->now())
        );
    }

    #[Test]
    public function an_unkeyed_relationship_whose_grant_has_lapsed_reports_expired(): void
    {
        self::assertSame(
            RelationshipStatus::Expired,
            $this->row(keyed: false, expiresAt: '2026-08-25T12:00:00Z')->statusAt($this->now())
        );
    }

    // ---------------------------------------------------------------------------------------------
    // What a verifying peer is told
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function only_an_active_relationship_counts_as_currently_valid(): void
    {
        self::assertTrue($this->row()->isValidAt($this->now()));
        self::assertFalse($this->row(revokedAt: '2026-08-25T12:00:00Z')->isValidAt($this->now()));
        self::assertFalse($this->row(expiresAt: '2026-08-25T12:00:00Z')->isValidAt($this->now()));
        self::assertFalse($this->row(keyed: false)->isValidAt($this->now()));
    }

    #[Test]
    public function the_wire_names_are_the_ones_the_contract_uses(): void
    {
        // These strings are the contract's enum, not a display concern. A rename here is a breaking
        // change for every verifying peer, so it is asserted rather than left to the enum's spelling.
        self::assertSame('unkeyed', RelationshipStatus::Unkeyed->value);
        self::assertSame('active', RelationshipStatus::Active->value);
        self::assertSame('revoked', RelationshipStatus::Revoked->value);
        self::assertSame('expired', RelationshipStatus::Expired->value);
    }
}
