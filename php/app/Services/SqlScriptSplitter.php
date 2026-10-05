<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Splits the canonical schema/*.sql scripts into statements a PDO driver will accept.
 *
 * The scripts are written to be run by each engine's own command-line client, so they use directives no
 * driver understands: GO batch separators on SQL Server, and DELIMITER on MySQL. Rather than keep a
 * second, driver-friendly copy of the DDL -- one more place for the schema to drift from the contract --
 * the scripts ship verbatim and are split here.
 */
final class SqlScriptSplitter
{
    /** @return list<string> */
    public static function split(string $script, string $driver): array
    {
        return match ($driver) {
            'sqlsrv' => self::splitOnGo($script),
            'mysql', 'mariadb' => self::splitOnDelimiter($script),
            'pgsql' => self::splitOnSemicolon($script),
            default => throw new InvalidArgumentException("Unsupported database driver '{$driver}'."),
        };
    }

    /** SQL Server: batches are separated by a line containing only GO. @return list<string> */
    private static function splitOnGo(string $script): array
    {
        $statements = [];
        $batch = '';

        foreach (explode("\n", $script) as $line) {
            if (strcasecmp(trim($line), 'GO') === 0) {
                self::addIfMeaningful($statements, $batch);
                $batch = '';

                continue;
            }

            $batch .= $line."\n";
        }

        self::addIfMeaningful($statements, $batch);

        return $statements;
    }

    /**
     * MySQL: DELIMITER x changes the statement terminator so a procedure body's own semicolons do not end
     * the CREATE PROCEDURE. The directive is a client feature, never sent to the server.
     *
     * @return list<string>
     */
    private static function splitOnDelimiter(string $script): array
    {
        $statements = [];
        $current = '';
        $delimiter = ';';

        foreach (explode("\n", $script) as $rawLine) {
            $line = rtrim($rawLine, "\r");

            if (stripos(ltrim($line), 'DELIMITER') === 0) {
                // A pending statement cannot straddle a delimiter change.
                self::addIfMeaningful($statements, $current);
                $current = '';

                $value = trim(substr(trim($line), strlen('DELIMITER')));
                if ($value !== '') {
                    $delimiter = $value;
                }

                continue;
            }

            $current .= $line."\n";

            // Only looked for at the end of a line: that is where these scripts put it, and it avoids
            // tokenising procedure bodies for a terminator that is never mid-line here.
            $trimmed = rtrim($current);
            if ($delimiter !== '' && str_ends_with($trimmed, $delimiter)) {
                self::addIfMeaningful($statements, substr($trimmed, 0, -strlen($delimiter)));
                $current = '';
            }
        }

        self::addIfMeaningful($statements, $current);

        return $statements;
    }

    /**
     * PostgreSQL: split on semicolons that are not inside a string, a comment, or a dollar-quoted block.
     * Dollar quoting matters -- procedure bodies are wrapped in $$ ... $$ and full of semicolons.
     *
     * @return list<string>
     */
    private static function splitOnSemicolon(string $script): array
    {
        $statements = [];
        $current = '';
        $index = 0;
        $length = strlen($script);

        while ($index < $length) {
            $c = $script[$index];

            // Everything that can hold a semicolon without ending a statement is copied whole, so the
            // only semicolon this loop ever acts on is one in open SQL. A semicolon starts no span, so
            // this returns $index for it and the first branch takes over.
            $spanEnd = self::endOfProtectedSpan($script, $index);

            if ($c === ';') {
                self::addIfMeaningful($statements, $current);
                $current = '';
                $index++;
            } elseif ($spanEnd > $index) {
                $current .= substr($script, $index, $spanEnd - $index);
                $index = $spanEnd;
            } else {
                $current .= $c;
                $index++;
            }
        }

        self::addIfMeaningful($statements, $current);

        return $statements;
    }

    /**
     * The end of a span that has to be copied verbatim -- a comment, a string literal, or a
     * dollar-quoted block -- or $index itself when the character there begins none of them.
     *
     * Each of these can contain a semicolon that does not end a statement, which is the whole reason
     * this cannot simply split on the character. Procedure bodies are the case that matters: PostgreSQL
     * wraps them in $$ ... $$ and they are full of semicolons.
     */
    private static function endOfProtectedSpan(string $script, int $index): int
    {
        $c = $script[$index];
        $next = $script[$index + 1] ?? '';

        return match (true) {
            $c === '-' && $next === '-' => self::endOfLineComment($script, $index),
            $c === '/' && $next === '*' => self::endOfBlockComment($script, $index),
            $c === "'" => self::endOfQuotedLiteral($script, $index),
            default => self::endOfDollarQuoted($script, $index),
        };
    }

    /**
     * A dollar-quoted block: $tag$ ... $tag$, where tag may be empty. Returns $index when the character
     * there does not open one, which is what tells the caller this is ordinary SQL.
     */
    private static function endOfDollarQuoted(string $script, int $index): int
    {
        $tag = self::dollarTagAt($script, $index);
        if ($tag === null) {
            return $index;
        }

        $closing = strpos($script, $tag, $index + strlen($tag));

        return $closing === false ? strlen($script) : $closing + strlen($tag);
    }

    /** Runs to the end of the line, or to the end of the script when the last line is unterminated. */
    private static function endOfLineComment(string $script, int $index): int
    {
        $newline = strpos($script, "\n", $index);

        return $newline === false ? strlen($script) : $newline + 1;
    }

    /** An unterminated block comment runs to the end, rather than reopening as executable SQL. */
    private static function endOfBlockComment(string $script, int $index): int
    {
        $closing = strpos($script, '*/', $index + 2);

        return $closing === false ? strlen($script) : $closing + 2;
    }

    /** A single-quoted literal, in which a doubled quote is an escaped quote rather than the end. */
    private static function endOfQuotedLiteral(string $script, int $index): int
    {
        $length = strlen($script);
        $end = $index + 1;

        while ($end < $length) {
            if ($script[$end] === "'") {
                if (($script[$end + 1] ?? '') !== "'") {
                    return $end + 1;
                }

                // A doubled quote: step over the first here, and the shared advance below takes the
                // second, so the pair is consumed without either being read as the closing quote.
                $end++;
            }

            $end++;
        }

        // Unterminated. The caller copies to here rather than reopening the rest as executable SQL.
        return $end;
    }

    private static function dollarTagAt(string $script, int $start): ?string
    {
        if ($script[$start] !== '$') {
            return null;
        }

        $end = $start + 1;
        $length = strlen($script);

        while ($end < $length && preg_match('/[A-Za-z0-9_]/', $script[$end]) === 1) {
            $end++;
        }

        if ($end >= $length || $script[$end] !== '$') {
            return null;
        }

        return substr($script, $start, $end - $start + 1);
    }

    /** Drops statements that are only whitespace or comments -- nothing to execute. */
    private static function addIfMeaningful(array &$statements, string $statement): void
    {
        $trimmed = trim($statement);
        if ($trimmed === '') {
            return;
        }

        foreach (explode("\n", $trimmed) as $line) {
            $line = trim($line);
            if ($line !== '' && ! str_starts_with($line, '--')) {
                $statements[] = $trimmed;

                return;
            }
        }
    }
}
