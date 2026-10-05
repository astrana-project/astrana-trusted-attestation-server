<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConfigurationException;
use App\Exceptions\SchemaCreationInProgressException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Creates the schema on first run, so IT staff only have to deploy the application, create an empty
 * database, and set connection details.
 *
 * The DDL executed is the repository's own shared/schema/*.sql, shipped verbatim, the same script a
 * database administrator would review, rather than a second definition maintained in Laravel migrations
 * that could drift from it. That is also why this application has no migrations directory of its own.
 *
 * The existence check covers every object the application itself uses, the two tables and the two
 * procedures it calls (member_self_revoke_relationship and prune_audit_log), not the first table alone.
 * The script also creates the three procedures the organisation role executes (grant, revoke and extend),
 * but they are left out of the check because a login holding only the application role cannot see them in
 * information_schema. Checking for them would refuse the hardened deployment, where an administrator
 * applies the schema and the server signs in with that narrow role. A database holding some of the four objects is a schema that was applied by hand and
 * cut short, or an earlier version of it, and running on it would fail at the first call of a procedure
 * that is not there. It is refused with a message naming what is missing. Creating the schema still runs
 * the whole script, the organisation role's procedures included. Two instances starting against the same
 * empty database at once both find nothing and both run the script. The one that loses the race is told
 * an object already exists, checks again, and carries on once the winner's schema is complete.
 */
final class SchemaInitializer
{
    /** The tables the schema file creates. */
    private const TABLES = ['member_relationships', 'audit_log'];

    /**
     * The procedures the application calls, the only ones its role may execute and so the only ones a
     * login holding that role alone can see.
     */
    private const PROCEDURES = [
        'member_self_revoke_relationship',
        'prune_audit_log',
    ];

    /**
     * @param  int  $raceRechecks  how many times the loser of a creation race looks again for the winner's schema
     * @param  int  $raceWaitMicroseconds  how long it waits between looks
     */
    public function __construct(
        private readonly int $raceRechecks = 20,
        private readonly int $raceWaitMicroseconds = 250_000,
    ) {}

    public function ensureSchema(): void
    {
        $driver = DB::connection()->getDriverName();

        $missing = $this->missingObjects($driver);
        if ($missing === []) {
            return;
        }

        if (count($missing) !== count(self::TABLES) + count(self::PROCEDURES)) {
            throw new ConfigurationException(
                'The database holds part of the Astrana Trusted Attestation schema but not all of it. Missing: '
                .implode(', ', $missing).'. Apply shared/schema/schema-'.$this->scriptSuffix($driver).'.sql in '
                .'full, or drop what it created so the application can create the schema itself.'
            );
        }

        if (! config('trusted_attestation.database.create_schema_on_startup')) {
            throw new ConfigurationException(
                'The schema does not exist and TRUSTED_ATTESTATION_CREATE_SCHEMA is false. Apply '
                .'shared/schema/schema-'.$this->scriptSuffix($driver).'.sql to the database, or turn the setting '
                .'back on.'
            );
        }

        $statements = SqlScriptSplitter::split($this->readScript($driver), $driver);

        Log::info('Creating Astrana Trusted Attestation schema.', ['driver' => $driver, 'statements' => count($statements)]);

        // Not wrapped in one transaction, because MySQL commits DDL implicitly, so a rollback would be a
        // promise the engine cannot keep. A failure part-way leaves a partial schema, which the operator
        // resolves by dropping the database and starting again, which is right for a first run on an
        // empty database.
        try {
            foreach ($statements as $statement) {
                DB::unprepared($statement);
            }
        } catch (QueryException $exception) {
            if (! self::saysAlreadyExists($exception)) {
                throw $exception;
            }

            $this->awaitSchemaCreatedElsewhere($driver, $exception);

            return;
        }

        Log::info('Astrana Trusted Attestation schema created.');
    }

    /**
     * Every object the application uses that the current schema lacks, in the file's order.
     *
     * All three engines expose information_schema.tables and information_schema.routines, but each names
     * the current schema differently -- and the filter matters: MySQL's information_schema spans every
     * database the connection can see, so an unfiltered probe would find a table of the same name in
     * someone else's database and conclude ours already exists.
     *
     * @return list<string>
     */
    private function missingObjects(string $driver): array
    {
        $currentSchema = match ($driver) {
            'sqlsrv' => 'SCHEMA_NAME()',
            'pgsql' => 'current_schema()',
            'mysql', 'mariadb' => 'DATABASE()',
            default => throw new InvalidArgumentException("Unsupported database driver '{$driver}'."),
        };

        $present = array_merge(
            $this->names(
                'SELECT table_name AS name FROM information_schema.tables '
                ."WHERE table_schema = {$currentSchema} AND table_name IN (".self::placeholders(self::TABLES).')',
                self::TABLES
            ),
            $this->names(
                'SELECT routine_name AS name FROM information_schema.routines '
                ."WHERE routine_schema = {$currentSchema} AND routine_name IN (".self::placeholders(self::PROCEDURES).')',
                self::PROCEDURES
            ),
        );

        return array_values(array_filter(
            array_merge(self::TABLES, self::PROCEDURES),
            static fn (string $object): bool => ! in_array($object, $present, true)
        ));
    }

    /**
     * The first column of each row, lower-cased. Column-name case varies by driver, so the first value is
     * read rather than a key assumed.
     *
     * @param  list<string>  $bindings
     * @return list<string>
     */
    private function names(string $sql, array $bindings): array
    {
        return array_map(
            static fn (mixed $row): string => strtolower((string) array_values((array) $row)[0]),
            DB::select($sql, $bindings)
        );
    }

    /** @param list<string> $values */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * Whether the engine refused a CREATE because the object is already there: PostgreSQL and MySQL say
     * "already exists", SQL Server "There is already an object named".
     */
    private static function saysAlreadyExists(QueryException $exception): bool
    {
        return preg_match('/already exists|already an object named/i', $exception->getMessage()) === 1;
    }

    /**
     * Another instance got to the empty database first. Its work is not undone by this instance's failed
     * statement, so the schema is checked again until it is complete, and this instance carries on with
     * the schema the other one created.
     */
    private function awaitSchemaCreatedElsewhere(string $driver, QueryException $cause): void
    {
        for ($attempt = 0; $attempt < $this->raceRechecks; $attempt++) {
            $missing = $this->missingObjects($driver);
            if ($missing === []) {
                Log::info('Astrana Trusted Attestation schema was created by another instance at the same moment.');

                return;
            }

            usleep($this->raceWaitMicroseconds);
        }

        throw new SchemaCreationInProgressException(
            'Another instance started creating the Astrana Trusted Attestation schema at the same moment and it is '
            .'still incomplete. Missing: '.implode(', ', $missing).'. This request is refused; the next one checks again.',
            0,
            $cause
        );
    }

    private function readScript(string $driver): string
    {
        $path = base_path('schema/schema-'.$this->scriptSuffix($driver).'.sql');
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new ConfigurationException(
                'Schema file "'.$path.'" could not be read. It is copied from shared/schema in the '
                .'repository and must ship with the application.'
            );
        }

        return $contents;
    }

    private function scriptSuffix(string $driver): string
    {
        return match ($driver) {
            'sqlsrv' => 'mssql',
            'pgsql' => 'postgres',
            'mysql', 'mariadb' => 'mysql',
            default => throw new InvalidArgumentException("Unsupported database driver '{$driver}'."),
        };
    }
}
