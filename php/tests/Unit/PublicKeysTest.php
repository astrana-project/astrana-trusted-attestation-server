<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PublicKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PublicKeysTest extends TestCase
{
    private static function bytes(int $length): string
    {
        $value = '';
        for ($i = 0; $i < $length; $i++) {
            $value .= chr(($i + 1) % 256);
        }

        return $value;
    }

    #[Test]
    public function it_accepts_a_32_byte_key(): void
    {
        $key = self::bytes(32);

        self::assertSame($key, PublicKeys::parse(base64_encode($key)));
    }

    public static function wrongLengths(): array
    {
        return [[0], [16], [31], [33], [64]];
    }

    #[Test]
    #[DataProvider('wrongLengths')]
    public function it_rejects_any_length_but_32(int $length): void
    {
        self::assertNull(PublicKeys::parse(base64_encode(self::bytes($length))));
    }

    public static function notBase64(): array
    {
        return [[null], [''], ['   '], ['not base64 at all!!'], ['AAAA%%%%']];
    }

    #[Test]
    #[DataProvider('notBase64')]
    public function it_rejects_anything_that_is_not_base64(?string $value): void
    {
        self::assertNull(PublicKeys::parse($value));
    }

    #[Test]
    public function it_rejects_the_all_zero_key(): void
    {
        // Not a valid Ed25519 point, and the likeliest artefact of a client that "successfully" produced
        // an empty key.
        self::assertNull(PublicKeys::parse(base64_encode(str_repeat("\0", 32))));
    }

    /**
     * public_key arrives from request JSON and can be any JSON type. Before this was handled here, a
     * number or an array reached parse() and PHP raised a TypeError, so hostile input got a 500 out of
     * an endpoint whose contract defines no 500 -- and out of the anonymous one at that.
     */
    #[Test]
    #[DataProvider('nonStringValues')]
    public function it_rejects_values_that_are_not_strings(mixed $value): void
    {
        self::assertNull(PublicKeys::parse($value));
    }

    /** @return iterable<string, array{mixed}> */
    public static function nonStringValues(): iterable
    {
        yield 'integer' => [12345];
        yield 'float' => [1.5];
        yield 'boolean' => [true];
        yield 'array' => [['a' => 1]];
        yield 'list' => [[1, 2, 3]];
        yield 'object' => [(object) ['a' => 1]];
        yield 'null' => [null];
    }

    #[Test]
    public function it_round_trips_through_base64(): void
    {
        $key = self::bytes(32);

        self::assertSame($key, PublicKeys::parse(PublicKeys::toBase64($key)));
    }

    #[Test]
    public function it_accepts_only_the_canonical_encoding_of_a_key(): void
    {
        // One key, several encodings a decoder might tolerate. Only the padded, standard-alphabet form
        // the system emits is a key here -- the same rule .NET and Java enforce, so a key accepted by
        // one is accepted by all three. base64_decode would otherwise skip the space and accept the
        // unpadded form, letting a key in that the other two refuse.
        $canonical = '+/A/ECAwQFBgcICQoLDA0ODwAQIDBAUGBwgJCgsMDf8=';
        self::assertNotNull(PublicKeys::parse($canonical));

        self::assertNull(PublicKeys::parse(rtrim($canonical, '=')));
        self::assertNull(PublicKeys::parse(strtr($canonical, '+/', '-_')));
        self::assertNull(PublicKeys::parse(substr($canonical, 0, 4).' '.substr($canonical, 4)));
        self::assertNull(PublicKeys::parse(' '.$canonical));
    }
}
