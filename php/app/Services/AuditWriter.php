<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes the three self-service audit events.
 *
 * Raw parameterised SQL rather than an Eloquent model, deliberately. The application's database principal
 * holds INSERT only on audit_log -- an audit trail that can be edited after the fact is not one -- and
 * giving the table a model would invite a call that reads, updates or deletes it. There is no
 * AuditLogEntry class anywhere in this codebase, and that absence is the point.
 *
 * Never records the public key itself, and never records a verification: checking a key is not one of the
 * events, and logging checks would tell the organisation who is asking.
 */
final class AuditWriter implements AuditLog
{
    private const KEY_REGISTERED = 'key_registered';

    private const KEY_CLEARED = 'key_cleared';

    private const KEY_REMOVED = 'key_removed';

    // relationship_granted_by_org, relationship_revoked_by_org, relationship_extended_by_org and
    // relationship_self_revoked are written by the stored procedures, not by the application.

    /**
     * Enlists in whatever transaction the caller has already opened, so the audit row and the change it
     * records commit or roll back together. actor is null for self-service events: the member is not
     * acting on someone else.
     *
     * The relationship type is recorded as well as the subject, because a member can hold several and an
     * entry naming only the subject would not say what actually happened.
     */
    public function write(string $eventType, string $iamSubjectId, string $relationshipType): void
    {
        DB::insert(
            'INSERT INTO audit_log (event_type, iam_subject_id, relationship_type, actor, occurred_at) '
            .'VALUES (?, ?, ?, NULL, ?)',
            // Microseconds, not toDateTimeString(): that formats whole seconds, so two events in the
            // same second tie when the log is ordered by occurred_at -- which is the obvious way to read
            // an append-only trail. The other two implementations write sub-second precision into this
            // same column, and the trail should not mean something different depending on who wrote it.
            [$eventType, $iamSubjectId, $relationshipType, Carbon::now('UTC')->format('Y-m-d H:i:s.u')]
        );
    }

    public function keyRegistered(string $iamSubjectId, string $relationshipType): void
    {
        $this->write(self::KEY_REGISTERED, $iamSubjectId, $relationshipType);
    }

    public function keyCleared(string $iamSubjectId, string $relationshipType): void
    {
        $this->write(self::KEY_CLEARED, $iamSubjectId, $relationshipType);
    }

    public function keyRemoved(string $iamSubjectId, string $relationshipType): void
    {
        $this->write(self::KEY_REMOVED, $iamSubjectId, $relationshipType);
    }
}
