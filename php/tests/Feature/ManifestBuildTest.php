<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ManifestBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * How the served manifest is built from configuration, for the fields that must be present or absent by
 * shape rather than by value.
 *
 * The rule pinned here is the empty-to-absent one applied to the two logos: a configured logo arrives in
 * the manifest already resolved to a data URI, and an unconfigured one is omitted entirely rather than
 * serialised as null -- exactly the way the light logo has always behaved, now mirrored for the dark
 * variant. A Feature test rather than a Unit one because build() reads config() through the container.
 */
final class ManifestBuildTest extends TestCase
{
    /** A one-pixel PNG. The rule under test is the shape of the value, not the image. */
    private const LOGO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private const LOGO_DARK = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciLz4=';

    #[Test]
    public function a_configured_dark_logo_appears_in_the_manifest(): void
    {
        config([
            'trusted_attestation.manifest.logo_data' => self::LOGO,
            'trusted_attestation.manifest.logo_data_dark' => self::LOGO_DARK,
        ]);

        $manifest = $this->app->make(ManifestBuilder::class)->build();

        self::assertArrayHasKey('logo_data', $manifest);
        self::assertArrayHasKey('logo_data_dark', $manifest);
        self::assertSame(self::LOGO_DARK, $manifest['logo_data_dark']);
    }

    #[Test]
    public function an_unconfigured_dark_logo_is_omitted_rather_than_null(): void
    {
        // The empty-to-absent rule: a member who prefers dark simply gets the light logo, and no consumer
        // has to special-case a logo_data_dark that is present but null.
        config([
            'trusted_attestation.manifest.logo_data' => self::LOGO,
            'trusted_attestation.manifest.logo_data_dark' => null,
        ]);

        $manifest = $this->app->make(ManifestBuilder::class)->build();

        self::assertArrayHasKey('logo_data', $manifest);
        self::assertArrayNotHasKey('logo_data_dark', $manifest);
    }

    #[Test]
    public function a_configured_support_url_appears_in_the_manifest(): void
    {
        $supportUrl = ['en' => 'https://acmebank.example/support'];

        config(['trusted_attestation.manifest.support_url' => $supportUrl]);

        $manifest = $this->app->make(ManifestBuilder::class)->build();

        self::assertArrayHasKey('support_url', $manifest);
        self::assertSame($supportUrl, $manifest['support_url']);
    }

    #[Test]
    public function an_unconfigured_support_url_is_omitted_rather_than_empty(): void
    {
        // The same empty-to-absent rule the other optional fields follow: a support_url that was never
        // configured is left out of the served document entirely, not serialised as an empty object.
        config(['trusted_attestation.manifest.support_url' => []]);

        $manifest = $this->app->make(ManifestBuilder::class)->build();

        self::assertArrayNotHasKey('support_url', $manifest);
    }
}
