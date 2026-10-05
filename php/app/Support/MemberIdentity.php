<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A member as the organisation's IAM system describes them, for the duration of one request.
 *
 * Only the subject is ever stored, and only as the key the relationship rows hang from. The name exists
 * solely so the self-service page can show the member who they are logged in as, and is never written
 * anywhere.
 *
 * It deliberately carries no relationship type or subtype. A relationship exists only because the
 * organisation created it, and the database is the only place that records one.
 */
final readonly class MemberIdentity
{
    public function __construct(
        /** The organisation's own subject identifier. Never leaves Astrana Trusted Attestation. */
        public string $iamSubjectId,
        /** Display name from the IAM session. Never stored. */
        public string $name,
    ) {}
}
