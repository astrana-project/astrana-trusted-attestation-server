<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\BinaryColumn;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How a 32-byte key crosses the PDO boundary.
 *
 * PDO has no portable way to bind raw binary, so the key travels as hex and each engine converts it in
 * SQL. Every query touching public_key goes through these four functions, which makes them the narrowest
 * point in the entire data path: a mistake here corrupts every key on one engine while the other two
 * stay green -- exactly the shape of divergence the engine matrix exists to catch, except this one would
 * corrupt data rather than fail a check.
 */
final class BinaryColumnTest extends TestCase
{
    // ---------------------------------------------------------------------------------------------
    // The round trip
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function raw_bytes_survive_the_hex_round_trip(): void
    {
        $key = random_bytes(32);

        self::assertSame($key, BinaryColumn::fromHex(BinaryColumn::toHex($key)));
    }

    #[Test]
    public function bytes_that_are_not_utf8_survive_too(): void
    {
        // The reason hex transport exists at all: a bytea parameter sent as text fails the moment the
        // key contains a byte that is not valid UTF-8, which for random 32-byte keys is almost always.
        $awkward = str_repeat("\xFF\x00", 16);

        self::assertSame($awkward, BinaryColumn::fromHex(BinaryColumn::toHex($awkward)));
    }

    #[Test]
    public function mysql_uppercase_hex_decodes_the_same_as_lowercase(): void
    {
        // HEX() on MySQL returns uppercase where the other two engines return lowercase. A decoder that
        // only handled one casing would read every key as null on one engine -- silently, since null is
        // also what "no key set" looks like.
        $key = random_bytes(32);
        $lower = BinaryColumn::toHex($key);

        self::assertSame($key, BinaryColumn::fromHex(strtoupper($lower)));
        self::assertSame(BinaryColumn::fromHex($lower), BinaryColumn::fromHex(strtoupper($lower)));
    }

    #[Test]
    public function null_and_empty_read_back_as_no_key(): void
    {
        // What an unkeyed relationship's row produces. Null must mean "no key", not an error, because
        // granted-but-unkeyed is the normal state right after grant_member_relationship.
        self::assertNull(BinaryColumn::fromHex(null));
        self::assertNull(BinaryColumn::fromHex(''));
    }

    #[Test]
    public function hex_that_does_not_decode_is_null_rather_than_garbage(): void
    {
        // Corrupt data coming out of the column should read as absent, not as a mangled key that then
        // fails to match anything while looking plausible.
        self::assertNull(BinaryColumn::fromHex('not hex'));
        self::assertNull(BinaryColumn::fromHex('abc'));  // odd length
    }

    // ---------------------------------------------------------------------------------------------
    // The per-engine SQL
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function each_engine_gets_its_own_spelling(): void
    {
        // The values are checked exactly, not just for shape: these strings are concatenated into
        // queries, and the difference between decode and UNHEX is the difference between a query that
        // runs and one that errors on two of the three engines.
        self::assertSame("decode(?, 'hex')", BinaryColumn::bindExpression('pgsql'));
        self::assertSame('UNHEX(?)', BinaryColumn::bindExpression('mysql'));
        self::assertSame('CONVERT(varbinary(32), ?, 2)', BinaryColumn::bindExpression('sqlsrv'));

        self::assertSame("encode(k, 'hex')", BinaryColumn::readExpression('pgsql', 'k'));
        self::assertSame('HEX(k)', BinaryColumn::readExpression('mysql', 'k'));
        self::assertSame('CONVERT(varchar(64), k, 2)', BinaryColumn::readExpression('sqlsrv', 'k'));
    }

    #[Test]
    public function mariadb_is_spelled_like_mysql(): void
    {
        // Laravel reports mariadb as its own driver name since 11.x. Treating it as unsupported would
        // refuse a database the MySQL schema runs on unchanged.
        self::assertSame(BinaryColumn::bindExpression('mysql'), BinaryColumn::bindExpression('mariadb'));
        self::assertSame(
            BinaryColumn::readExpression('mysql', 'k'),
            BinaryColumn::readExpression('mariadb', 'k')
        );
    }

    #[Test]
    public function every_bind_expression_carries_exactly_one_placeholder(): void
    {
        // The caller binds exactly one value against this fragment. An expression with none would shift
        // every later parameter in the query one place left -- the kind of off-by-one that binds the
        // subject where the key should be and produces a wrong row rather than an error.
        foreach (['pgsql', 'mysql', 'mariadb', 'sqlsrv'] as $driver) {
            self::assertSame(1, substr_count(BinaryColumn::bindExpression($driver), '?'), $driver);
        }
    }

    #[Test]
    public function an_unknown_driver_is_refused_by_name(): void
    {
        // Loudly, at the first query, naming the driver -- not a silently wrong expression that corrupts
        // whatever engine somebody pointed this at.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sqlite');

        BinaryColumn::bindExpression('sqlite');
    }

    #[Test]
    public function an_unknown_driver_is_refused_for_reads_too(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BinaryColumn::readExpression('sqlite', 'public_key');
    }
}
