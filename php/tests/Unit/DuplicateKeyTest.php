<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DuplicateKey;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Losing the race for a key is a conflict, not a crash.
 *
 * Two members can submit the same key at the same moment. The pre-flight check settles the ordinary
 * sequential case but cannot settle the simultaneous one: both requests read an unused key, both
 * proceed, and the unique index decides. This implementation had no handler for the loser, so it got a
 * 500 where .NET and Java both answered 409.
 *
 * Nothing noticed, because the conformance suite's concurrency section only fails when the race is
 * genuinely tight, and on a fast local database the pre-flight check usually wins first. A test that
 * only fails sometimes is one that eventually gets believed when it passes.
 *
 * So the violation is described rather than raced. What each engine reports is a fact about the engine,
 * and reproducing it needs neither a database nor a collision.
 */
final class DuplicateKeyTest extends TestCase
{
    private function violation(string $sqlState, int|string $driverCode): QueryException
    {
        // errorInfo lives on the PDOException the connection wrapped, and is public where the exception
        // code is not.
        $pdo = new PDOException('constraint violation');
        $pdo->errorInfo = [$sqlState, $driverCode, 'duplicate key'];

        return new QueryException('pgsql', 'UPDATE member_relationships ...', [], $pdo);
    }

    // ---------------------------------------------------------------------------------------------
    // What each engine calls a duplicate
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function postgres_reports_a_state_of_its_own(): void
    {
        self::assertTrue(DuplicateKey::describes($this->violation('23505', 7)));
    }

    #[Test]
    public function mysql_reports_a_generic_state_and_a_specific_number(): void
    {
        // 23000 covers every integrity violation; 1062 is what says "duplicate entry".
        self::assertTrue(DuplicateKey::describes($this->violation('23000', 1062)));
    }

    #[Test]
    public function sql_server_reports_two_different_numbers_for_the_same_thing(): void
    {
        // 2601 is a duplicate on a unique index, 2627 on a unique constraint. This schema uses an index
        // on PostgreSQL and SQL Server both, but an operator may have built either.
        self::assertTrue(DuplicateKey::describes($this->violation('23000', 2601)));
        self::assertTrue(DuplicateKey::describes($this->violation('23000', 2627)));
    }

    // ---------------------------------------------------------------------------------------------
    // What must not be mistaken for one
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function another_integrity_violation_is_not_a_duplicate(): void
    {
        // The whole reason for matching on the driver's number rather than SQLSTATE alone. A not-null
        // violation on MySQL reports the same 23000, and answering "that key is already registered"
        // would tell a member something untrue and hide a real fault behind a plausible answer.
        self::assertFalse(DuplicateKey::describes($this->violation('23000', 1048)));
    }

    #[Test]
    public function a_connection_failure_is_not_a_duplicate(): void
    {
        self::assertFalse(DuplicateKey::describes($this->violation('08006', 7)));
    }

    #[Test]
    public function a_syntax_error_is_not_a_duplicate(): void
    {
        self::assertFalse(DuplicateKey::describes($this->violation('42601', 7)));
    }

    #[Test]
    public function an_exception_carrying_no_driver_detail_is_not_a_duplicate(): void
    {
        // Better to let an unrecognisable failure surface than to guess it was a collision. A 500 that
        // reaches error monitoring is recoverable; a 409 that quietly tells the member to pick another
        // key is not.
        $exception = new QueryException('pgsql', 'UPDATE ...', [], new \RuntimeException('who knows'));

        self::assertFalse(DuplicateKey::describes($exception));
    }
}
