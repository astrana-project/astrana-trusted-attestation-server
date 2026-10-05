<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * A key registration lost the race for a public key another relationship already holds.
 *
 * The unique index is the real arbiter -- the up-front check only gives a clean answer in the common,
 * uncontended case -- so the store translates the database's constraint violation into this, which the
 * service turns into the same 409 the check gives. Raised from inside the transaction, so the transaction
 * is rolled back on the way out rather than continued: a failed transaction refuses every further
 * statement, so there is nothing to salvage from within it.
 */
final class KeyConflictException extends RuntimeException {}
