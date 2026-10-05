<?php

declare(strict_types=1);

namespace App\Services;

use App\Contract\RelationshipTypeCatalog;
use App\Models\MemberRelationship;
use Illuminate\Database\Eloquent\Collection;

/**
 * The four operations' decisions, lifted off the database.
 *
 * A relationship the organisation never granted is NOT_GRANTED -- answered 404, never created, which is
 * what stops a member self-granting a type nobody vouched for. A key another relationship already holds is
 * CONFLICT, whether the up-front check or the unique index catches it. A stored key still reports a
 * standing that may be revoked, because registering a key is not an appeal. Each write is one transaction
 * covering both the member_relationships change and the audit_log insert, so there is no path where the
 * change happens but the log entry does not.
 *
 * The relationship type in the path has to be in the governed vocabulary and equal the row's type exactly,
 * character for character. The engine's collation may match "EMPLOYEE" to a row of "employee" (MySQL and
 * SQL Server compare case-insensitively unless the column says otherwise), and the three implementations
 * must not answer that differently on different engines, so the comparison is made here, after the lookup,
 * and the audit writer is handed the row's type, never the path's. The subject identifier is compared the
 * same way after every lookup by subject, because MySQL and SQL Server also ignore trailing spaces, so a
 * lookup for "alice" can return the row of "alice ", which PostgreSQL never would.
 *
 * None of that reasoning touches Eloquent or a transaction directly: it runs over a RelationshipStore and
 * a TransactionRunner, so it can be exercised against hand-written fakes with no database. The Eloquent
 * store and the DB::transaction runner -- what actually reaches Postgres, MySQL or SQL Server -- are
 * exercised by the conformance suite instead.
 */
final class RelationshipService
{
    public const SAVED = 'saved';

    /** This key already belongs to a different relationship -- anyone's, including the member's own. */
    public const CONFLICT = 'conflict';

    /** The organisation has not granted the caller this relationship. Answered 404, never created. */
    public const NOT_GRANTED = 'not_granted';

    public function __construct(
        private readonly RelationshipStore $store,
        private readonly TransactionRunner $transaction,
        private readonly AuditLog $audit,
        private readonly RelationshipTypeCatalog $catalog,
    ) {}

    /**
     * Everything the member holds, in a stable order. An empty collection is an ordinary answer.
     *
     * @return Collection<int, MemberRelationship>
     */
    public function findAllHeldBy(string $iamSubjectId): Collection
    {
        return $this->store->findAllHeldBy($iamSubjectId)
            ->filter(fn (MemberRelationship $row): bool => $row->iam_subject_id === $iamSubjectId)
            ->values();
    }

    /** One specific relationship of the caller's own -- the re-read the key PUT answers its status from. */
    public function find(string $iamSubjectId, string $relationshipType): ?MemberRelationship
    {
        return $this->held($iamSubjectId, $relationshipType);
    }

    /**
     * A point lookup: one key in, the one relationship it belongs to out, whatever its standing. There is
     * no operation to list or enumerate keys, which is what makes anonymous access to this safe. Nothing is
     * written -- a verification is not an audit event, and logging checks would tell the organisation who
     * is asking.
     */
    public function attest(string $rawPublicKey): ?MemberRelationship
    {
        return $this->store->findByPublicKey($rawPublicKey);
    }

    /**
     * Registers or replaces the key for one relationship, and nothing else the member holds.
     *
     * Never creates: a relationship that is not there is NOT_GRANTED, because a PUT that could create would
     * let any member self-grant any type they liked with no organisation involvement at all. A row that
     * vanished between the lookup and the write is NOT_GRANTED too, and writes no audit entry.
     *
     * A null $rawPublicKey clears the key instead of setting one: the member unsetting their own key to
     * pause the relationship without giving up the grant, so an empty key field saved from the page returns
     * them to the same "waiting for your key" state as before their first save. Being the member's own act,
     * it can be undone by simply saving a key again -- unlike an organisation revocation. Clearing takes no
     * conflict check (there is no key to collide with) and, like registering, leaves revoked_at and
     * expires_at alone, so a revoked relationship still reports revoked afterwards. It is audited only when
     * a key was actually removed, so re-saving an already-empty field records nothing.
     */
    public function setKey(string $iamSubjectId, string $relationshipType, ?string $rawPublicKey): string
    {
        try {
            return $this->transaction->run(function () use ($iamSubjectId, $relationshipType, $rawPublicKey): string {
                $row = $this->held($iamSubjectId, $relationshipType);
                if ($row === null) {
                    return self::NOT_GRANTED;
                }

                return $rawPublicKey === null
                    ? $this->clearHeldKey($iamSubjectId, $row)
                    : $this->registerKey($iamSubjectId, $row, $rawPublicKey);
            });
        } catch (KeyConflictException) {
            // Lost the race on the unique index -- the same answer the up-front check gives.
            return self::CONFLICT;
        }
    }

    /**
     * Clears the key on a row the member holds. Audited only when a key was actually removed, so re-saving
     * an already-empty field is a quiet no-op rather than a hollow log entry.
     */
    private function clearHeldKey(string $iamSubjectId, MemberRelationship $row): string
    {
        if ($row->public_key === null) {
            return self::SAVED;
        }

        if ($this->store->clearKey($row->id) === 0) {
            return self::NOT_GRANTED;
        }

        $this->audit->keyCleared($iamSubjectId, $row->relationship_type);

        return self::SAVED;
    }

    /** Registers or replaces the key on a row the member holds, unless another relationship holds that key. */
    private function registerKey(string $iamSubjectId, MemberRelationship $row, string $rawPublicKey): string
    {
        if ($this->store->isKeyHeldElsewhere($rawPublicKey, $row->id)) {
            return self::CONFLICT;
        }

        if ($this->store->updateKey($row->id, $rawPublicKey) === 0) {
            return self::NOT_GRANTED;
        }

        $this->audit->keyRegistered($iamSubjectId, $row->relationship_type);

        return self::SAVED;
    }

    /**
     * A hard delete of this one relationship, not a soft revoke and not a return to unkeyed: a member
     * exercising this themselves is closer to a right-to-erasure action than an organisation-initiated
     * status change. Works whether the relationship is keyed or not; the member's other relationships are
     * untouched, and so is the audit trail, which records that events happened rather than the key itself.
     * A row that vanished between the lookup and the delete is a no, with no audit entry.
     */
    public function delete(string $iamSubjectId, string $relationshipType): bool
    {
        return $this->transaction->run(function () use ($iamSubjectId, $relationshipType): bool {
            $row = $this->held($iamSubjectId, $relationshipType);
            if ($row === null || $this->store->deleteOwn($iamSubjectId, $row->relationship_type) === 0) {
                return false;
            }

            $this->audit->keyRemoved($iamSubjectId, $row->relationship_type);

            return true;
        });
    }

    /**
     * Member-initiated revoke: one-directional and idempotent.
     *
     * Returns false for a relationship the member does not hold, so the caller can answer 404 rather than
     * the procedure's silent success -- it cannot tell "not granted" from "already revoked", and both are
     * no-ops to it. Deliberately not wrapped in a transaction of ours: the procedure opens and commits its
     * own, which MySQL and SQL Server both need, and a procedure that commits inside an outer transaction
     * ends it -- Laravel's own commit then fails for a revoke that actually succeeded. Nothing is lost, as
     * the procedure is a single atomic call that writes its own audit row, with the row's type.
     */
    public function selfRevoke(string $iamSubjectId, string $relationshipType): bool
    {
        $row = $this->held($iamSubjectId, $relationshipType);
        if ($row === null) {
            return false;
        }

        $this->store->invokeSelfRevoke($iamSubjectId, $row->relationship_type);

        return true;
    }

    /**
     * The caller's own relationship of exactly this type, or null. Null for a type outside the governed
     * vocabulary without a lookup, since nothing of that type was ever granted, and null for a row the
     * engine matched loosely but whose subject or type is not the one asked for, character for character.
     */
    private function held(string $iamSubjectId, string $relationshipType): ?MemberRelationship
    {
        if (! $this->catalog->isGoverned($relationshipType)) {
            return null;
        }

        $row = $this->store->find($iamSubjectId, $relationshipType);

        return $row !== null
            && $row->iam_subject_id === $iamSubjectId
            && $row->relationship_type === $relationshipType ? $row : null;
    }
}
