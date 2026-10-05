<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contract\RelationshipTypeCatalog;
use App\Models\MemberRelationship;
use App\Services\AuditLog;
use App\Services\KeyConflictException;
use App\Services\RelationshipService;
use App\Services\RelationshipStore;
use App\Services\TransactionRunner;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The write path's decisions, isolated from the database and the HTTP layer.
 *
 * The conformance suite drives these through a real engine; this pins the reasoning the service owns: a
 * relationship the organisation never granted is refused rather than created, a key another relationship
 * holds is a conflict whether the up-front check or the unique index catches it, and self-revoke is a 404
 * for a relationship the caller does not hold rather than the procedure's silent no-op. The store, the
 * transaction and the audit log are hand-written fakes -- no database, no mocking framework -- so a change
 * in the reasoning shows up here rather than only under Postgres.
 */
final class RelationshipServiceTest extends TestCase
{
    // A 32-character stand-in. Its value is irrelevant: the fake store never inspects the key.
    private const KEY = 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk';

    private FakeRelationshipStore $store;

    private RecordingAuditLog $audit;

    protected function setUp(): void
    {
        $this->store = new FakeRelationshipStore;
        $this->audit = new RecordingAuditLog;
    }

    private function service(): RelationshipService
    {
        // The real vocabulary, from the synced contract file, so "employee" is governed here exactly as it
        // is in production and a made-up type is not.
        $catalog = new RelationshipTypeCatalog(dirname(__DIR__, 2).'/contract/relationship-types.json');

        return new RelationshipService($this->store, new ImmediateTransactionRunner, $this->audit, $catalog);
    }

    private static function row(int $id, string $type = 'employee', string $subject = 'alice'): MemberRelationship
    {
        $row = new MemberRelationship;
        $row->id = $id;
        $row->iam_subject_id = $subject;
        $row->relationship_type = $type;

        return $row;
    }

    /** A row that already carries a key, so the clear path has something to remove. */
    private static function keyedRow(int $id): MemberRelationship
    {
        $row = self::row($id);
        // The model reads public_key from the hex attribute the query selects; set that so public_key is
        // non-null without touching the database.
        $row->public_key_hex = bin2hex(self::KEY);

        return $row;
    }

    // -- setKey --------------------------------------------------------------------------------------

    #[Test]
    public function setting_a_key_on_a_relationship_that_was_never_granted_is_refused_not_created(): void
    {
        $this->store->own = null;

        self::assertSame(RelationshipService::NOT_GRANTED, $this->service()->setKey('alice', 'employee', self::KEY));
        self::assertFalse($this->store->updated);
        self::assertSame([], $this->audit->events);
    }

    #[Test]
    public function setting_a_key_another_relationship_already_holds_is_a_conflict(): void
    {
        $this->store->own = self::row(1);
        $this->store->keyHeldElsewhere = true;

        self::assertSame(RelationshipService::CONFLICT, $this->service()->setKey('alice', 'employee', self::KEY));
        self::assertFalse($this->store->updated);
    }

    #[Test]
    public function losing_the_unique_index_race_on_write_is_the_same_conflict(): void
    {
        // The up-front check passed, but another request took the key first: the index rejects the write,
        // and the outcome is a conflict all the same.
        $this->store->own = self::row(1);
        $this->store->keyHeldElsewhere = false;
        $this->store->updateThrowsConflict = true;

        self::assertSame(RelationshipService::CONFLICT, $this->service()->setKey('alice', 'employee', self::KEY));
    }

    #[Test]
    public function a_saved_key_writes_the_registration_to_the_audit_log(): void
    {
        $this->store->own = self::row(1);

        self::assertSame(RelationshipService::SAVED, $this->service()->setKey('alice', 'employee', self::KEY));
        self::assertTrue($this->store->updated);
        self::assertSame(['key_registered:alice:employee'], $this->audit->events);
    }

    #[Test]
    public function a_row_that_vanished_between_the_lookup_and_the_write_is_not_granted_and_leaves_no_audit_entry(): void
    {
        // The lookup found the row and the update touched nothing: the organisation removed it in between.
        // Not granted, not a conflict and not a saved key, and the log must not claim a registration that
        // did not happen.
        $this->store->own = self::row(1);
        $this->store->updateCount = 0;

        self::assertSame(RelationshipService::NOT_GRANTED, $this->service()->setKey('alice', 'employee', self::KEY));
        self::assertSame([], $this->audit->events);
    }

    #[Test]
    public function a_path_type_that_differs_from_the_rows_type_only_in_case_is_not_granted(): void
    {
        // What the engine's collation may do: MySQL and SQL Server match EMPLOYEE to an employee row
        // unless the column says otherwise. The service compares after the lookup, character for
        // character, so the answer is the same on every engine: nothing of that type was granted.
        $this->store->own = self::row(1, 'employee');

        self::assertSame(RelationshipService::NOT_GRANTED, $this->service()->setKey('alice', 'EMPLOYEE', self::KEY));
        self::assertSame(RelationshipService::NOT_GRANTED, $this->service()->setKey('alice', 'EMPLOYEE', null));
        self::assertFalse($this->service()->delete('alice', 'EMPLOYEE'));
        self::assertFalse($this->service()->selfRevoke('alice', 'EMPLOYEE'));
        self::assertNull($this->service()->find('alice', 'EMPLOYEE'));
        self::assertFalse($this->store->updated);
        self::assertSame(0, $this->store->selfRevokeInvocations);
        self::assertSame([], $this->audit->events);
    }

    #[Test]
    public function a_row_whose_subject_differs_from_the_callers_only_in_trailing_spaces_is_not_held(): void
    {
        // What MySQL and SQL Server do: they ignore trailing spaces when they compare, so a lookup for
        // "alice" can return the row of "alice ". The service compares the subject after the lookup,
        // character for character, as PostgreSQL would, so the answer is the same on every engine.
        $this->store->own = self::row(1, 'employee', 'alice ');
        $this->store->deleteCount = 1;

        self::assertSame(RelationshipService::NOT_GRANTED, $this->service()->setKey('alice', 'employee', self::KEY));
        self::assertSame(RelationshipService::NOT_GRANTED, $this->service()->setKey('alice', 'employee', null));
        self::assertFalse($this->service()->delete('alice', 'employee'));
        self::assertFalse($this->service()->selfRevoke('alice', 'employee'));
        self::assertNull($this->service()->find('alice', 'employee'));
        self::assertFalse($this->store->updated);
        self::assertSame([], $this->store->deletedWith);
        self::assertSame(0, $this->store->selfRevokeInvocations);
        self::assertSame([], $this->audit->events);
    }

    #[Test]
    public function the_relationships_listed_are_only_those_whose_subject_is_exactly_the_callers(): void
    {
        $this->store->all = [self::row(1, 'client'), self::row(2, 'employee', 'alice '), self::row(3, 'student', 'ALICE')];

        $held = $this->service()->findAllHeldBy('alice');

        self::assertSame([1], $held->pluck('id')->all());
    }

    #[Test]
    public function a_type_outside_the_governed_vocabulary_is_not_granted_without_a_lookup(): void
    {
        // Nothing of a type the vocabulary does not know was ever granted, so the store is not asked. The
        // vocabulary is read through the catalogue, the one copy of relationship-types.json.
        $this->store->own = self::row(1, 'astronaut');

        self::assertSame(RelationshipService::NOT_GRANTED, $this->service()->setKey('alice', 'astronaut', self::KEY));
        self::assertFalse($this->service()->delete('alice', 'astronaut'));
        self::assertFalse($this->service()->selfRevoke('alice', 'astronaut'));
        self::assertSame(0, $this->store->finds);
        self::assertSame([], $this->audit->events);
    }

    #[Test]
    public function the_audit_log_and_the_procedure_receive_the_rows_type(): void
    {
        // The type recorded is the row's own, read back from the store, never the path value as typed.
        // With the exact comparison above the two are equal, and this pins which one is handed on.
        $this->store->own = self::row(1, 'client');
        $this->store->deleteCount = 1;

        $this->service()->setKey('alice', 'client', self::KEY);
        $this->service()->delete('alice', 'client');
        $this->service()->selfRevoke('alice', 'client');

        self::assertSame(['key_registered:alice:client', 'key_removed:alice:client'], $this->audit->events);
        self::assertSame(['alice', 'client'], $this->store->selfRevokedWith);
        self::assertSame(['alice', 'client'], $this->store->deletedWith);
    }

    // -- clearKey ------------------------------------------------------------------------------------

    #[Test]
    public function clearing_a_set_key_removes_it_and_records_the_clearing(): void
    {
        // A null key is the member unsetting their own key to pause the relationship. The row keeps its
        // grant, the key is cleared, and the audit log gets key_cleared rather than key_registered.
        $this->store->own = self::keyedRow(1);

        self::assertSame(RelationshipService::SAVED, $this->service()->setKey('alice', 'employee', null));
        self::assertTrue($this->store->cleared);
        self::assertFalse($this->store->updated);
        self::assertSame(['key_cleared:alice:employee'], $this->audit->events);
    }

    #[Test]
    public function clearing_a_relationship_that_was_never_granted_is_refused(): void
    {
        $this->store->own = null;

        self::assertSame(RelationshipService::NOT_GRANTED, $this->service()->setKey('alice', 'employee', null));
        self::assertFalse($this->store->cleared);
        self::assertSame([], $this->audit->events);
    }

    #[Test]
    public function clearing_an_already_empty_key_is_a_quiet_no_op(): void
    {
        // Re-saving a field that is already blank must not clear again or write a hollow audit row: the
        // outcome is success, but nothing happened, so nothing is recorded.
        $this->store->own = self::row(1);

        self::assertSame(RelationshipService::SAVED, $this->service()->setKey('alice', 'employee', null));
        self::assertFalse($this->store->cleared);
        self::assertSame([], $this->audit->events);
    }

    // -- delete --------------------------------------------------------------------------------------

    #[Test]
    public function deleting_a_held_relationship_removes_it_and_records_the_removal(): void
    {
        $this->store->own = self::row(1);
        $this->store->deleteCount = 1;

        self::assertTrue($this->service()->delete('alice', 'employee'));
        self::assertSame(['key_removed:alice:employee'], $this->audit->events);
    }

    #[Test]
    public function deleting_a_relationship_that_is_not_held_is_a_no(): void
    {
        $this->store->own = null;

        self::assertFalse($this->service()->delete('alice', 'employee'));
        self::assertSame([], $this->audit->events);
    }

    #[Test]
    public function deleting_a_row_that_vanished_between_the_lookup_and_the_delete_is_a_no_with_no_audit_entry(): void
    {
        $this->store->own = self::row(1);
        $this->store->deleteCount = 0;

        self::assertFalse($this->service()->delete('alice', 'employee'));
        self::assertSame([], $this->audit->events);
    }

    // -- selfRevoke ----------------------------------------------------------------------------------

    #[Test]
    public function self_revoking_a_held_relationship_invokes_the_procedure(): void
    {
        $this->store->own = self::row(1);

        self::assertTrue($this->service()->selfRevoke('alice', 'employee'));
        self::assertSame(1, $this->store->selfRevokeInvocations);
    }

    #[Test]
    public function self_revoking_a_relationship_that_is_not_held_touches_the_procedure_not_at_all(): void
    {
        // A 404, not the procedure's silent no-op: it cannot tell "not granted" from "already revoked", so
        // the service must not call it for a relationship the caller does not hold.
        $this->store->own = null;

        self::assertFalse($this->service()->selfRevoke('alice', 'employee'));
        self::assertSame(0, $this->store->selfRevokeInvocations);
    }

    // -- attest / me ---------------------------------------------------------------------------------

    #[Test]
    public function attesting_returns_whatever_relationship_holds_the_key(): void
    {
        $onRecord = self::row(1);
        $this->store->byPublicKey = $onRecord;

        self::assertSame($onRecord, $this->service()->attest(self::KEY));
    }

    #[Test]
    public function attesting_a_key_on_record_for_nobody_returns_nothing(): void
    {
        $this->store->byPublicKey = null;

        self::assertNull($this->service()->attest(self::KEY));
    }
}

/** Runs the operation directly -- the commit/rollback is the store's concern here. */
final class ImmediateTransactionRunner implements TransactionRunner
{
    public function run(Closure $operation): mixed
    {
        return $operation();
    }
}

final class RecordingAuditLog implements AuditLog
{
    /** @var list<string> */
    public array $events = [];

    public function keyRegistered(string $iamSubjectId, string $relationshipType): void
    {
        $this->events[] = "key_registered:{$iamSubjectId}:{$relationshipType}";
    }

    public function keyCleared(string $iamSubjectId, string $relationshipType): void
    {
        $this->events[] = "key_cleared:{$iamSubjectId}:{$relationshipType}";
    }

    public function keyRemoved(string $iamSubjectId, string $relationshipType): void
    {
        $this->events[] = "key_removed:{$iamSubjectId}:{$relationshipType}";
    }
}

final class FakeRelationshipStore implements RelationshipStore
{
    public ?MemberRelationship $own = null;

    public ?MemberRelationship $byPublicKey = null;

    public bool $keyHeldElsewhere = false;

    public bool $updateThrowsConflict = false;

    public int $deleteCount = 0;

    public int $updateCount = 1;

    public int $clearCount = 1;

    public bool $updated = false;

    public bool $cleared = false;

    public int $finds = 0;

    public int $selfRevokeInvocations = 0;

    /** @var list<string> the subject and type the procedure was last invoked with */
    public array $selfRevokedWith = [];

    /** @var list<string> the subject and type the delete was last issued with */
    public array $deletedWith = [];

    /** @var list<MemberRelationship> */
    public array $all = [];

    public function findAllHeldBy(string $iamSubjectId): Collection
    {
        return new Collection($this->all);
    }

    public function find(string $iamSubjectId, string $relationshipType): ?MemberRelationship
    {
        $this->finds++;

        return $this->own;
    }

    public function findByPublicKey(string $rawPublicKey): ?MemberRelationship
    {
        return $this->byPublicKey;
    }

    public function isKeyHeldElsewhere(string $rawPublicKey, int $exceptId): bool
    {
        return $this->keyHeldElsewhere;
    }

    public function updateKey(int $id, string $rawPublicKey): int
    {
        if ($this->updateThrowsConflict) {
            throw new KeyConflictException;
        }

        $this->updated = $this->updateCount > 0;

        return $this->updateCount;
    }

    public function clearKey(int $id): int
    {
        $this->cleared = $this->clearCount > 0;

        return $this->clearCount;
    }

    public function deleteOwn(string $iamSubjectId, string $relationshipType): int
    {
        $this->deletedWith = [$iamSubjectId, $relationshipType];

        return $this->deleteCount;
    }

    public function invokeSelfRevoke(string $iamSubjectId, string $relationshipType): void
    {
        $this->selfRevokeInvocations++;
        $this->selfRevokedWith = [$iamSubjectId, $relationshipType];
    }
}
