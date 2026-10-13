<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PublicKeyBody;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading public_key from a body, with the limits the contract sets on what the body may hold. The routes
 * that use it are driven in the Feature tests.
 */
final class PublicKeyBodyTest extends TestCase
{
    #[Test]
    public function it_reads_the_key_as_sent(): void
    {
        self::assertSame('abc', PublicKeyBody::publicKey('{"public_key":"abc"}'));
        self::assertSame(1234, PublicKeyBody::publicKey('{"public_key":1234}'));
    }

    /** @return iterable<string, array{string}> */
    public static function bodiesWithNoKey(): iterable
    {
        yield 'an empty body' => [''];
        yield 'a string' => ['"abc"'];
        yield 'an array' => ['["abc"]'];
        yield 'no public_key' => ['{"other":"abc"}'];
        yield 'nested 65 deep' => ['{"public_key":"abc","n":'.str_repeat('[', 64).str_repeat(']', 64).'}'];
        yield 'a number of 1,001 digits' => ['{"public_key":"abc","n":'.str_repeat('1', 1001).'}'];
    }

    #[Test]
    #[DataProvider('bodiesWithNoKey')]
    public function it_reads_no_key_from_a_body_that_has_none_or_is_malformed(string $body): void
    {
        self::assertNull(PublicKeyBody::publicKey($body));
    }

    #[Test]
    public function digits_inside_strings_are_not_a_number(): void
    {
        $digits = str_repeat('1', 1001);

        self::assertSame('abc', PublicKeyBody::publicKey('{"public_key":"abc","'.$digits.'":"\"'.$digits.'"}'));
    }

    #[Test]
    public function it_reads_a_body_nested_64_deep_counting_its_own_object(): void
    {
        self::assertSame('abc', PublicKeyBody::publicKey('{"public_key":"abc","n":'.str_repeat('[', 63).str_repeat(']', 63).'}'));
    }
}
