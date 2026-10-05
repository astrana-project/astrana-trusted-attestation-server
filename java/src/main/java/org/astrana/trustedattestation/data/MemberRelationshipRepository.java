package org.astrana.trustedattestation.data;

import java.util.List;
import java.util.Optional;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Modifying;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

/**
 * The whole data access surface.
 *
 * <p>Note what is absent: there is no {@code findAll}, and nothing that lists or enumerates keys across
 * members. The check operation is a point lookup by one specific key, which is what makes anonymous
 * access to it safe -- the only way to check a key is to already hold it. Listing a member's own
 * relationships is a different thing: it is scoped to one subject, and that subject comes from their own
 * session.
 *
 * <p>There is also no save-by-subject-alone. Relationships are created only by
 * grant_member_relationship, which the organisation calls and this application does not.
 */
public interface MemberRelationshipRepository extends JpaRepository<MemberRelationship, Long> {

    /** Everything one member holds, in a stable order so the page does not shuffle between loads. */
    List<MemberRelationship> findByIamSubjectIdOrderByRelationshipType(String iamSubjectId);

    /**
     * One specific relationship of the caller's own.
     *
     * <p>Both halves matter: the subject confines the lookup to the caller's own record, and the type
     * picks one of their relationships out of it. Neither is ever taken from anywhere but the session
     * and the route.
     */
    Optional<MemberRelationship> findByIamSubjectIdAndRelationshipType(String iamSubjectId, String relationshipType);

    /** The verification hot path: every check and every periodic re-check hits the unique index here. */
    Optional<MemberRelationship> findByPublicKey(byte[] publicKey);

    /**
     * Whether this key already belongs to some other relationship -- anyone's, including another of the
     * same member's own.
     *
     * <p>Excluding the row being updated is what keeps an idempotent retry from being answered with a
     * conflict against itself.
     */
    boolean existsByPublicKeyAndIdNot(byte[] publicKey, Long id);

    /**
     * The one write the application makes to this table: the key, and nothing else, on one row.
     *
     * <p>An explicit single-column update rather than saving the entity. Hibernate writes every mapped
     * column when it saves, and the documented application role holds UPDATE on {@code public_key} alone
     * (decision record 16 in docs/adr), so a full-row update is refused by every engine. The statement names the
     * one column the role may write, so it runs under that role as the schema intends.
     *
     * <p>Returns how many rows changed. Zero means the row went away between the lookup and this write,
     * which the caller answers as not found rather than recording an event that did not happen.
     */
    @Modifying
    @Query("update MemberRelationship r set r.publicKey = :publicKey where r.id = :id")
    int updatePublicKey(@Param("id") Long id, @Param("publicKey") byte[] publicKey);
}
