<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\AuditLog;

/** Records each event as "event:subject:type", so a test can assert what was written and with which type. */
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
