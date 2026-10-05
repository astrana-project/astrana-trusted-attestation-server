<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SqlScriptSplitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The splitter runs against the real shipped schema scripts, not hand-written fixtures. If the contract
 * repo's DDL changes shape -- a new procedure, a different delimiter -- these tests see it.
 */
final class SqlScriptSplitterTest extends TestCase
{
    /** @return list<string> */
    private static function split(string $driver): array
    {
        $suffix = match ($driver) {
            'sqlsrv' => 'mssql',
            'pgsql' => 'postgres',
            'mysql' => 'mysql',
        };

        $script = file_get_contents(dirname(__DIR__, 2)."/schema/schema-{$suffix}.sql");
        self::assertIsString($script, "Missing schema/schema-{$suffix}.sql");

        return SqlScriptSplitter::split($script, $driver);
    }

    public static function drivers(): array
    {
        return [['sqlsrv'], ['pgsql'], ['mysql']];
    }

    #[Test]
    #[DataProvider('drivers')]
    public function it_produces_both_tables_and_every_procedure(string $driver): void
    {
        $statements = self::split($driver);
        $joined = implode("\n", $statements);

        foreach (['CREATE TABLE member_relationships', 'CREATE TABLE audit_log',
            'grant_member_relationship', 'revoke_member_relationship', 'extend_member_relationship',
            'member_self_revoke_relationship', 'prune_audit_log'] as $needle) {
            self::assertStringContainsString($needle, $joined);
        }
    }

    #[Test]
    #[DataProvider('drivers')]
    public function it_emits_no_empty_or_comment_only_statements(string $driver): void
    {
        foreach (self::split($driver) as $statement) {
            self::assertNotSame('', trim($statement));

            $hasExecutableLine = false;
            foreach (explode("\n", $statement) as $line) {
                $line = trim($line);
                if ($line !== '' && ! str_starts_with($line, '--')) {
                    $hasExecutableLine = true;
                    break;
                }
            }

            self::assertTrue($hasExecutableLine, "Comment-only statement would be sent to the server:\n{$statement}");
        }
    }

    #[Test]
    public function it_never_passes_client_only_directives_to_the_driver(): void
    {
        // GO and DELIMITER are features of each engine's command-line client. A PDO driver rejects them.
        foreach (self::split('sqlsrv') as $statement) {
            self::assertStringNotContainsString("\nGO", $statement);
        }

        foreach (self::split('mysql') as $statement) {
            self::assertStringNotContainsStringIgnoringCase('DELIMITER', $statement);
            self::assertStringNotContainsString('$$', $statement);
        }
    }

    #[Test]
    public function it_keeps_a_postgres_procedure_body_whole(): void
    {
        // The bodies are dollar-quoted and full of semicolons. Splitting naively on ';' would cut a
        // procedure into fragments, each of which fails on its own.
        $procedures = array_values(array_filter(
            self::split('pgsql'),
            fn (string $s): bool => str_contains($s, 'PROCEDURE revoke_member_relationship(')
        ));

        self::assertCount(1, $procedures);
        self::assertStringContainsString('UPDATE member_relationships', $procedures[0]);
        self::assertStringContainsString('INSERT INTO audit_log', $procedures[0]);
        self::assertStringContainsString('END;', $procedures[0]);
    }

    #[Test]
    public function it_keeps_a_mysql_procedure_body_whole(): void
    {
        $procedures = array_values(array_filter(
            self::split('mysql'),
            fn (string $s): bool => str_contains($s, 'PROCEDURE extend_member_relationship(')
        ));

        self::assertCount(1, $procedures);
        self::assertStringContainsString('UPDATE member_relationships', $procedures[0]);
        self::assertStringContainsString('INSERT INTO audit_log', $procedures[0]);
    }

    #[Test]
    public function postgres_semicolons_inside_string_literals_do_not_split(): void
    {
        $statements = SqlScriptSplitter::split(
            "INSERT INTO t (a) VALUES ('one; two'); INSERT INTO t (a) VALUES ('three');",
            'pgsql'
        );

        self::assertCount(2, $statements);
        self::assertStringContainsString('one; two', $statements[0]);
    }

    #[Test]
    public function sql_server_go_separator_is_matched_only_on_its_own_line(): void
    {
        // "GO" appears inside identifiers and words; only a line that is nothing but GO is a batch break.
        $statements = SqlScriptSplitter::split("SELECT 'ONGOING';\nGO\nSELECT 2;\n", 'sqlsrv');

        self::assertCount(2, $statements);
        self::assertStringContainsString('ONGOING', $statements[0]);
    }
}
