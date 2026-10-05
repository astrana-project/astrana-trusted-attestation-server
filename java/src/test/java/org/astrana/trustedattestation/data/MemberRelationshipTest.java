package org.astrana.trustedattestation.data;

import static org.assertj.core.api.Assertions.assertThat;

import java.time.Instant;
import org.junit.jupiter.api.Test;

/**
 * What a relationship's standing is at a given moment.
 *
 * <p>Point-in-time and re-evaluated on every check, never a proof established once and assumed forever.
 * The status is computed from the row rather than stored, so there is no second copy of the truth to
 * fall out of step with the timestamps it was derived from.
 */
class MemberRelationshipTest {

    private static final Instant NOW = Instant.parse("2026-08-26T12:00:00Z");

    private static byte[] someKey() {
        byte[] key = new byte[32];
        java.util.Arrays.fill(key, (byte) 7);

        return key;
    }

    private static MemberRelationship row(byte[] publicKey, Instant expiresAt, Instant revokedAt) {
        MemberRelationship row = new MemberRelationship("abc-123", "employee");
        row.setPublicKey(publicKey);
        row.setExpiresAt(expiresAt);
        row.setRevokedAt(revokedAt);

        return row;
    }

    // ---------------------------------------------------------------------------------------------
    // The ordinary states
    // ---------------------------------------------------------------------------------------------

    @Test
    void aKeyedRelationshipWithNoExpiryAndNoRevocationIsActive() {
        assertThat(row(someKey(), null, null).statusAt(NOW)).isEqualTo(RelationshipStatus.ACTIVE);
    }

    @Test
    void aGrantedRelationshipTheMemberHasNotKeyedYetIsUnkeyed() {
        // The normal state immediately after grant_member_relationship, and the reason public_key is
        // nullable at all: the organisation grants the relationship before the member has ever logged in.
        assertThat(row(null, null, null).statusAt(NOW)).isEqualTo(RelationshipStatus.UNKEYED);
    }

    @Test
    void aRevokedRelationshipIsRevoked() {
        assertThat(row(someKey(), null, NOW.minusSeconds(86400)).statusAt(NOW)).isEqualTo(RelationshipStatus.REVOKED);
    }

    @Test
    void aLapsedRelationshipIsExpired() {
        assertThat(row(someKey(), NOW.minusSeconds(1), null).statusAt(NOW)).isEqualTo(RelationshipStatus.EXPIRED);
    }

    @Test
    void aRelationshipExpiringInTheFutureIsStillActive() {
        assertThat(row(someKey(), NOW.plusSeconds(86400), null).statusAt(NOW)).isEqualTo(RelationshipStatus.ACTIVE);
    }

    @Test
    void expiryIsExclusiveAtTheBoundary() {
        assertThat(row(someKey(), NOW, null).statusAt(NOW)).isEqualTo(RelationshipStatus.EXPIRED);
    }

    // ---------------------------------------------------------------------------------------------
    // Where two states could both apply
    // ---------------------------------------------------------------------------------------------

    @Test
    void revocationBeatsAFutureExpiry() {
        assertThat(row(someKey(), NOW.plusSeconds(31536000), NOW.minusSeconds(86400))
                        .statusAt(NOW))
                .isEqualTo(RelationshipStatus.REVOKED);
    }

    @Test
    void revocationBeatsAPastExpiryToo() {
        // Both are true of the row. Revocation is reported because it is the deliberate act, where
        // expiry is only the absence of anyone renewing it.
        assertThat(row(someKey(), NOW.minusSeconds(31536000), NOW.minusSeconds(86400))
                        .statusAt(NOW))
                .isEqualTo(RelationshipStatus.REVOKED);
    }

    @Test
    void aRevokedRelationshipReportsRevokedEvenWithNoKeySet() {
        // An organisation can revoke a relationship the member never got around to keying. Reporting
        // "unkeyed" here would invite them to set a key that would not restore anything.
        assertThat(row(null, null, NOW.minusSeconds(86400)).statusAt(NOW)).isEqualTo(RelationshipStatus.REVOKED);
    }

    @Test
    void anUnkeyedRelationshipWhoseGrantHasLapsedReportsExpired() {
        assertThat(row(null, NOW.minusSeconds(86400), null).statusAt(NOW)).isEqualTo(RelationshipStatus.EXPIRED);
    }

    // ---------------------------------------------------------------------------------------------
    // What a verifying peer is told
    // ---------------------------------------------------------------------------------------------

    @Test
    void onlyAnActiveRelationshipCountsAsCurrentlyValid() {
        assertThat(row(someKey(), null, null).isValidAt(NOW)).isTrue();
        assertThat(row(someKey(), null, NOW.minusSeconds(86400)).isValidAt(NOW)).isFalse();
        assertThat(row(someKey(), NOW.minusSeconds(86400), null).isValidAt(NOW)).isFalse();
        assertThat(row(null, null, null).isValidAt(NOW)).isFalse();
    }

    @Test
    void theWireNamesAreTheOnesTheContractUses() {
        // These strings are the contract's enum, not a display concern. A rename here is a breaking
        // change for every verifying peer, so it is asserted rather than left to the enum's spelling.
        assertThat(RelationshipStatus.UNKEYED.wireValue()).isEqualTo("unkeyed");
        assertThat(RelationshipStatus.ACTIVE.wireValue()).isEqualTo("active");
        assertThat(RelationshipStatus.REVOKED.wireValue()).isEqualTo("revoked");
        assertThat(RelationshipStatus.EXPIRED.wireValue()).isEqualTo("expired");
    }
}
