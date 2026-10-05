<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\QueryException;
use PDOException;

/**
 * Whether a database failure is the unique index refusing a key that is already taken.
 *
 * Two members can submit the same key at the same moment. The pre-flight check in RelationshipService
 * settles the ordinary sequential case, but it cannot settle the simultaneous one: both requests read
 * an unused key, both proceed, and the unique index is what actually decides. Whichever loses gets a
 * constraint violation, and that member should be told their key is already registered -- not handed a
 * 500, which is what happened before this existed.
 *
 * Matched on the engine's own error number, not on SQLSTATE alone. PostgreSQL has a dedicated state for
 * it (23505), but MySQL and SQL Server both report 23000 for every integrity violation there is, so a
 * SQLSTATE-only match would answer "that key is already registered" to a member whose write failed for
 * an entirely different reason -- hiding a real fault behind a plausible answer.
 *
 * A rule rather than a catch block, so it can be tested against what each engine actually reports
 * without needing three databases and a race to reproduce.
 */
final class DuplicateKey
{
    /** PostgreSQL: unique_violation has a SQLSTATE of its own, so the state alone is enough. */
    private const POSTGRES_UNIQUE_VIOLATION = '23505';

    /**
     * The engines that report every integrity violation as 23000, and the numbers that narrow it:
     * MySQL's ER_DUP_ENTRY, and SQL Server's duplicate-on-index and duplicate-on-constraint.
     */
    private const DRIVER_DUPLICATE_CODES = [1062, 2601, 2627];

    public static function describes(QueryException $exception): bool
    {
        $errorInfo = self::errorInfo($exception);
        if ($errorInfo === null) {
            return false;
        }

        [$sqlState, $driverCode] = [$errorInfo[0] ?? null, $errorInfo[1] ?? null];

        if ($sqlState === self::POSTGRES_UNIQUE_VIOLATION) {
            return true;
        }

        return in_array((int) $driverCode, self::DRIVER_DUPLICATE_CODES, true);
    }

    /**
     * The driver's own error detail, which lives on the PDOException the connection wrapped rather than
     * on the QueryException itself.
     *
     * @return array<int, mixed>|null
     */
    private static function errorInfo(QueryException $exception): ?array
    {
        $previous = $exception->getPrevious();

        if ($previous instanceof PDOException && is_array($previous->errorInfo)) {
            return $previous->errorInfo;
        }

        return is_array($exception->errorInfo) ? $exception->errorInfo : null;
    }
}
