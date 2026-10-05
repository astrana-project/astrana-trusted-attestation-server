<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The three self-service audit events, behind an interface so the write path's decisions can be unit-tested
 * without the database the real writer inserts into. AuditWriter is the implementation the conformance
 * suite drives; a recording fake stands in under test.
 */
interface AuditLog
{
    public function keyRegistered(string $iamSubjectId, string $relationshipType): void;

    public function keyCleared(string $iamSubjectId, string $relationshipType): void;

    public function keyRemoved(string $iamSubjectId, string $relationshipType): void;
}
