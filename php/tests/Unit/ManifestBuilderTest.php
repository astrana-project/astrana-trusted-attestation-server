<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contract\RelationshipTypeCatalog;
use App\Services\ManifestBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ManifestBuilderTest extends TestCase
{
    private function builder(): ManifestBuilder
    {
        return new ManifestBuilder(
            new RelationshipTypeCatalog(dirname(__DIR__, 2).'/contract/relationship-types.json')
        );
    }

    /** @param array<string, mixed> $overrides */
    private static function manifest(array $overrides = []): array
    {
        return array_merge([
            'manifest_version' => 1,
            'default_locale' => 'en',
            'name' => ['en' => 'Acme Bank', 'fr' => 'Banque Acme'],
            'description' => ['en' => 'Retail and commercial banking'],
            'website' => ['en' => 'https://acmebank.example'],
            'privacy_notice_url' => ['en' => 'https://acmebank.example/privacy'],
            'jurisdictions' => ['US', 'US-NY'],
            'relationship_types' => ['employee', 'client'],
            'enrollment_url' => 'https://ata.acmebank.example/me',
            'attestation_url' => 'https://ata.acmebank.example/api/v1/attest',
        ], $overrides);
    }

    #[Test]
    public function it_accepts_the_specs_own_example(): void
    {
        self::assertSame([], $this->builder()->validate(self::manifest()));
    }

    #[Test]
    public function it_accepts_a_manifest_with_only_the_required_fields(): void
    {
        $manifest = self::manifest();
        unset($manifest['description'], $manifest['website'], $manifest['privacy_notice_url'],
            $manifest['jurisdictions']);

        self::assertSame([], $this->builder()->validate($manifest));
    }

    #[Test]
    public function it_rejects_a_relationship_type_outside_the_governed_enum(): void
    {
        $errors = $this->builder()->validate(
            self::manifest(['relationship_types' => ['employee', 'patient']])
        );

        self::assertNotEmpty(array_filter($errors, fn (string $e): bool => str_contains($e, 'patient')));
    }

    #[Test]
    public function it_rejects_a_manifest_that_attests_to_nothing(): void
    {
        $errors = $this->builder()->validate(self::manifest(['relationship_types' => []]));

        self::assertNotEmpty(array_filter($errors, fn (string $e): bool => str_contains($e, 'relationship_types')));
    }

    #[Test]
    public function it_rejects_a_missing_default_locale_entry(): void
    {
        // If default_locale names a locale that is not present, the fallback rule has nowhere to land.
        $errors = $this->builder()->validate(
            self::manifest(['default_locale' => 'de', 'name' => ['en' => 'Acme Bank']])
        );

        self::assertNotEmpty(array_filter($errors, fn (string $e): bool => str_contains($e, 'default locale')));
    }

    public static function badAttestationUrls(): array
    {
        return [['http://a.example/api/v1/attest'], ['/api/v1/attest'], ['']];
    }

    #[Test]
    #[DataProvider('badAttestationUrls')]
    public function it_rejects_an_attestation_url_that_is_not_absolute_https(string $url): void
    {
        $errors = $this->builder()->validate(self::manifest(['attestation_url' => $url]));

        self::assertNotEmpty(array_filter($errors, fn (string $e): bool => str_contains($e, 'attestation_url')));
    }

    public static function badEnrollmentUrls(): array
    {
        return [['http://a.example/me'], ['/me'], ['']];
    }

    #[Test]
    #[DataProvider('badEnrollmentUrls')]
    public function it_rejects_an_enrollment_url_that_is_not_absolute_https(string $url): void
    {
        // The same rule and the same code as attestation_url -- and, until this test, the same code with
        // no test: dropping the enrollment_url check broke nothing. A prospective member follows this URL.
        $errors = $this->builder()->validate(self::manifest(['enrollment_url' => $url]));

        self::assertNotEmpty(array_filter($errors, fn (string $e): bool => str_contains($e, 'enrollment_url')));
    }

    #[Test]
    public function it_rejects_an_unexpected_manifest_version(): void
    {
        $errors = $this->builder()->validate(self::manifest(['manifest_version' => 2]));

        self::assertNotEmpty(array_filter($errors, fn (string $e): bool => str_contains($e, 'manifest_version')));
    }

    public static function badJurisdictions(): array
    {
        return [['USA'], ['us'], ['US-NEWYORK']];
    }

    #[Test]
    #[DataProvider('badJurisdictions')]
    public function it_rejects_a_jurisdiction_that_is_not_an_iso_code(string $code): void
    {
        $errors = $this->builder()->validate(self::manifest(['jurisdictions' => [$code]]));

        self::assertNotEmpty(array_filter($errors, fn (string $e): bool => str_contains($e, 'jurisdictions')));
    }

    /** A one-pixel PNG. The rule under test is the shape of the value, not the image. */
    private const LOGO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    #[Test]
    public function it_accepts_an_embedded_logo(): void
    {
        self::assertSame([], $this->builder()->validate(self::manifest(['logo_data' => self::LOGO])));
    }

    #[Test]
    public function it_accepts_a_manifest_with_no_logo_at_all(): void
    {
        self::assertSame([], $this->builder()->validate(self::manifest()));
    }

    #[Test]
    public function it_rejects_a_logo_that_is_a_url_rather_than_embedded(): void
    {
        // A link would put the request back on the org's server, which is what embedding the image
        // exists to avoid.
        $errors = $this->builder()->validate(
            self::manifest(['logo_data' => 'https://acmebank.example/logo.png'])
        );

        self::assertNotEmpty(array_filter($errors, fn (string $e): bool => str_contains($e, 'logo_data')));
    }

    #[Test]
    public function it_rejects_a_logo_that_is_not_an_image(): void
    {
        $errors = $this->builder()->validate(
            self::manifest(['logo_data' => 'data:text/html;base64,PGgxPmhpPC9oMT4='])
        );

        self::assertNotEmpty(array_filter($errors, fn (string $e): bool => str_contains($e, 'logo_data')));
    }

    #[Test]
    public function it_accepts_an_embedded_dark_logo_alongside_a_light_one(): void
    {
        self::assertSame([], $this->builder()->validate(
            self::manifest(['logo_data' => self::LOGO, 'logo_data_dark' => self::LOGO])
        ));
    }

    #[Test]
    public function it_rejects_a_dark_logo_that_is_a_url_rather_than_embedded(): void
    {
        // The dark variant obeys the same embedded-only rule as the light logo.
        $errors = $this->builder()->validate(
            self::manifest(['logo_data' => self::LOGO, 'logo_data_dark' => 'https://acmebank.example/logo-dark.png'])
        );

        self::assertNotEmpty(array_filter($errors, fn (string $e): bool => str_contains($e, 'logo_data_dark')));
    }

    #[Test]
    public function it_rejects_a_dark_logo_set_without_a_light_one(): void
    {
        // The light logo is the default and the fallback, so a dark-only logo would never be shown.
        $manifest = self::manifest(['logo_data_dark' => self::LOGO]);
        unset($manifest['logo_data']);

        $errors = $this->builder()->validate($manifest);

        self::assertNotEmpty(array_filter(
            $errors,
            fn (string $e): bool => str_contains($e, 'logo_data_dark is set without logo_data')
        ));
    }

    /**
     * A well-formed data URI of exactly the given length, so only the length is under test.
     */
    private static function logoOfLength(int $length): string
    {
        $prefix = 'data:image/png;base64,';

        return $prefix.str_repeat('A', $length - strlen($prefix));
    }

    /** @return iterable<string, array{string}> */
    public static function logoFields(): iterable
    {
        yield 'light logo' => ['logo_data'];
        yield 'dark logo' => ['logo_data_dark'];
    }

    #[Test]
    #[DataProvider('logoFields')]
    public function a_logo_of_exactly_the_schemas_limit_is_accepted(string $field): void
    {
        $manifest = self::manifest(['logo_data' => self::LOGO, $field => self::logoOfLength(ManifestBuilder::MAX_LOGO_LENGTH)]);

        self::assertSame([], $this->builder()->validate($manifest));
    }

    #[Test]
    #[DataProvider('logoFields')]
    public function a_logo_over_the_schemas_limit_is_refused_naming_the_field(string $field): void
    {
        // The schema caps each logo at 65,536 characters, because every verifying instance fetches the
        // manifest and the logo inflates it directly.
        $manifest = self::manifest(['logo_data' => self::LOGO, $field => self::logoOfLength(ManifestBuilder::MAX_LOGO_LENGTH + 1)]);

        self::assertSame(
            [$field.' is 65537 characters, over the limit of 65536: keep the logo icon-sized, because it '
                .'inflates the manifest directly.'],
            $this->builder()->validate($manifest)
        );
    }

    #[Test]
    public function repeated_jurisdictions_are_refused(): void
    {
        $errors = $this->builder()->validate(self::manifest(['jurisdictions' => ['US', 'US-NY', 'US']]));

        self::assertSame(['jurisdictions contains duplicates.'], $errors);
    }

    #[Test]
    public function jurisdictions_differing_only_in_case_are_not_duplicates_but_the_lower_case_one_is_refused(): void
    {
        // Compared exactly, as the schema's uniqueItems compares them. "us" is refused for its form alone.
        $errors = $this->builder()->validate(self::manifest(['jurisdictions' => ['US', 'us']]));

        self::assertSame(
            ['jurisdictions contains "us", which is not an ISO 3166-1 alpha-2 or ISO 3166-2 subdivision code.'],
            $errors
        );
    }

    #[Test]
    public function it_rejects_duplicate_relationship_types(): void
    {
        $errors = $this->builder()->validate(
            self::manifest(['relationship_types' => ['employee', 'employee']])
        );

        self::assertNotEmpty(array_filter($errors, fn (string $e): bool => str_contains($e, 'duplicates')));
    }

    #[Test]
    public function it_accepts_a_support_url(): void
    {
        // Optional and locale-keyed exactly like website: a valid entry is no error.
        self::assertSame([], $this->builder()->validate(
            self::manifest(['support_url' => ['en' => 'https://acmebank.example/support']])
        ));
    }

    #[Test]
    public function it_accepts_a_manifest_with_no_support_url(): void
    {
        // Absent is the ordinary case -- the field is optional, and the empty-state falls back to website.
        $errors = $this->builder()->validate(self::manifest());

        self::assertEmpty(array_filter($errors, fn (string $e): bool => str_contains($e, 'support_url')));
    }

    #[Test]
    public function it_rejects_a_support_url_that_is_not_https(): void
    {
        // The same absolute-https rule website is held to; the complaint names the field it is about.
        $errors = $this->builder()->validate(
            self::manifest(['support_url' => ['en' => 'http://acmebank.example/support']])
        );

        self::assertNotEmpty(array_filter($errors, fn (string $e): bool => str_contains($e, 'support_url')));
    }
}
