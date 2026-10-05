<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contract\Attribution;
use App\Contract\RelationshipTypeCatalog;
use App\Providers\TrustedAttestationServiceProvider;
use App\Services\LogoData;
use App\Services\ManifestBuilder;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * What an operator is told when the app refuses to start.
 *
 * These messages are the entire diagnosis: the app is not running, there is nothing to inspect, and the
 * text is all there is to work from. They had no tests at all, which is the wrong way round -- this
 * session found more defects in failure paths than in the happy path, and a message that stops naming
 * the file it is about is a change nothing else would notice.
 *
 * A Feature test rather than a Unit one because the loaders resolve their default path through
 * base_path(), which needs the application container. Each test passes its own path, so nothing here
 * touches the real contract files.
 */
final class StartupRefusalTest extends TestCase
{
    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function file(string $contents, string $extension = 'json'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ata').'.'.$extension;
        file_put_contents($path, $contents);
        $this->written[] = $path;

        return $path;
    }

    // ---------------------------------------------------------------------------------------------
    // The governed vocabulary
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_missing_relationship_types_file_names_itself_and_says_where_it_comes_from(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/relationship-types\.json.*could not be read/s');

        new RelationshipTypeCatalog('/no/such/relationship-types.json');
    }

    #[Test]
    public function an_unparseable_relationship_types_file_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not valid, or defines no types/');

        new RelationshipTypeCatalog($this->file('{ this is not json'));
    }

    #[Test]
    public function a_relationship_type_with_no_id_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/type with no id/');

        new RelationshipTypeCatalog($this->file('{"types":[{"label":"Employee"}]}'));
    }

    #[Test]
    public function a_duplicated_relationship_type_is_refused(): void
    {
        // A duplicate would make the governed vocabulary ambiguous, and the drift check across the four
        // artefacts compares counts. Catching it at load is what keeps that comparison meaningful.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/more than once/');

        new RelationshipTypeCatalog($this->file(
            '{"types":[{"id":"employee","label":"E"},{"id":"employee","label":"E"}]}'
        ));
    }

    #[Test]
    public function an_empty_relationship_types_file_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/defines no types/');

        new RelationshipTypeCatalog($this->file('{"types":[]}'));
    }

    // ---------------------------------------------------------------------------------------------
    // The attribution footer
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_missing_attribution_file_names_itself(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/attribution\.json.*could not be read/s');

        new Attribution('/no/such/attribution.json');
    }

    #[Test]
    public function an_unparseable_attribution_file_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/is not valid JSON/');

        new Attribution($this->file('nonsense'));
    }

    #[Test]
    public function an_attribution_file_missing_the_project_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must name the project and link to it/');

        new Attribution($this->file('{"project_name":"Astrana"}'));
    }

    // ---------------------------------------------------------------------------------------------
    // The org logo
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_logo_path_that_does_not_exist_names_the_path_and_the_setting(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not exist.*TRUSTED_ATTESTATION_MANIFEST_LOGO/s');

        LogoData::resolve('/no/such/logo.png');
    }

    #[Test]
    public function a_missing_dark_logo_names_the_dark_setting(): void
    {
        // The manifest builder passes the setting each logo came from, so an operator who mistyped only
        // the dark logo's path is not sent to the light one.
        config(['trusted_attestation.manifest.logo_data_dark' => '/no/such/dark-logo.png']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/dark-logo\.png" does not exist\. TRUSTED_ATTESTATION_MANIFEST_LOGO_DARK takes/');

        $this->app->make(ManifestBuilder::class)->build();
    }

    #[Test]
    public function a_logo_with_an_unsupported_extension_lists_what_is_supported(): void
    {
        // Telling an operator that .bmp is unsupported without saying what is supported leaves them
        // guessing, so the message carries the list.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unsupported extension.*Supported:/s');

        LogoData::resolve($this->file('not really an image', 'bmp'));
    }

    #[Test]
    public function a_configured_logo_is_encoded_as_a_data_uri(): void
    {
        // The happy path alongside the failures, because the point of the field is that the logo is
        // embedded rather than fetched: a manifest reader must never have to call back to the org.
        $resolved = LogoData::resolve($this->file('pretend-png-bytes', 'png'));

        self::assertStringStartsWith('data:image/png;base64,', (string) $resolved);
        self::assertSame('pretend-png-bytes', base64_decode(
            substr((string) $resolved, strlen('data:image/png;base64,')),
            true
        ));
    }

    #[Test]
    public function an_absent_logo_is_simply_absent(): void
    {
        // logo_data is optional. An org that configures none should get a manifest without the field,
        // not a startup failure.
        self::assertNull(LogoData::resolve(null));
    }

    // ---------------------------------------------------------------------------------------------
    // Settings that did not parse
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_setting_that_did_not_parse_refuses_every_request_naming_the_setting(): void
    {
        // The config file records each unparseable value; the provider turns the record into a refusal.
        // The provider's boot returns early under the test runner, so the check is driven directly.
        config(['trusted_attestation.configuration_problems' => [
            'TRUSTED_ATTESTATION_CREATE_SCHEMA must be true or false, was "no".',
        ]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/could not be read.*TRUSTED_ATTESTATION_CREATE_SCHEMA must be true or false, was "no"/s');

        $provider = new TrustedAttestationServiceProvider($this->app);
        (new ReflectionMethod($provider, 'refuseUnparsedSettings'))->invoke($provider);
    }

    #[Test]
    public function the_configuration_file_records_a_value_that_does_not_parse_and_keeps_the_default(): void
    {
        // End to end through the real config file: an environment value of "abc" for the retention leaves
        // the default in place and the complaint on record, so a cast never turns it into zero days.
        $_ENV['TRUSTED_ATTESTATION_AUDIT_RETENTION_DAYS'] = 'abc';
        $_ENV['TRUSTED_ATTESTATION_AUDIT_PRUNE_ENABLED'] = 'no';

        try {
            $this->refreshApplication();

            self::assertSame(1825, config('trusted_attestation.audit.retention_days'));
            self::assertFalse(config('trusted_attestation.audit.prune_enabled'));
            self::assertSame(
                ['TRUSTED_ATTESTATION_AUDIT_RETENTION_DAYS must be a whole number from 1 to 36500, was "abc".'],
                config('trusted_attestation.configuration_problems'),
            );
        } finally {
            unset($_ENV['TRUSTED_ATTESTATION_AUDIT_RETENTION_DAYS'], $_ENV['TRUSTED_ATTESTATION_AUDIT_PRUNE_ENABLED']);
        }
    }
}
