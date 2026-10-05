<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Reads and writes the binary public_key column across the three engines.
 *
 * The key is stored as its 32 raw bytes (decision record 29 in docs/adr), against 44 characters for base64 text and
 * 64 for hexadecimal, which keeps the one index the verification path reads small and more of it in
 * memory. PDO, though, has no portable way to bind a raw binary string to a binary column. On PostgreSQL
 * a bytea parameter sent as text fails the moment the key contains a byte that is not valid UTF-8, which
 * for random 32-byte keys is almost always.
 *
 * So the key crosses the driver boundary as hexadecimal, and each engine converts it back to binary in
 * SQL. The column stays binary, which is the part that matters. Only the transport is text, and
 * hexadecimal is exact.
 */
final class BinaryColumn
{
    /** SQL fragment that turns a bound hex parameter into the binary value to store. */
    public static function bindExpression(string $driver): string
    {
        return match ($driver) {
            'pgsql' => "decode(?, 'hex')",
            'mysql', 'mariadb' => 'UNHEX(?)',
            'sqlsrv' => 'CONVERT(varbinary(32), ?, 2)',
            default => throw new InvalidArgumentException("Unsupported database driver '{$driver}'."),
        };
    }

    /** SQL fragment that reads a binary column back out as hex. */
    public static function readExpression(string $driver, string $column): string
    {
        return match ($driver) {
            'pgsql' => "encode({$column}, 'hex')",
            'mysql', 'mariadb' => "HEX({$column})",
            'sqlsrv' => "CONVERT(varchar(64), {$column}, 2)",
            default => throw new InvalidArgumentException("Unsupported database driver '{$driver}'."),
        };
    }

    /** Raw bytes to the hex a bind expression expects. */
    public static function toHex(string $raw): string
    {
        return bin2hex($raw);
    }

    /** Hex from a read expression back to raw bytes. MySQL returns uppercase, the others lowercase. */
    public static function fromHex(?string $hex): ?string
    {
        if ($hex === null || $hex === '') {
            return null;
        }

        $decoded = @hex2bin(strtolower($hex));

        return $decoded === false ? null : $decoded;
    }
}
