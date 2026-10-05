<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AuditWriter;
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The audit trail is append-only and ordered, so what it records about *when* has to be worth ordering
 * by. Two events a few milliseconds apart must not land on the same instant.
 *
 * A Feature test rather than a Unit one because it swaps the DB facade, which needs the container.
 * It touches no database: the insert is intercepted and the bound parameters inspected.
 */
final class AuditWriterTest extends TestCase
{
    /** @return list<mixed> the parameters bound by the single expected insert */
    private function captureBindings(callable $operation): array
    {
        $captured = [];

        DB::shouldReceive('insert')->once()->andReturnUsing(
            function (string $sql, array $bindings) use (&$captured): bool {
                $captured = $bindings;

                return true;
            }
        );

        $operation(new AuditWriter);

        return $captured;
    }

    #[Test]
    public function it_records_the_time_with_sub_second_precision(): void
    {
        // The regression this exists for: the timestamp was formatted with Carbon's toDateTimeString(),
        // which emits whole seconds. Two events in the same second then tie, so ordering an audit report
        // by occurred_at -- the obvious thing to do with it -- puts them in an arbitrary order. The other
        // two implementations write microseconds into the same column, so this also made the same trail
        // mean different things depending on which one wrote the row.
        $bindings = $this->captureBindings(fn (AuditWriter $writer) => $writer->keyRegistered('subject-1', 'employee'));

        $occurredAt = (string) $bindings[3];

        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}\.\d{1,6}/',
            $occurredAt,
            'occurred_at was written without a fractional second: '.$occurredAt
        );
    }

    #[Test]
    public function it_records_the_time_in_utc(): void
    {
        // The column is timestamptz and the other two implementations write UTC. A local-time value
        // silently shifted by the server's zone would corrupt ordering across a deployment move.
        //
        // The writer produces a *naive* wall-clock string, so this only means anything when the local
        // zone is not itself UTC -- which, under test, it is. Force a non-UTC default zone: now a
        // regression to Carbon::now() (local) would write a New York wall-clock time, and reading that
        // back as UTC lands it hours from now and fails. Only Carbon::now('UTC') puts the UTC wall-clock
        // time in the column.
        $originalZone = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            $bindings = $this->captureBindings(
                fn (AuditWriter $writer) => $writer->keyRemoved('subject-2', 'employee'));

            $occurredAt = (string) $bindings[3];
            $parsed = new \DateTimeImmutable($occurredAt, new \DateTimeZone('UTC'));
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            self::assertLessThan(
                60,
                abs($now->getTimestamp() - $parsed->getTimestamp()),
                'occurred_at is not the current UTC wall-clock time -- it looks like a local-zone value: '.$occurredAt
            );
        } finally {
            date_default_timezone_set($originalZone);
        }
    }

    #[Test]
    public function the_postgresql_connection_fixes_its_session_to_utc(): void
    {
        // The UTC wall-clock time above is written without an offset, and PostgreSQL reads such a value
        // into the timestamptz column in the session's time zone. A server set to another zone would
        // otherwise store an instant hours away from the one meant. The connector is driven against a
        // stand-in connection, so no PostgreSQL server is needed, and every statement it prepares is kept.
        $prepared = [];
        $statement = $this->createStub(PDOStatement::class);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$prepared, $statement): PDOStatement {
            $prepared[] = $sql;

            return $statement;
        });

        $connector = new class($pdo) extends PostgresConnector
        {
            public function __construct(private readonly PDO $pdo) {}

            public function createConnection($dsn, array $config, array $options): PDO
            {
                return $this->pdo;
            }
        };

        $connector->connect(config('database.connections.pgsql'));

        self::assertContains("set time zone 'UTC'", $prepared);
    }

    #[Test]
    public function it_records_the_event_type_and_subject_and_leaves_actor_null(): void
    {
        // actor is null for self-service events by design; the schema documents it. Asserted here so a
        // later change cannot start attributing a member's own action to someone else.
        $bindings = $this->captureBindings(fn (AuditWriter $writer) => $writer->keyRegistered('subject-3', 'client'));

        self::assertSame('key_registered', $bindings[0]);
        self::assertSame('subject-3', $bindings[1]);
        self::assertCount(4, $bindings, 'actor is passed as a literal NULL in the statement, not bound');
    }

    #[Test]
    public function it_records_a_key_clearing_as_its_own_event(): void
    {
        // Clearing a key is a distinct self-service event from registering or removing one, so it carries
        // its own type in the trail rather than being folded into either.
        $bindings = $this->captureBindings(fn (AuditWriter $writer) => $writer->keyCleared('subject-5', 'employee'));

        self::assertSame('key_cleared', $bindings[0]);
        self::assertSame('subject-5', $bindings[1]);
        self::assertSame('employee', $bindings[2]);
    }

    #[Test]
    public function it_records_which_relationship_the_event_concerns(): void
    {
        // A member can hold several relationships, so an entry naming only the subject would not say
        // what actually happened -- which key was registered, which relationship was left alone.
        $bindings = $this->captureBindings(fn (AuditWriter $writer) => $writer->keyRemoved('subject-4', 'student'));

        self::assertSame('key_removed', $bindings[0]);
        self::assertSame('subject-4', $bindings[1]);
        self::assertSame('student', $bindings[2]);
    }
}
