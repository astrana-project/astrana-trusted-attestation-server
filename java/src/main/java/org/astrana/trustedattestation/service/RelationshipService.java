package org.astrana.trustedattestation.service;

import java.time.Clock;
import java.time.Instant;
import java.util.List;
import java.util.Optional;
import org.astrana.trustedattestation.contract.RelationshipTypeCatalog;
import org.astrana.trustedattestation.data.AuditWriter;
import org.astrana.trustedattestation.data.DuplicateKey;
import org.astrana.trustedattestation.data.MemberRelationship;
import org.astrana.trustedattestation.data.MemberRelationshipRepository;
import org.astrana.trustedattestation.data.SelfRevokeCommand;
import org.springframework.dao.DataIntegrityViolationException;
import org.springframework.stereotype.Service;
import org.springframework.transaction.PlatformTransactionManager;
import org.springframework.transaction.annotation.Transactional;
import org.springframework.transaction.support.TransactionTemplate;

/**
 * The four operations, with their transaction boundaries.
 *
 * <p>Each write is one transaction covering both the {@code member_relationships} change and the {@code
 * audit_log} insert, so there is no path where the change happens but the log entry does not. The
 * organisation-initiated stored procedures get that atomicity from being a single call; the self-service
 * path gets it from here.
 *
 * <p>Granting is deliberately absent. A relationship exists only because the organisation created it
 * with grant_member_relationship, and there is no method here that could create one -- which is what
 * makes the API's 404 a structural fact rather than a check someone could later relax.
 *
 * <p>The relationship type a caller names has to be in the governed vocabulary and has to equal the row's
 * type exactly, whatever the engine's collation does. A database that compares {@code EMPLOYEE} equal to
 * {@code employee} would otherwise let a caller act on a row through a spelling the contract does not
 * define, and the audit log would record that spelling. So every lookup here compares the row's own type
 * with the one asked for, and every audit entry and procedure call is given the row's type, never the
 * path's. The subject identifier is compared exactly in the same way, because MySQL and SQL Server match
 * {@code alice} to a row held by {@code alice } or {@code ALICE}, which belongs to another member.
 */
@Service
public class RelationshipService {

    private final MemberRelationshipRepository repository;
    private final AuditWriter audit;
    private final SelfRevokeCommand selfRevoke;
    private final RelationshipTypeCatalog catalog;
    private final Clock clock;

    /**
     * Used instead of {@code @Transactional} for the one operation that has to survive losing a race;
     * see {@link #setKey}.
     */
    private final TransactionTemplate transactions;

    public RelationshipService(
            MemberRelationshipRepository repository,
            AuditWriter audit,
            SelfRevokeCommand selfRevoke,
            RelationshipTypeCatalog catalog,
            Clock clock,
            PlatformTransactionManager transactionManager) {
        this.repository = repository;
        this.audit = audit;
        this.selfRevoke = selfRevoke;
        this.catalog = catalog;
        this.clock = clock;
        this.transactions = new TransactionTemplate(transactionManager);
    }

    /**
     * Everything the member holds. An empty list is an ordinary answer, not an error. A row the engine
     * matched under a collation that ignores case or trailing spaces, but whose own subject is spelt
     * differently, belongs to someone else and is left out.
     */
    @Transactional(readOnly = true)
    public List<MemberRelationship> findAllHeldBy(String iamSubjectId) {
        return repository.findByIamSubjectIdOrderByRelationshipType(iamSubjectId).stream()
                .filter(row -> iamSubjectId.equals(row.getIamSubjectId()))
                .toList();
    }

    /** One of the member's own relationships, by type. */
    @Transactional(readOnly = true)
    public Optional<MemberRelationship> find(String iamSubjectId, String relationshipType) {
        return held(iamSubjectId, relationshipType);
    }

    /**
     * Registers or replaces the key for one relationship, and nothing else the member holds.
     *
     * <p>Never creates. A relationship that is not there is {@link SetKeyResult#NOT_GRANTED}, because a
     * PUT that could create would let any member self-grant any type they liked, with no organisation
     * involvement at all.
     *
     * <p>A null {@code publicKey} clears the key instead of setting one: the member unsetting their own key
     * to pause the relationship without giving up the grant, so an empty key field saved from the page
     * returns them to the same "waiting for your key" state as before their first save. Being the member's
     * own act, it can be undone by simply saving a key again -- unlike an organisation revocation. Clearing
     * takes no conflict check (there is no key to collide with anything) and, like registering, leaves
     * revoked_at and expires_at alone, so a revoked relationship still reports revoked afterwards. It is
     * audited only when a key was actually removed, so re-saving an already-empty field records nothing.
     *
     * <p>The write is a single-column update of {@code public_key} on the row found, see {@link
     * MemberRelationshipRepository#updatePublicKey}. A row that went away between the lookup and the
     * write changes nothing, and is answered {@link SetKeyResult#NOT_GRANTED} with no audit entry.
     *
     * <p>The transaction is managed explicitly here rather than with {@code @Transactional}, because the
     * unique index on public_key has to be allowed to reject this write. Catching that inside the
     * transaction does not work: the constraint violation marks the transaction rollback-only, so
     * returning normally afterwards makes the commit throw UnexpectedRollbackException, and a lost race
     * surfaces as a 500. The engines force the same shape from below -- PostgreSQL aborts a transaction
     * outright once a statement in it has failed, so there is nothing to salvage from inside either. The
     * catch therefore sits outside the transaction, where the rollback has already happened. Only the
     * duplicate itself is caught, identified by the engine's own error code (see {@link DuplicateKey}), so
     * any other failure surfaces as the 500 it is.
     */
    public SetKeyResult setKey(String iamSubjectId, String relationshipType, byte[] publicKey) {
        try {
            return transactions.execute(status -> setKeyInTransaction(iamSubjectId, relationshipType, publicKey));
        } catch (DataIntegrityViolationException violation) {
            if (DuplicateKey.describes(violation)) {
                // Lost the race on the unique index: another relationship took this key between the
                // check above and the write. Same answer as if the check had caught it.
                return SetKeyResult.CONFLICT;
            }

            throw violation;
        }
    }

    /**
     * A hard delete of this one relationship, not a soft revoke and not a return to unkeyed: a member
     * exercising this themselves is closer to a right-to-erasure action than an organisation-initiated
     * status change, and leaving the row behind would mean their request to leave removed only their key.
     *
     * <p>Works the same whether the relationship is currently keyed or not -- deletability depends on the
     * relationship existing, not on it being keyed. The member's other relationships are untouched, and
     * so is the audit trail: it records that events happened, which is not the same personal data as the
     * key itself.
     */
    @Transactional
    public boolean delete(String iamSubjectId, String relationshipType) {
        Optional<MemberRelationship> row = held(iamSubjectId, relationshipType);

        if (row.isEmpty()) {
            return false;
        }

        repository.delete(row.get());
        repository.flush();

        audit.keyRemoved(iamSubjectId, row.get().getRelationshipType(), Instant.now(clock));
        return true;
    }

    /**
     * Member-initiated revoke: one-directional, and idempotent.
     *
     * <p>Through the stored procedure rather than by writing revoked_at, because the procedure is what
     * enforces the direction -- NULL to now(), never the reverse -- and writes its own audit row. Nothing
     * here writes a second one: two entries for one event in an append-only log cannot be corrected
     * afterwards.
     *
     * <p>Returns false for a relationship the member does not hold, so that the caller can answer 404
     * rather than a silent success. The procedure itself cannot make that distinction, and should not
     * have to: it cannot tell "not granted" from "already revoked", and both are no-ops to it.
     *
     * <p>Deliberately not {@code @Transactional}: the procedure opens and commits its own transaction
     * (MySQL and SQL Server need that -- neither rolls a batch back on a failed statement the way
     * PostgreSQL does). A procedure that commits inside an outer transaction ends it, so an app-managed
     * wrapper here would leave the framework trying to commit a transaction that is already gone -- a 500
     * for a revoke that actually succeeded. The pre-check read runs in its own short transaction, which
     * is all it needs. This matches how the other two implementations call it.
     */
    public boolean selfRevoke(String iamSubjectId, String relationshipType) {
        Optional<MemberRelationship> row = held(iamSubjectId, relationshipType);
        if (row.isEmpty()) {
            return false;
        }

        selfRevoke.invoke(iamSubjectId, row.get().getRelationshipType());
        return true;
    }

    /**
     * A point lookup: one specific key in, the one relationship it belongs to out. There is no operation
     * to list or enumerate keys on record, which is what makes anonymous access to this safe.
     *
     * <p>Returns the row whatever its standing, and lets the caller report that standing. A caller
     * holding a real key received it from the member, so being told the relationship is revoked is what
     * they came to find out; it is a key that was never registered which learns nothing.
     *
     * <p>Nothing is written here. A verification is not one of the audit events, and logging checks would
     * tell the organisation who is asking.
     */
    @Transactional(readOnly = true)
    public Optional<MemberRelationship> attest(byte[] publicKey) {
        return repository.findByPublicKey(publicKey);
    }

    /** The body of {@link #setKey}, run inside its transaction. */
    private SetKeyResult setKeyInTransaction(String iamSubjectId, String relationshipType, byte[] publicKey) {
        Optional<MemberRelationship> found = held(iamSubjectId, relationshipType);

        if (found.isEmpty()) {
            return SetKeyResult.NOT_GRANTED;
        }

        MemberRelationship row = found.get();

        if (publicKey == null) {
            return clearKey(iamSubjectId, row);
        }

        // A key belongs to exactly one relationship anywhere in the table, including another of this
        // member's own. Checked up front for a clear answer. The unique index is what actually enforces it
        // when two requests race, hence the catch in setKey. Excluding this row keeps an idempotent retry
        // from being answered with a conflict against itself.
        if (repository.existsByPublicKeyAndIdNot(publicKey, row.getId())) {
            return SetKeyResult.CONFLICT;
        }

        // revoked_at and expires_at are deliberately left alone, as the statement names public_key and
        // nothing else. Registering a key is not an appeal: a member must not be able to lift an
        // organisation's revocation by registering a key again, so the status the caller is handed back may
        // well still say revoked.
        //
        // Executed here, inside the transaction, so a violation surfaces as an exception setKey can
        // attribute to this write rather than at some later commit.
        if (repository.updatePublicKey(row.getId(), publicKey) == 0) {
            return SetKeyResult.NOT_GRANTED;
        }

        audit.keyRegistered(iamSubjectId, row.getRelationshipType(), Instant.now(clock));
        return SetKeyResult.SAVED;
    }

    /**
     * Clearing. There is no conflict check, because there is no key to collide with, and as with
     * registering the standing is left untouched. Written and audited only when a key was actually removed, so re-saving an
     * already-empty field is a quiet no-op.
     */
    private SetKeyResult clearKey(String iamSubjectId, MemberRelationship row) {
        if (row.getPublicKey() == null) {
            return SetKeyResult.SAVED;
        }

        if (repository.updatePublicKey(row.getId(), null) == 0) {
            return SetKeyResult.NOT_GRANTED;
        }

        audit.keyCleared(iamSubjectId, row.getRelationshipType(), Instant.now(clock));
        return SetKeyResult.SAVED;
    }

    /**
     * The member's relationship of exactly this type, or empty. Empty for a type outside the governed
     * vocabulary without asking the database, and empty for a row the engine matched under a collation
     * that ignores case or trailing spaces but whose own subject or type is spelt differently: the row is
     * not the one asked for.
     */
    private Optional<MemberRelationship> held(String iamSubjectId, String relationshipType) {
        if (!catalog.isGoverned(relationshipType)) {
            return Optional.empty();
        }

        return repository
                .findByIamSubjectIdAndRelationshipType(iamSubjectId, relationshipType)
                .filter(row -> iamSubjectId.equals(row.getIamSubjectId())
                        && relationshipType.equals(row.getRelationshipType()));
    }

    public enum SetKeyResult {
        SAVED,

        /** This key already belongs to a different relationship -- anyone's, including the member's own. */
        CONFLICT,

        /** The organisation has not granted the caller this relationship. Answered 404, never created. */
        NOT_GRANTED,
    }
}
