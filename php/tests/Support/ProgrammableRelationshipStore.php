<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\MemberRelationship;
use App\Services\RelationshipStore;
use Illuminate\Database\Eloquent\Collection;

/**
 * A store whose every answer the test sets directly, for tests that drive a route through the router with
 * no database behind it. Bound into the container in place of the Eloquent store.
 */
final class ProgrammableRelationshipStore implements RelationshipStore
{
    /** @var list<MemberRelationship> */
    public array $all = [];

    public ?MemberRelationship $own = null;

    public ?MemberRelationship $byPublicKey = null;

    public bool $keyHeldElsewhere = false;

    public int $updateCount = 1;

    public int $clearCount = 1;

    public int $deleteCount = 0;

    public function findAllHeldBy(string $iamSubjectId): Collection
    {
        return new Collection($this->all);
    }

    public function find(string $iamSubjectId, string $relationshipType): ?MemberRelationship
    {
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
        return $this->updateCount;
    }

    public function clearKey(int $id): int
    {
        return $this->clearCount;
    }

    public function deleteOwn(string $iamSubjectId, string $relationshipType): int
    {
        return $this->deleteCount;
    }

    public function invokeSelfRevoke(string $iamSubjectId, string $relationshipType): void {}
}
