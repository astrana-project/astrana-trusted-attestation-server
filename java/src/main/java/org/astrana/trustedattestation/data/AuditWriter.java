package org.astrana.trustedattestation.data;

import jakarta.persistence.EntityManager;
import jakarta.persistence.PersistenceContext;
import java.sql.Timestamp;
import java.time.Instant;
import org.springframework.stereotype.Component;

/** The seven events the audit trail records. Nothing else is ever written to it. */
final class AuditEvent {

    // Written here, by the application, for what a member does to their own record.
    static final String KEY_REGISTERED = "key_registered";
    static final String KEY_CLEARED = "key_cleared";
    static final String KEY_REMOVED = "key_removed";

    // Written by the stored procedures, not by the application. Named here so the vocabulary lives in
    // one place and a reader can see the whole trail without opening the schema.
    static final String RELATIONSHIP_GRANTED_BY_ORG = "relationship_granted_by_org";
    static final String RELATIONSHIP_REVOKED_BY_ORG = "relationship_revoked_by_org";
    static final String RELATIONSHIP_EXTENDED_BY_ORG = "relationship_extended_by_org";
    static final String RELATIONSHIP_SELF_REVOKED = "relationship_self_revoked";

    private AuditEvent() {}
}

/**
 * Writes the three self-service audit events.
 *
 * <p>A native parameterised insert rather than a JPA entity, for two reasons. The application's database
 * principal holds {@code INSERT} only on {@code audit_log} -- an audit trail that can be edited after the
 * fact is not one -- and mapping it as an entity would invite a repository that can read, update and
 * delete it. And the requirement that the audit row is written in the same transaction as the change it
 * logs is easier to see is true when the insert is right here, sharing the caller's persistence context,
 * rather than behind a second abstraction.
 *
 * <p>Never records the public key itself, and never records a verification -- checking a key is not one
 * of the events, and logging checks would tell the organisation who is asking.
 */
@Component
public class AuditWriter {

    @PersistenceContext
    private EntityManager entityManager;

    /**
     * Enlists in the caller's transaction, so the audit row and the change it records commit or roll back
     * together. {@code actor} is null for self-service events: the member is not acting on someone else.
     *
     * <p>The relationship type is recorded as well as the subject, because a member can hold several and
     * an entry naming only the subject would not say what actually happened.
     */
    public void write(String eventType, String iamSubjectId, String relationshipType, Instant occurredAt) {
        entityManager
                .createNativeQuery(
                        "INSERT INTO audit_log (event_type, iam_subject_id, relationship_type, actor, occurred_at) "
                                + "VALUES (?1, ?2, ?3, NULL, ?4)")
                .setParameter(1, eventType)
                .setParameter(2, iamSubjectId)
                .setParameter(3, relationshipType)
                .setParameter(4, Timestamp.from(occurredAt))
                .executeUpdate();
    }

    public void keyRegistered(String iamSubjectId, String relationshipType, Instant occurredAt) {
        write(AuditEvent.KEY_REGISTERED, iamSubjectId, relationshipType, occurredAt);
    }

    public void keyCleared(String iamSubjectId, String relationshipType, Instant occurredAt) {
        write(AuditEvent.KEY_CLEARED, iamSubjectId, relationshipType, occurredAt);
    }

    public void keyRemoved(String iamSubjectId, String relationshipType, Instant occurredAt) {
        write(AuditEvent.KEY_REMOVED, iamSubjectId, relationshipType, occurredAt);
    }
}
