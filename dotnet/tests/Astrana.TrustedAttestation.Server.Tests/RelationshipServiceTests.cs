using Astrana.TrustedAttestation.Server.Contract;
using Astrana.TrustedAttestation.Server.Data;
using Astrana.TrustedAttestation.Server.Services;
using Astrana.TrustedAttestation.Server.Tests.Support;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The write path's decisions, isolated from the database and the HTTP layer.
///
/// The conformance suite drives these through a real engine; this pins the reasoning the service owns:
/// a grant that was never made is refused rather than created, a key another relationship holds is a
/// conflict whether the up-front check or the unique index catches it, and a stored key never lifts a
/// revocation. The store, the transaction and the two commands are hand-written fakes -- no database, no
/// mocking framework -- so a change in the reasoning shows up here rather than only under Postgres.
/// </summary>
public class RelationshipServiceTests
{
    private static readonly byte[] Key = MakeKey();

    private static byte[] MakeKey()
    {
        var key = new byte[32];
        key[0] = 7; // non-zero: the all-zero key is refused before it reaches the service
        return key;
    }

    private readonly FakeRelationshipStore _store = new();
    private readonly RecordingAuditWriter _audit = new();
    private readonly RecordingSelfRevoke _selfRevoke = new();

    private RelationshipService Service() =>
        new(_store, new ImmediateTransactionRunner(), _audit, _selfRevoke, RelationshipTypeCatalog.Load());

    // -- The type in the path -----------------------------------------------------------------------

    [Fact]
    public async Task A_type_outside_the_vocabulary_is_not_granted_and_is_never_looked_up()
    {
        // The vocabulary is the first gate. A value that is not in it cannot name a row, so the store is
        // not asked, and the answer is the same 404 an ungranted relationship gets.
        _store.Own = new MemberRelationship { IamSubjectId = "alice", RelationshipType = "patient", Id = 1 };

        var outcome = await Service().SetKeyAsync("alice", "patient", Key, default);

        Assert.Equal(SetKeyStatus.NotGranted, outcome.Status);
        Assert.Equal(0, _store.Lookups);
        Assert.False(await Service().DeleteAsync("alice", "patient", default));
        Assert.False(await Service().SelfRevokeAsync("alice", "patient", default));
        Assert.Empty(_audit.Events);
        Assert.Equal(0, _selfRevoke.Invocations);
    }

    [Theory]
    [InlineData("EMPLOYEE")]
    [InlineData("Employee")]
    public async Task A_type_that_matches_the_row_only_under_a_folding_collation_is_not_granted(string pathType)
    {
        // A case-insensitive collation finds the "employee" row for a path saying "EMPLOYEE". The row is
        // compared to the path ordinally afterwards, so the answer is a 404 on every engine alike, and no
        // write and no audit entry names the path's spelling.
        _store.Own = new MemberRelationship { IamSubjectId = "alice", RelationshipType = "employee", Id = 1, PublicKey = Key };

        var outcome = await Service().SetKeyAsync("alice", pathType, Key, default);

        Assert.Equal(SetKeyStatus.NotGranted, outcome.Status);
        Assert.False(await Service().DeleteAsync("alice", pathType, default));
        Assert.False(await Service().SelfRevokeAsync("alice", pathType, default));
        Assert.False(_store.Saved);
        Assert.False(_store.Removed);
        Assert.Empty(_audit.Events);
        Assert.Equal(0, _selfRevoke.Invocations);
    }

    // -- The subject from the session -----------------------------------------------------------------

    [Theory]
    [InlineData("alice ")]
    [InlineData("ALICE")]
    public async Task A_subject_that_matches_the_row_only_under_a_lenient_comparison_is_not_granted(string sessionSubject)
    {
        // MySQL and SQL Server ignore trailing spaces, and a case-insensitive collation folds case, so the
        // lookup can return alice's row for a session whose subject is not alice. The row is compared to the
        // session ordinally afterwards, so the answer is a 404 on every engine alike and nothing is written.
        _store.Own = new MemberRelationship { IamSubjectId = "alice", RelationshipType = "employee", Id = 1, PublicKey = Key };

        var outcome = await Service().SetKeyAsync(sessionSubject, "employee", null, default);

        Assert.Equal(SetKeyStatus.NotGranted, outcome.Status);
        Assert.False(await Service().DeleteAsync(sessionSubject, "employee", default));
        Assert.False(await Service().SelfRevokeAsync(sessionSubject, "employee", default));
        Assert.False(_store.Saved);
        Assert.False(_store.Removed);
        Assert.Empty(_audit.Events);
        Assert.Equal(0, _selfRevoke.Invocations);
    }

    [Fact]
    public async Task Listing_keeps_only_the_rows_whose_subject_is_exactly_the_sessions()
    {
        _store.Held = [new MemberRelationship { IamSubjectId = "alice", RelationshipType = "employee", Id = 1 }];

        Assert.Empty(await Service().FindAllHeldByAsync("alice ", default));
        Assert.Single(await Service().FindAllHeldByAsync("alice", default));
    }

    [Fact]
    public async Task Every_write_names_the_rows_own_type_to_the_audit_log_and_the_procedure()
    {
        _store.Own = new MemberRelationship { IamSubjectId = "alice", RelationshipType = "employee", Id = 1, PublicKey = Key };

        await Service().SetKeyAsync("alice", "employee", null, default);
        await Service().DeleteAsync("alice", "employee", default);
        await Service().SelfRevokeAsync("alice", "employee", default);

        Assert.All(_audit.RelationshipTypes, type => Assert.Equal("employee", type));
        Assert.Equal(2, _audit.RelationshipTypes.Count);
        Assert.Equal("employee", _selfRevoke.RelationshipType);
    }

    // -- A row that vanished between the lookup and the write --------------------------------------

    [Fact]
    public async Task A_key_write_whose_row_has_gone_is_not_granted()
    {
        // The lookup found the row, the organisation deleted it before the save, and the save touched
        // nothing. The answer is what the lookup would now give, a 404, never a 409 and never a 500.
        _store.Own = new MemberRelationship { IamSubjectId = "alice", RelationshipType = "employee", Id = 1 };
        _store.SaveFindsTheRowGone = true;

        var outcome = await Service().SetKeyAsync("alice", "employee", Key, default);

        Assert.Equal(SetKeyStatus.NotGranted, outcome.Status);
        Assert.Null(outcome.Row);
    }

    [Fact]
    public async Task A_delete_whose_row_has_gone_is_a_no()
    {
        _store.Own = new MemberRelationship { IamSubjectId = "alice", RelationshipType = "employee", Id = 1 };
        _store.SaveFindsTheRowGone = true;

        Assert.False(await Service().DeleteAsync("alice", "employee", default));
    }

    // -- SetKey --------------------------------------------------------------------------------------

    [Fact]
    public async Task Setting_a_key_on_a_relationship_that_was_never_granted_is_refused_not_created()
    {
        _store.Own = null;

        var outcome = await Service().SetKeyAsync("alice", "employee", Key, default);

        Assert.Equal(SetKeyStatus.NotGranted, outcome.Status);
        Assert.False(_store.Saved);
        Assert.Empty(_audit.Events);
    }

    [Fact]
    public async Task Setting_a_key_another_relationship_already_holds_is_a_conflict()
    {
        _store.Own = new MemberRelationship { IamSubjectId = "alice", RelationshipType = "employee", Id = 1 };
        _store.KeyHeldElsewhere = true;

        var outcome = await Service().SetKeyAsync("alice", "employee", Key, default);

        Assert.Equal(SetKeyStatus.Conflict, outcome.Status);
        Assert.False(_store.Saved);
    }

    [Fact]
    public async Task Losing_the_unique_index_race_on_save_is_the_same_conflict()
    {
        // The up-front check passed, but another request took the key first: the index rejects the save,
        // and the outcome is a conflict all the same.
        _store.Own = new MemberRelationship { IamSubjectId = "alice", RelationshipType = "employee", Id = 1 };
        _store.KeyHeldElsewhere = false;
        _store.SaveLosesTheRace = true;

        var outcome = await Service().SetKeyAsync("alice", "employee", Key, default);

        Assert.Equal(SetKeyStatus.Conflict, outcome.Status);
    }

    [Fact]
    public async Task A_saved_key_carries_the_row_and_writes_the_registration_to_the_audit_log()
    {
        _store.Own = new MemberRelationship { IamSubjectId = "alice", RelationshipType = "employee", Id = 1 };

        var outcome = await Service().SetKeyAsync("alice", "employee", Key, default);

        Assert.Equal(SetKeyStatus.Saved, outcome.Status);
        Assert.Equal(Key, outcome.Row!.PublicKey);
        Assert.True(_store.Saved);
        Assert.Equal([AuditEvent.KeyRegistered], _audit.Events);
    }

    [Fact]
    public async Task Registering_a_key_never_lifts_a_revocation()
    {
        // A member registering a key again on a revoked relationship gets their key stored -- the row keeps its
        // RevokedAt, so the status the handler reports still says revoked. Registering is not an appeal.
        var revokedAt = new DateTime(2026, 1, 1, 0, 0, 0, DateTimeKind.Utc);
        _store.Own = new MemberRelationship
        {
            IamSubjectId = "alice",
            RelationshipType = "employee",
            Id = 1,
            RevokedAt = revokedAt,
        };

        var outcome = await Service().SetKeyAsync("alice", "employee", Key, default);

        Assert.Equal(SetKeyStatus.Saved, outcome.Status);
        Assert.Equal(revokedAt, outcome.Row!.RevokedAt);
    }

    // -- ClearKey ------------------------------------------------------------------------------------

    [Fact]
    public async Task Clearing_a_set_key_nulls_it_saves_and_records_the_clearing()
    {
        // A null key is the member unsetting their own key to pause the relationship. The row keeps its
        // grant, the key is removed, and the audit log gets key_cleared rather than key_registered.
        _store.Own = new MemberRelationship
        {
            IamSubjectId = "alice",
            RelationshipType = "employee",
            Id = 1,
            PublicKey = Key,
        };

        var outcome = await Service().SetKeyAsync("alice", "employee", null, default);

        Assert.Equal(SetKeyStatus.Saved, outcome.Status);
        Assert.Null(outcome.Row!.PublicKey);
        Assert.True(_store.Saved);
        Assert.Equal([AuditEvent.KeyCleared], _audit.Events);
    }

    [Fact]
    public async Task Clearing_a_relationship_that_was_never_granted_is_refused()
    {
        _store.Own = null;

        var outcome = await Service().SetKeyAsync("alice", "employee", null, default);

        Assert.Equal(SetKeyStatus.NotGranted, outcome.Status);
        Assert.False(_store.Saved);
        Assert.Empty(_audit.Events);
    }

    [Fact]
    public async Task Clearing_an_already_empty_key_is_a_quiet_no_op()
    {
        // Re-saving a field that is already blank must not write a hollow audit row or touch the store: the
        // outcome is success, but nothing happened, so nothing is recorded.
        _store.Own = new MemberRelationship
        {
            IamSubjectId = "alice",
            RelationshipType = "employee",
            Id = 1,
            PublicKey = null,
        };

        var outcome = await Service().SetKeyAsync("alice", "employee", null, default);

        Assert.Equal(SetKeyStatus.Saved, outcome.Status);
        Assert.Null(outcome.Row!.PublicKey);
        Assert.False(_store.Saved);
        Assert.Empty(_audit.Events);
    }

    [Fact]
    public async Task Clearing_a_key_never_lifts_a_revocation()
    {
        // Clearing, like registering, leaves RevokedAt alone: an organisation's revocation is not something
        // the member can shed by emptying the field, so the row still reports revoked afterwards.
        var revokedAt = new DateTime(2026, 1, 1, 0, 0, 0, DateTimeKind.Utc);
        _store.Own = new MemberRelationship
        {
            IamSubjectId = "alice",
            RelationshipType = "employee",
            Id = 1,
            PublicKey = Key,
            RevokedAt = revokedAt,
        };

        var outcome = await Service().SetKeyAsync("alice", "employee", null, default);

        Assert.Equal(SetKeyStatus.Saved, outcome.Status);
        Assert.Equal(revokedAt, outcome.Row!.RevokedAt);
        Assert.Equal(RelationshipStatus.Revoked, outcome.Row.StatusAt(DateTime.UtcNow));
    }

    // -- Delete --------------------------------------------------------------------------------------

    [Fact]
    public async Task Deleting_a_held_relationship_removes_it_and_records_the_removal()
    {
        _store.Own = new MemberRelationship { IamSubjectId = "alice", RelationshipType = "employee", Id = 1 };

        var deleted = await Service().DeleteAsync("alice", "employee", default);

        Assert.True(deleted);
        Assert.True(_store.Removed);
        Assert.Equal([AuditEvent.KeyRemoved], _audit.Events);
    }

    [Fact]
    public async Task Deleting_a_relationship_that_is_not_held_is_a_no()
    {
        _store.Own = null;

        var deleted = await Service().DeleteAsync("alice", "employee", default);

        Assert.False(deleted);
        Assert.False(_store.Removed);
        Assert.Empty(_audit.Events);
    }

    // -- SelfRevoke ----------------------------------------------------------------------------------

    [Fact]
    public async Task Self_revoking_a_held_relationship_invokes_the_procedure()
    {
        _store.Own = new MemberRelationship { IamSubjectId = "alice", RelationshipType = "employee", Id = 1 };

        var revoked = await Service().SelfRevokeAsync("alice", "employee", default);

        Assert.True(revoked);
        Assert.Equal(1, _selfRevoke.Invocations);
    }

    [Fact]
    public async Task Self_revoking_a_relationship_that_is_not_held_touches_the_procedure_not_at_all()
    {
        // A 404, not the procedure's silent no-op: the procedure cannot tell "not granted" from "already
        // revoked", so the service must not call it for a relationship the caller does not hold.
        _store.Own = null;

        var revoked = await Service().SelfRevokeAsync("alice", "employee", default);

        Assert.False(revoked);
        Assert.Equal(0, _selfRevoke.Invocations);
    }

    // -- Attest --------------------------------------------------------------------------------------

    [Fact]
    public async Task Attesting_returns_whatever_relationship_holds_the_key()
    {
        var onRecord = new MemberRelationship { IamSubjectId = "alice", RelationshipType = "employee", Id = 1 };
        _store.ByPublicKey = onRecord;

        Assert.Same(onRecord, await Service().AttestAsync(Key, default));
    }

    [Fact]
    public async Task Attesting_a_key_on_record_for_nobody_returns_nothing()
    {
        _store.ByPublicKey = null;

        Assert.Null(await Service().AttestAsync(Key, default));
    }
}
