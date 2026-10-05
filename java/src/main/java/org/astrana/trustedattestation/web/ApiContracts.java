package org.astrana.trustedattestation.web;

import com.fasterxml.jackson.annotation.JsonInclude;
import java.time.Instant;
import java.util.List;
import org.astrana.trustedattestation.data.MemberRelationship;
import org.astrana.trustedattestation.security.PublicKeys;

/**
 * Request and response shapes, matching {@code shared/contract/openapi.yaml} exactly. A verifying Astrana
 * instance cannot tell which of the three implementations it is talking to, so these must not drift.
 *
 * <p>Field names are camelCase and serialise to snake_case through the application-wide Jackson naming
 * strategy, so the JSON matches the contract without a single annotation. Null fields are omitted for the
 * same reason (see {@code spring.jackson} in application.yml).
 */
final class ApiContracts {

    private ApiContracts() {}

    /**
     * One relationship the member holds, with its own key and its own standing.
     *
     * @param status unkeyed, active, revoked or expired
     * @param publicKey this relationship's own key, or null when none is registered yet, never shared
     *     with any other relationship the member holds
     * @param expiresAt when the relationship expires, already in the wire form every implementation
     *     writes (see {@link WireTimestamps}), or null when it does not expire
     */
    // Null fields are kept, unlike this application's default and unlike the AttestResponse below: the
    // /me contract lists public_key, relationship_subtype and expires_at as present-and-nullable, so a
    // member's page always shows the same shape whether or not a relationship is keyed or expiring. The
    // other two implementations serialise these as explicit nulls, and matching them keeps the three byte
    // for byte identical. AttestResponse is the exception, because there absence carries meaning.
    @JsonInclude(JsonInclude.Include.ALWAYS)
    record RelationshipWithKey(
            String relationshipType, String relationshipSubtype, String status, String publicKey, String expiresAt) {

        static RelationshipWithKey from(MemberRelationship row, Instant now) {
            return new RelationshipWithKey(
                    row.getRelationshipType(),
                    row.getRelationshipSubtype(),
                    row.statusAt(now).wireValue(),
                    row.getPublicKey() == null ? null : PublicKeys.toBase64(row.getPublicKey()),
                    WireTimestamps.format(row.getExpiresAt()));
        }
    }

    /**
     * @param name from the session the organisation's identity system started, never stored
     * @param relationships every relationship the member holds here, each with its own key status.
     *     Always an array, even when it holds one entry and even when it holds none: a member the
     *     organisation has granted nothing is an ordinary state rather than an error.
     */
    record MeResponse(String name, List<RelationshipWithKey> relationships) {}

    /**
     * @param publicKey the key now on record, or null when the member cleared it. Registering a key echoes
     *     it back, and clearing one returns null, so the page can show the field empty and the standing back at
     *     "waiting for your key".
     * @param status the relationship's standing after the key was set. Present because neither setting nor
     *     clearing a key lifts an organisation's revocation: a member who registers or clears a key on a
     *     revoked relationship gets a 200, because the write really happened, and would otherwise have every
     *     reason to believe they had restored themselves. This is what tells them they have not.
     */
    // Keeps a null relationship_subtype and a null public_key, for the same reason RelationshipWithKey
    // does: the response to a key write has one fixed shape across all three implementations.
    @JsonInclude(JsonInclude.Include.ALWAYS)
    record SetKeyResponse(String publicKey, String relationshipType, String relationshipSubtype, String status) {

        static SetKeyResponse from(MemberRelationship row, Instant now) {
            return new SetKeyResponse(
                    row.getPublicKey() == null ? null : PublicKeys.toBase64(row.getPublicKey()),
                    row.getRelationshipType(),
                    row.getRelationshipSubtype(),
                    row.statusAt(now).wireValue());
        }
    }

    /**
     * The answer to "is this key on record here, and what is its standing?".
     *
     * <p>A key that was never registered gets exactly {@code {"valid": false}} and nothing else, so an
     * anonymous caller fishing for keys learns nothing at all. A caller holding a real key is in a
     * different position: they can only have it because the member presented it to them, so telling them
     * the relationship has been revoked is the point of their asking rather than a leak.
     *
     * <p>Astrana's own three outcomes, valid, invalid and unreachable, are derived from both fields
     * together. A verifying Astrana instance counts only an active status as valid, even though the lookup
     * succeeded for any other status.
     */
    record AttestResponse(boolean valid, String relationshipType, String relationshipSubtype, String status) {

        static final AttestResponse INVALID = new AttestResponse(false, null, null, null);

        static AttestResponse from(MemberRelationship row, Instant now) {
            return new AttestResponse(
                    true,
                    row.getRelationshipType(),
                    row.getRelationshipSubtype(),
                    row.statusAt(now).wireValue());
        }
    }
}
