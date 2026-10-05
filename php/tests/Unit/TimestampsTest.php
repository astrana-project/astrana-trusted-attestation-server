<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Timestamps;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The one way an expiry is written on the API and the page, on all three implementations.
 *
 * Carbon's own Zulu string drops the fraction of a second, so an expiry granted at half past the second
 * read differently here and on the .NET implementation. Every shape of fraction is pinned by value.
 */
final class TimestampsTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function moments(): iterable
    {
        yield 'a whole second has no fraction' => ['2030-06-01 12:00:00.000000', '2030-06-01T12:00:00Z'];
        yield 'trailing zeros are trimmed' => ['2030-06-01 12:00:00.500000', '2030-06-01T12:00:00.5Z'];
        yield 'milliseconds' => ['2030-06-01 12:00:00.123000', '2030-06-01T12:00:00.123Z'];
        yield 'microseconds are kept in full' => ['2030-06-01 12:00:00.123456', '2030-06-01T12:00:00.123456Z'];
        yield 'a single trailing digit' => ['2030-06-01 12:00:00.000001', '2030-06-01T12:00:00.000001Z'];
    }

    #[Test]
    #[DataProvider('moments')]
    public function a_utc_moment_is_written_with_its_fraction_trimmed(string $moment, string $expected): void
    {
        self::assertSame($expected, Timestamps::iso8601Utc(CarbonImmutable::parse($moment, 'UTC')));
    }

    #[Test]
    public function a_moment_in_another_zone_is_written_in_utc(): void
    {
        self::assertSame('2030-06-01T10:00:00.25Z', Timestamps::iso8601Utc(CarbonImmutable::parse('2030-06-01 12:00:00.250000', 'Europe/Paris')));
    }

    #[Test]
    public function a_mutable_carbon_is_not_changed_by_being_written(): void
    {
        $moment = Carbon::parse('2030-06-01 12:00:00', 'Europe/Paris');

        Timestamps::iso8601Utc($moment);

        self::assertSame('Europe/Paris', $moment->getTimezone()->getName());
    }
}
