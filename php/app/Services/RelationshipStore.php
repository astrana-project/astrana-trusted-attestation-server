<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MemberRelationship;
use Illuminate\Database\Eloquent\Collection;

/**
 * The member-relationship reads and writes the service needs, behind an interface so the decisions built
 * on them -- which result is a 404, which a 409 -- can be exercised without a database. The Eloquent
 * implementation is what the conformance suite drives end to end, and it is where every engine-specific
 * detail lives: the binary column's per-driver binding, the duplicate-key translation, the procedure
 * call syntax. The interface hides all of it, so the service reads the same whatever the engine.
 *
 * The writes report how many rows they touched. The service writes an audit entry only for a change that
 * happened, so a row that vanished between its lookup and the write is answered not found and leaves no
 * entry claiming otherwise.
 */
interface RelationshipStore
{
    /**
     * Everything the member holds, in a stable order. An empty collection is an ordinary answer.
     *
     * @return Collection<int, MemberRelationship>
     */
    public function findAllHeldBy(string $iamSubjectId): Collection;

    /**
     * One specific relationship of the caller's own, or null if the organisation never granted it. The
     * engine's collation decides what the lookup matches, so the service compares the row's type with the
     * requested one itself before trusting the match.
     */
    public function find(string $iamSubjectId, string $relationshipType): ?MemberRelationship;

    /** The one relationship a public key belongs to, or null. The anonymous /attest lookup. */
    public function findByPublicKey(string $rawPublicKey): ?MemberRelationship;

    /** Whether the key already belongs to some other relationship -- this member's own included. */
    public function isKeyHeldElsewhere(string $rawPublicKey, int $exceptId): bool;

    /**
     * Writes the key onto one relationship, translating a unique-index violation into
     * KeyConflictException -- the only constraint this can trip. Returns how many rows changed (0 or 1).
     *
     * @throws KeyConflictException
     */
    public function updateKey(int $id, string $rawPublicKey): int;

    /**
     * Clears the key on one relationship, keeping the row. The member unsetting their own key to pause the
     * relationship; no unique-index violation is possible, so this cannot conflict. Returns how many rows
     * changed (0 or 1).
     */
    public function clearKey(int $id): int;

    /** Hard-deletes the caller's relationship of this type, returning how many rows went (0 or 1). */
    public function deleteOwn(string $iamSubjectId, string $relationshipType): int;

    /** Runs the one-directional self-revoke stored procedure, which writes its own audit row. */
    public function invokeSelfRevoke(string $iamSubjectId, string $relationshipType): void;
}
