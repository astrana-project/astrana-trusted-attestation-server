<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contract\RelationshipTypeCatalog;
use App\Services\ManifestBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The manifests in shared/test-material/invalid-manifests.json, which all three implementations' validators
 * must refuse, so that the three refuse the same ones. Read from the repository's single copy, not synced.
 */
final class SharedInvalidManifestsTest extends TestCase
{
    private static function material(): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__, 3).'/shared/test-material/invalid-manifests.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
    }

    private function builder(): ManifestBuilder
    {
        return new ManifestBuilder(
            new RelationshipTypeCatalog(dirname(__DIR__, 2).'/contract/relationship-types.json')
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidCases(): iterable
    {
        foreach (self::material()['invalid'] as $invalid) {
            yield $invalid['case'] => [$invalid];
        }
    }

    #[Test]
    public function the_shared_valid_manifest_is_accepted(): void
    {
        self::assertSame([], $this->builder()->validate(self::material()['valid']));
    }

    /** @param array<string, mixed> $invalid */
    #[Test]
    #[DataProvider('invalidCases')]
    public function each_shared_invalid_manifest_is_refused_for_its_own_reason(array $invalid): void
    {
        $manifest = array_merge(self::material()['valid'], $invalid['set'] ?? []);
        foreach ($invalid['remove'] ?? [] as $field) {
            unset($manifest[$field]);
        }

        $matching = array_filter(
            $this->builder()->validate($manifest),
            static fn (string $error): bool => array_all(
                $invalid['error'],
                static fn (string $text): bool => str_contains($error, $text)
            )
        );

        self::assertNotEmpty($matching, 'no error contains '.json_encode($invalid['error']));
    }
}
