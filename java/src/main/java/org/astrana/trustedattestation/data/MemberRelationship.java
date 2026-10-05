package org.astrana.trustedattestation.data;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.GeneratedValue;
import jakarta.persistence.GenerationType;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import jakarta.persistence.UniqueConstraint;
import java.time.Instant;

/**
 * One row per relationship, not per member. A member can hold several at the same organisation --
 * employee and client at once, say -- and each carries its own independent key.
 *
 * <p>That independence is the point rather than a convenience: presenting one relationship's key reveals
 * nothing about any other the member holds, because there is nothing shared between the rows to reveal.
 * A single key per member would have made every relationship presentable by anyone who had seen any one
 * of them.
 *
 * <p>Deliberately holds no names and no PII beyond what the organisation's own IAM system already has.
 */
@Entity
@Table(
        name = "member_relationships",
        uniqueConstraints =
                @UniqueConstraint(
                        name = "uq_subject_type",
                        columnNames = {"iam_subject_id", "relationship_type"}))
public class MemberRelationship {

    /**
     * Sequential, and the physically-ordered key. Public keys look random, so ordering the table by them
     * would scatter inserts across the disk as it grows.
     */
    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    @Column(name = "id")
    private Long id;

    /**
     * The organisation's own IAM identifier (OIDC {@code sub}, SAML NameID). Indexed but deliberately
     * not unique: a member holding several relationships has a row for each, and marking it unique here
     * would silently limit every member to one. What is unique is the pair with relationship_type.
     */
    @Column(name = "iam_subject_id", nullable = false, length = 255)
    private String iamSubjectId;

    /**
     * Raw Ed25519 public key, 32 bytes, stored as binary rather than base64 text.
     *
     * <p>Null until the member sets one. Unique across the whole table when present, which is a security
     * property and not merely data hygiene: keys are not secret, so without it anyone who saw a key
     * during a connection could register it against a relationship of their own.
     */
    @Column(name = "public_key", unique = true, length = 32)
    private byte[] publicKey;

    /** A value from the governed enum. Never free text. */
    @Column(name = "relationship_type", nullable = false, length = 64)
    private String relationshipType;

    /**
     * Optional, ungoverned free text (for example "Fellow"). Informational only: never validated, never
     * indexed, and never affects whether a key is valid.
     */
    @Column(name = "relationship_subtype", length = 255)
    private String relationshipSubtype;

    @Column(name = "expires_at")
    private Instant expiresAt;

    @Column(name = "revoked_at")
    private Instant revokedAt;

    protected MemberRelationship() {
        // for JPA
    }

    public MemberRelationship(String iamSubjectId, String relationshipType) {
        this.iamSubjectId = iamSubjectId;
        this.relationshipType = relationshipType;
    }

    /**
     * The relationship's standing at a given moment.
     *
     * <p>Computed rather than stored, so there is no second copy of the truth to drift from the
     * timestamps it was derived from -- and re-evaluated on every check rather than settled once.
     *
     * <p>Where two states could both apply, revocation is reported first: it is the deliberate act,
     * where expiry is only the absence of anyone renewing. A revoked relationship reports revoked even
     * with no key set, because telling the member it is "unkeyed" would invite them to set a key that
     * would not restore anything.
     */
    public RelationshipStatus statusAt(Instant now) {
        if (revokedAt != null) {
            return RelationshipStatus.REVOKED;
        }

        if (expiresAt != null && !expiresAt.isAfter(now)) {
            return RelationshipStatus.EXPIRED;
        }

        return publicKey == null ? RelationshipStatus.UNKEYED : RelationshipStatus.ACTIVE;
    }

    /**
     * Whether a verifying peer should treat this relationship as currently attested. Exactly {@link
     * RelationshipStatus#ACTIVE}, and nothing else.
     */
    public boolean isValidAt(Instant now) {
        return statusAt(now) == RelationshipStatus.ACTIVE;
    }

    public Long getId() {
        return id;
    }

    public String getIamSubjectId() {
        return iamSubjectId;
    }

    public byte[] getPublicKey() {
        return publicKey;
    }

    public void setPublicKey(byte[] publicKey) {
        this.publicKey = publicKey;
    }

    public String getRelationshipType() {
        return relationshipType;
    }

    public String getRelationshipSubtype() {
        return relationshipSubtype;
    }

    public Instant getExpiresAt() {
        return expiresAt;
    }

    public Instant getRevokedAt() {
        return revokedAt;
    }

    // No public setters for relationship_type, expires_at or revoked_at, deliberately. The application
    // never writes any of them: the type is fixed when the organisation grants the relationship, and
    // standing is changed only by the stored procedures. A member must not be able to lift a revocation
    // by re-registering, and the absence of a setter is a stronger guarantee of that than a comment.
    // These exist for tests, which live in this package.

    void setExpiresAt(Instant expiresAt) {
        this.expiresAt = expiresAt;
    }

    void setRevokedAt(Instant revokedAt) {
        this.revokedAt = revokedAt;
    }
}
