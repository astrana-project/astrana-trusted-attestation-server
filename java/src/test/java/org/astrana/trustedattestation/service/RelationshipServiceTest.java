package org.astrana.trustedattestation.service;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.anyLong;
import static org.mockito.ArgumentMatchers.anyString;
import static org.mockito.Mockito.lenient;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.never;
import static org.mockito.Mockito.verify;
import static org.mockito.Mockito.verifyNoInteractions;
import static org.mockito.Mockito.when;

import java.sql.SQLException;
import java.time.Clock;
import java.time.Instant;
import java.time.ZoneOffset;
import java.util.List;
import java.util.Optional;
import org.astrana.trustedattestation.contract.RelationshipTypeCatalog;
import org.astrana.trustedattestation.data.AuditWriter;
import org.astrana.trustedattestation.data.MemberRelationship;
import org.astrana.trustedattestation.data.MemberRelationshipRepository;
import org.astrana.trustedattestation.data.SelfRevokeCommand;
import org.astrana.trustedattestation.service.RelationshipService.SetKeyResult;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.extension.ExtendWith;
import org.mockito.Mock;
import org.mockito.junit.jupiter.MockitoExtension;
import org.springframework.dao.DataIntegrityViolationException;
import org.springframework.transaction.PlatformTransactionManager;
import org.springframework.transaction.TransactionStatus;

/**
 * The four self-service operations and their branches, isolated from any database.
 *
 * <p>The conformance suite drives these end to end against a real engine; this pins the decisions the
 * service itself makes -- not-granted vs conflict vs saved, a lost race becoming a conflict rather than a
 * 500, a row that vanished becoming not found rather than a recorded event, a type the path spells
 * differently from the row being refused whatever the engine's collation did, that a self-revoke on a
 * relationship the member does not hold calls no procedure -- so a regression in any of them fails here,
 * fast, instead of only in an integration run. The injected clock means the audit timestamp is asserted
 * exactly.
 */
@ExtendWith(MockitoExtension.class)
class RelationshipServiceTest {

    private static final Instant NOW = Instant.parse("2026-01-01T12:00:00Z");
    private static final byte[] KEY = "a-thirty-two-byte-public-key----".getBytes();

    @Mock
    private MemberRelationshipRepository repository;

    @Mock
    private AuditWriter audit;

    @Mock
    private SelfRevokeCommand selfRevoke;

    @Mock
    private PlatformTransactionManager transactionManager;

    private RelationshipService service;

    @BeforeEach
    void setUp() {
        // setKey runs its body inside a TransactionTemplate, which on a runtime exception rolls back and
        // rethrows -- the behaviour its out-of-transaction catch relies on. A plain mock manager gives
        // exactly that: a status to hand back, and no-op commit/rollback. Only setKey touches it, so the
        // stub is lenient.
        lenient().when(transactionManager.getTransaction(any())).thenReturn(mock(TransactionStatus.class));
        service = new RelationshipService(
                repository,
                audit,
                selfRevoke,
                new RelationshipTypeCatalog(),
                Clock.fixed(NOW, ZoneOffset.UTC),
                transactionManager);
    }

    private static MemberRelationship relationship() {
        return new MemberRelationship("alice", "employee");
    }

    private void holds(MemberRelationship row) {
        when(repository.findByIamSubjectIdAndRelationshipType("alice", "employee"))
                .thenReturn(Optional.ofNullable(row));
    }

    private static DataIntegrityViolationException refusedBy(String sqlState, int errorCode) {
        return new DataIntegrityViolationException(
                "could not execute statement", new SQLException("refused", sqlState, errorCode));
    }

    // -- setKey --------------------------------------------------------------------------------------

    @Test
    void setKeyOnARelationshipTheMemberDoesNotHoldIsNotGrantedAndCreatesNothing() {
        holds(null);

        assertThat(service.setKey("alice", "employee", KEY)).isEqualTo(SetKeyResult.NOT_GRANTED);

        verify(repository, never()).updatePublicKey(anyLong(), any());
        verify(repository, never()).saveAndFlush(any());
        verifyNoInteractions(audit);
    }

    @Test
    void setKeyWithAKeyAlreadyHeldElsewhereIsAConflict() {
        MemberRelationship row = relationship();
        holds(row);
        when(repository.existsByPublicKeyAndIdNot(KEY, row.getId())).thenReturn(true);

        assertThat(service.setKey("alice", "employee", KEY)).isEqualTo(SetKeyResult.CONFLICT);

        verify(repository, never()).updatePublicKey(any(), any());
        verifyNoInteractions(audit);
    }

    @Test
    void setKeyWritesTheKeyColumnAloneAndRecordsTheEventOnTheInjectedClock() {
        // The one write the application makes to the table is the single-column update, never a save of
        // the whole entity: the application role may write public_key and nothing else.
        MemberRelationship row = relationship();
        holds(row);
        when(repository.existsByPublicKeyAndIdNot(KEY, row.getId())).thenReturn(false);
        when(repository.updatePublicKey(row.getId(), KEY)).thenReturn(1);

        assertThat(service.setKey("alice", "employee", KEY)).isEqualTo(SetKeyResult.SAVED);

        verify(repository).updatePublicKey(row.getId(), KEY);
        verify(repository, never()).saveAndFlush(any());
        verify(repository, never()).save(any());
        verify(audit).keyRegistered("alice", "employee", NOW);
    }

    // "Registering a key never lifts a revocation" is not tested here on purpose: setKey has no access
    // to revoked_at -- the setter is package-private to the data package and setKey lives in this one --
    // so it is a guarantee the compiler enforces, not a branch a runtime test could exercise.

    @Test
    void aKeyLostToAConcurrentWriteBecomesAConflictNotAServerError() {
        MemberRelationship row = relationship();
        holds(row);
        // The up-front check passes, then another request takes the key: the unique index rejects the
        // write, which must surface as a conflict, not the 500 an unhandled violation would give. On each
        // engine, by the error it reports.
        when(repository.existsByPublicKeyAndIdNot(KEY, row.getId())).thenReturn(false);
        when(repository.updatePublicKey(row.getId(), KEY))
                .thenThrow(refusedBy("23505", 0))
                .thenThrow(refusedBy("23000", 1062))
                .thenThrow(refusedBy("23000", 2627));

        assertThat(service.setKey("alice", "employee", KEY)).isEqualTo(SetKeyResult.CONFLICT);
        assertThat(service.setKey("alice", "employee", KEY)).isEqualTo(SetKeyResult.CONFLICT);
        assertThat(service.setKey("alice", "employee", KEY)).isEqualTo(SetKeyResult.CONFLICT);
        verifyNoInteractions(audit);
    }

    @Test
    void anyOtherIntegrityViolationIsRaisedNotAnsweredAsAConflict() {
        MemberRelationship row = relationship();
        holds(row);
        when(repository.existsByPublicKeyAndIdNot(KEY, row.getId())).thenReturn(false);
        DataIntegrityViolationException notNull = refusedBy("23502", 0);
        when(repository.updatePublicKey(row.getId(), KEY)).thenThrow(notNull);

        assertThatThrownBy(() -> service.setKey("alice", "employee", KEY)).isSameAs(notNull);
        verifyNoInteractions(audit);
    }

    @Test
    void aRowThatVanishedBetweenTheLookupAndTheWriteIsNotGrantedAndRecordsNothing() {
        MemberRelationship row = relationship();
        holds(row);
        when(repository.existsByPublicKeyAndIdNot(KEY, row.getId())).thenReturn(false);
        when(repository.updatePublicKey(row.getId(), KEY)).thenReturn(0);

        assertThat(service.setKey("alice", "employee", KEY)).isEqualTo(SetKeyResult.NOT_GRANTED);

        verifyNoInteractions(audit);
    }

    // -- clearKey (setKey with a null key) -----------------------------------------------------------

    @Test
    void clearingASetKeyRemovesItAndRecordsTheClearing() {
        // A null key is the member unsetting their own key to pause the relationship. The key comes off
        // the row, the grant stays, and the audit log gets key_cleared rather than key_registered.
        MemberRelationship row = relationship();
        row.setPublicKey(KEY);
        holds(row);
        when(repository.updatePublicKey(row.getId(), null)).thenReturn(1);

        assertThat(service.setKey("alice", "employee", null)).isEqualTo(SetKeyResult.SAVED);

        verify(repository).updatePublicKey(row.getId(), null);
        verify(audit).keyCleared("alice", "employee", NOW);
    }

    @Test
    void clearingARelationshipTheMemberDoesNotHoldIsNotGranted() {
        holds(null);

        assertThat(service.setKey("alice", "employee", null)).isEqualTo(SetKeyResult.NOT_GRANTED);

        verify(repository, never()).updatePublicKey(any(), any());
        verifyNoInteractions(audit);
    }

    @Test
    void clearingAnAlreadyEmptyKeyIsAQuietNoOp() {
        // Re-saving a field that is already blank must not write or record a hollow audit row: the outcome
        // is success, but nothing happened, so nothing is recorded.
        MemberRelationship row = relationship();
        holds(row);

        assertThat(service.setKey("alice", "employee", null)).isEqualTo(SetKeyResult.SAVED);

        verify(repository, never()).updatePublicKey(any(), any());
        verifyNoInteractions(audit);
    }

    @Test
    void clearingARowThatVanishedIsNotGrantedAndRecordsNothing() {
        MemberRelationship row = relationship();
        row.setPublicKey(KEY);
        holds(row);
        when(repository.updatePublicKey(row.getId(), null)).thenReturn(0);

        assertThat(service.setKey("alice", "employee", null)).isEqualTo(SetKeyResult.NOT_GRANTED);

        verifyNoInteractions(audit);
    }

    // -- The relationship type in the path --------------------------------------------------------------

    @Test
    void aTypeOutsideTheVocabularyIsNotGrantedWithoutAskingTheDatabase() {
        // EMPLOYEE is not employee. On an engine whose collation ignores case the lookup would have found
        // the row, so the vocabulary is checked first and the database is not consulted at all.
        assertThat(service.setKey("alice", "EMPLOYEE", KEY)).isEqualTo(SetKeyResult.NOT_GRANTED);
        assertThat(service.delete("alice", "EMPLOYEE")).isFalse();
        assertThat(service.selfRevoke("alice", "EMPLOYEE")).isFalse();
        assertThat(service.find("alice", "EMPLOYEE")).isEmpty();

        verify(repository, never()).findByIamSubjectIdAndRelationshipType(anyString(), anyString());
        verifyNoInteractions(audit, selfRevoke);
    }

    @Test
    void aRowTheEngineMatchedUnderALooserCollationIsNotTheOneAskedFor() {
        // SQL Server's default collation pads trailing spaces and MySQL's ignores case, so the lookup for
        // "employee" can hand back a row whose own type is spelt otherwise. It is compared exactly after the
        // lookup, so the row is not acted on, whatever the engine thought.
        when(repository.findByIamSubjectIdAndRelationshipType("alice", "employee"))
                .thenReturn(Optional.of(new MemberRelationship("alice", "employee ")));

        assertThat(service.setKey("alice", "employee", KEY)).isEqualTo(SetKeyResult.NOT_GRANTED);
        assertThat(service.delete("alice", "employee")).isFalse();
        assertThat(service.selfRevoke("alice", "employee")).isFalse();
        assertThat(service.find("alice", "employee")).isEmpty();

        verify(repository, never()).updatePublicKey(any(), any());
        verify(repository, never()).delete(any());
        verifyNoInteractions(audit, selfRevoke);
    }

    @Test
    void aRowWhoseSubjectTheEngineMatchedUnderALooserCollationIsNotTheMembers() {
        // The same collations match the subject "alice" to a row held by "alice " or "ALICE". The subject is
        // compared exactly after the lookup, as the type is, so another subject's row is never acted on.
        when(repository.findByIamSubjectIdAndRelationshipType("alice", "employee"))
                .thenReturn(Optional.of(new MemberRelationship("alice ", "employee")));

        assertThat(service.setKey("alice", "employee", KEY)).isEqualTo(SetKeyResult.NOT_GRANTED);
        assertThat(service.delete("alice", "employee")).isFalse();
        assertThat(service.selfRevoke("alice", "employee")).isFalse();
        assertThat(service.find("alice", "employee")).isEmpty();

        verify(repository, never()).updatePublicKey(any(), any());
        verify(repository, never()).delete(any());
        verifyNoInteractions(audit, selfRevoke);
    }

    // -- findAllHeldBy -------------------------------------------------------------------------------

    @Test
    void theRelationshipsListedAreOnlyThoseWhoseSubjectIsExactlyTheMembers() {
        MemberRelationship own = relationship();
        when(repository.findByIamSubjectIdOrderByRelationshipType("alice"))
                .thenReturn(List.of(
                        own, new MemberRelationship("alice ", "client"), new MemberRelationship("ALICE", "director")));

        assertThat(service.findAllHeldBy("alice")).containsExactly(own);
    }

    // -- delete --------------------------------------------------------------------------------------

    @Test
    void deletingARelationshipTheMemberDoesNotHoldReturnsFalse() {
        holds(null);

        assertThat(service.delete("alice", "employee")).isFalse();

        verify(repository, never()).delete(any());
        verifyNoInteractions(audit);
    }

    @Test
    void deletingAHeldRelationshipRemovesItAndRecordsTheEvent() {
        MemberRelationship row = relationship();
        holds(row);

        assertThat(service.delete("alice", "employee")).isTrue();

        verify(repository).delete(row);
        verify(audit).keyRemoved("alice", "employee", NOW);
    }

    // -- selfRevoke ----------------------------------------------------------------------------------

    @Test
    void selfRevokingARelationshipTheMemberDoesNotHoldReturnsFalseAndCallsNoProcedure() {
        holds(null);

        assertThat(service.selfRevoke("alice", "employee")).isFalse();

        verifyNoInteractions(selfRevoke);
    }

    @Test
    void selfRevokingAHeldRelationshipInvokesTheProcedure() {
        holds(relationship());

        assertThat(service.selfRevoke("alice", "employee")).isTrue();

        verify(selfRevoke).invoke("alice", "employee");
    }

    // -- attest --------------------------------------------------------------------------------------

    @Test
    void attestReturnsTheRelationshipTheKeyBelongsTo() {
        MemberRelationship held = relationship();
        when(repository.findByPublicKey(KEY)).thenReturn(Optional.of(held));

        assertThat(service.attest(KEY)).containsSame(held);
    }

    @Test
    void attestReturnsEmptyForAKeyNoOneHolds() {
        when(repository.findByPublicKey(KEY)).thenReturn(Optional.empty());

        assertThat(service.attest(KEY)).isEmpty();
    }
}
