<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MemberRelationship;
use App\Support\BinaryColumn;
use App\Support\DuplicateKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The Eloquent-and-parameterised-SQL implementation of the store.
 *
 * Reads go through Eloquent. The writes that touch public_key use explicit parameterised SQL, because the
 * binary column has to be converted in SQL rather than bound directly -- see BinaryColumn -- and the read
 * has to convert it back to hex, since PDO cannot portably return raw binary. Every value is still bound,
 * never interpolated.
 */
final class EloquentRelationshipStore implements RelationshipStore
{
    public function findAllHeldBy(string $iamSubjectId): Collection
    {
        return $this->selectWithKey()
            ->where('iam_subject_id', $iamSubjectId)
            ->orderBy('relationship_type')
            ->get();
    }

    public function find(string $iamSubjectId, string $relationshipType): ?MemberRelationship
    {
        return $this->selectWithKey()
            ->where('iam_subject_id', $iamSubjectId)
            ->where('relationship_type', $relationshipType)
            ->first();
    }

    public function findByPublicKey(string $rawPublicKey): ?MemberRelationship
    {
        return $this->selectWithKey()
            ->whereRaw(
                'public_key = '.BinaryColumn::bindExpression($this->driver()),
                [BinaryColumn::toHex($rawPublicKey)]
            )
            ->first();
    }

    public function isKeyHeldElsewhere(string $rawPublicKey, int $exceptId): bool
    {
        // Excluding this row is what keeps an idempotent retry from being answered with a conflict
        // against itself.
        return MemberRelationship::query()
            ->whereRaw(
                'public_key = '.BinaryColumn::bindExpression($this->driver()),
                [BinaryColumn::toHex($rawPublicKey)]
            )
            ->where('id', '!=', $exceptId)
            ->exists();
    }

    public function updateKey(int $id, string $rawPublicKey): int
    {
        $bind = BinaryColumn::bindExpression($this->driver());

        try {
            // Only public_key is written. revoked_at and expires_at are deliberately untouched:
            // registering a key is not an appeal, so a member cannot lift the organisation's revocation by
            // registering a key again. relationship_type and relationship_subtype belong to the grant, not to this.
            return DB::update(
                "UPDATE member_relationships SET public_key = {$bind} WHERE id = ?",
                [BinaryColumn::toHex($rawPublicKey), $id]
            );
        } catch (QueryException $exception) {
            // Lost the race on the unique index between the check and the write. Raised, not returned, so
            // the transaction rolls back on the way out -- a failed transaction refuses every further
            // statement, so there is nothing to salvage from within it.
            if (DuplicateKey::describes($exception)) {
                throw new KeyConflictException;
            }

            throw $exception;
        }
    }

    public function clearKey(int $id): int
    {
        // Only public_key is written, and only ever to NULL, so there is no binary value to bind and no
        // unique index to trip. revoked_at and expires_at are left untouched, exactly as updateKey leaves
        // them: clearing a key is not an appeal any more than registering one is.
        return DB::update('UPDATE member_relationships SET public_key = NULL WHERE id = ?', [$id]);
    }

    public function deleteOwn(string $iamSubjectId, string $relationshipType): int
    {
        return MemberRelationship::query()
            ->where('iam_subject_id', $iamSubjectId)
            ->where('relationship_type', $relationshipType)
            ->delete();
    }

    public function invokeSelfRevoke(string $iamSubjectId, string $relationshipType): void
    {
        // SQL Server spells a procedure call differently from the other two, and MySQL's procedures
        // declare no defaults, so every argument is passed explicitly everywhere. Both values are bound,
        // never interpolated -- only the call syntax varies.
        $call = $this->driver() === 'sqlsrv'
            ? 'EXEC member_self_revoke_relationship ?, ?'
            : 'CALL member_self_revoke_relationship(?, ?)';

        DB::statement($call, [$iamSubjectId, $relationshipType]);
    }

    /**
     * Always reads the binary key back as hex, since PDO cannot portably return raw binary. The model
     * decodes it to raw bytes in its public_key accessor.
     */
    private function selectWithKey(): Builder
    {
        $read = BinaryColumn::readExpression($this->driver(), 'public_key');

        return MemberRelationship::query()->select([
            'id',
            'iam_subject_id',
            DB::raw("{$read} as public_key_hex"),
            'relationship_type',
            'relationship_subtype',
            'expires_at',
            'revoked_at',
        ]);
    }

    private function driver(): string
    {
        return DB::connection()->getDriverName();
    }
}
