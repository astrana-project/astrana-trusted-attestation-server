<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The PHP extensions the application needs are declared to Composer, so that an installation on a PHP
 * build without one fails at composer install rather than at the first request that reaches it. Both
 * composer.json and the platform block of composer.lock are checked, because a lock that was not refreshed
 * after composer.json changed still describes the old requirements.
 */
final class PlatformRequirementsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function extensions(): iterable
    {
        // intl orders language names in the language switcher the way the other implementations do.
        yield 'intl' => ['ext-intl'];
        // OneLogin's IdPMetadataParser fetches the identity provider's metadata with curl.
        yield 'curl' => ['ext-curl'];
    }

    #[Test]
    #[DataProvider('extensions')]
    public function the_extension_is_required_by_composer_json_and_the_lock(string $extension): void
    {
        self::assertArrayHasKey($extension, self::json('composer.json')['require']);
        self::assertArrayHasKey($extension, self::json('composer.lock')['platform']);
    }

    /** @return array<string, mixed> */
    private static function json(string $file): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/'.$file), true, flags: JSON_THROW_ON_ERROR);
    }
}
