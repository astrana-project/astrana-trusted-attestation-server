<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\SchemaInitializer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Mockery;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * When the schema is created, when it is left alone, and when the instance refuses to run.
 *
 * The existence check covers every object the application uses, not only the first table, because a
 * database holding that table and nothing else would otherwise pass as complete and the instance would fail
 * at the first procedure call. It leaves out the organisation role's three procedures, which a login holding
 * only the application role cannot see, so that the hardened deployment is not refused. The procedure call and the DDL are intercepted here; what the script
 * does to a real engine is shared/test/schema-checks.py's concern.
 *
 * A Feature test because the initializer reads configuration and the DB facade, so it needs the container.
 * Nothing here reaches a database.
 */
final class SchemaInitializerTest extends TestCase
{
    private const TABLES = ['member_relationships', 'audit_log'];

    private const PROCEDURES = [
        'grant_member_relationship',
        'revoke_member_relationship',
        'extend_member_relationship',
        'member_self_revoke_relationship',
        'prune_audit_log',
    ];

    /** The procedures the application itself calls, the only ones its role may execute. */
    private const APPLICATION_PROCEDURES = ['member_self_revoke_relationship', 'prune_audit_log'];

    /** @var list<string> */
    private array $executed = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('trusted_attestation.database.create_schema_on_startup', true);
    }

    /**
     * A database that answers the existence check from $present and records every statement run. When
     * $failWith is set, the first statement fails with it; $afterFailure then becomes what is present.
     *
     * @param  list<string>  $present
     * @param  list<string>|null  $afterFailure
     */
    private function database(array $present, ?PDOException $failWith = null, ?array $afterFailure = null): void
    {
        $this->executed = [];
        $state = ['present' => $present];

        $connection = Mockery::mock();
        $connection->shouldReceive('getDriverName')->andReturn('pgsql');

        $db = Mockery::mock();
        $db->shouldReceive('connection')->andReturn($connection);
        $db->shouldReceive('select')->andReturnUsing(function (string $sql, array $bindings) use (&$state): array {
            $rows = [];
            foreach ($bindings as $name) {
                if (in_array($name, $state['present'], true)) {
                    $rows[] = (object) ['name' => $name];
                }
            }

            return $rows;
        });
        $db->shouldReceive('unprepared')->andReturnUsing(function (string $sql) use (&$state, $failWith, $afterFailure): bool {
            $this->executed[] = $sql;

            if ($failWith !== null && count($this->executed) === 1) {
                $state['present'] = $afterFailure ?? $state['present'];

                throw new QueryException('pgsql', $sql, [], $failWith);
            }

            return true;
        });

        DB::swap($db);
    }

    #[Test]
    public function a_complete_schema_is_left_alone(): void
    {
        $this->database(array_merge(self::TABLES, self::PROCEDURES));

        (new SchemaInitializer)->ensureSchema();

        self::assertSame([], $this->executed);
    }

    #[Test]
    public function a_schema_seen_through_the_application_role_alone_is_left_alone(): void
    {
        // The hardened deployment: an administrator applied the schema and the server signs in with a login
        // holding only the application role. information_schema then shows that login the two tables and
        // only the two procedures the role may execute, so the other three are invisible, not missing.
        $this->database(array_merge(self::TABLES, self::APPLICATION_PROCEDURES));

        (new SchemaInitializer)->ensureSchema();

        self::assertSame([], $this->executed);
    }

    #[Test]
    public function an_empty_database_gets_the_whole_schema(): void
    {
        $this->database([]);

        (new SchemaInitializer)->ensureSchema();

        self::assertNotEmpty($this->executed);
        self::assertStringContainsString('CREATE TABLE member_relationships', implode("\n", $this->executed));
        self::assertStringContainsString('prune_audit_log', implode("\n", $this->executed));
        // The organisation role's procedures are not part of the existence check but are still created.
        self::assertStringContainsString('PROCEDURE grant_member_relationship', implode("\n", $this->executed));
    }

    #[Test]
    public function a_partial_schema_refuses_to_run_naming_what_is_missing(): void
    {
        // The two tables are there and the procedures are not: an application of the script that was cut
        // short, or an old version. Running would fail at the first procedure call, so it is refused now,
        // and the message says which objects to look for.
        $this->database(self::TABLES);

        try {
            (new SchemaInitializer)->ensureSchema();
            self::fail('a partial schema was accepted');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('part of the Astrana Trusted Attestation schema but not all of it', $exception->getMessage());
            self::assertStringContainsString('Missing: '.implode(', ', self::APPLICATION_PROCEDURES).'.', $exception->getMessage());
            self::assertStringNotContainsString('member_relationships, ', $exception->getMessage());
        }

        self::assertSame([], $this->executed, 'it ran the script over a partial schema');
    }

    #[Test]
    public function a_missing_procedure_alone_is_a_partial_schema(): void
    {
        $this->database(array_merge(self::TABLES, array_slice(self::PROCEDURES, 0, 4)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Missing: prune_audit_log\./');

        (new SchemaInitializer)->ensureSchema();
    }

    #[Test]
    public function an_empty_database_with_creation_turned_off_refuses_to_run_naming_the_setting(): void
    {
        config()->set('trusted_attestation.database.create_schema_on_startup', false);
        $this->database([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/TRUSTED_ATTESTATION_CREATE_SCHEMA is false.*schema-postgres\.sql/s');

        (new SchemaInitializer)->ensureSchema();
    }

    #[Test]
    public function the_loser_of_a_creation_race_carries_on_once_the_winners_schema_is_complete(): void
    {
        // Both instances found an empty database. The other one's CREATE TABLE landed first, so this one's
        // is refused with "already exists". By the time it looks again the winner has finished, so this
        // instance continues on that schema rather than reporting a failed start.
        $this->database(
            present: [],
            failWith: new PDOException('SQLSTATE[42P07]: Duplicate table: 7 ERROR: relation "member_relationships" already exists'),
            afterFailure: array_merge(self::TABLES, self::PROCEDURES),
        );

        (new SchemaInitializer(raceRechecks: 3, raceWaitMicroseconds: 0))->ensureSchema();

        self::assertCount(1, $this->executed, 'it kept running statements after losing the race');
    }

    #[Test]
    public function the_loser_of_a_creation_race_refuses_the_request_while_the_winner_is_still_working(): void
    {
        $this->database(
            present: [],
            failWith: new PDOException("SQLSTATE[42S01]: Base table or view already exists: 1050 Table 'member_relationships' already exists"),
            afterFailure: self::TABLES,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/another instance.*still incomplete.*Missing: member_self_revoke_relationship, prune_audit_log\./is');

        (new SchemaInitializer(raceRechecks: 2, raceWaitMicroseconds: 0))->ensureSchema();
    }

    #[Test]
    public function any_other_failure_while_creating_is_raised_as_it_is(): void
    {
        // Only "already exists" is a race. A permission error or a syntax error is the operator's to see.
        $this->database(present: [], failWith: new PDOException('SQLSTATE[42501]: Insufficient privilege: 7 ERROR: permission denied for schema public'));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/permission denied/');

        (new SchemaInitializer)->ensureSchema();
    }
}
