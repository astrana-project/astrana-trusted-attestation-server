<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\EnvironmentSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How the typed settings are read from the environment.
 *
 * Laravel's env() converts only true, false, (true), (false), empty and null, so through a cast a boolean of
 * "no" would read as true, and a retention of "abc" as 0 days, which would prune every audit row. Instead,
 * each value that does not parse is a complaint naming the setting, and the default stands in until the
 * provider refuses the request. The other two implementations' configuration binders refuse the same
 * values, so a setting file that starts one of them starts this one, and one that stops one stops this one.
 */
final class EnvironmentSettingsTest extends TestCase
{
    /** @param array<string, mixed> $environment */
    private static function settings(array $environment): EnvironmentSettings
    {
        return new EnvironmentSettings(static fn (string $name): mixed => $environment[$name] ?? null);
    }

    // -- booleans ------------------------------------------------------------------------------------

    /** @return iterable<string, array{mixed, bool}> */
    public static function booleans(): iterable
    {
        yield '1' => ['1', true];
        yield 'yes' => ['yes', true];
        yield 'on' => ['on', true];
        yield 'TRUE' => ['TRUE', true];
        yield 'no' => ['no', false];
        yield 'off' => ['off', false];
        yield 'False with a trailing space' => ['False ', false];
        yield '0' => ['0', false];
        yield 'already a boolean, as env() converts true and false' => [true, true];
    }

    #[Test]
    #[DataProvider('booleans')]
    public function a_boolean_is_read_in_every_spelling_the_other_implementations_accept(mixed $raw, bool $expected): void
    {
        $settings = self::settings(['TRUSTED_ATTESTATION_AUDIT_PRUNE_ENABLED' => $raw]);

        self::assertSame($expected, $settings->bool('TRUSTED_ATTESTATION_AUDIT_PRUNE_ENABLED', ! $expected));
        self::assertSame([], $settings->problems());
    }

    #[Test]
    public function an_unset_boolean_is_its_default(): void
    {
        $settings = self::settings([]);

        self::assertTrue($settings->bool('TRUSTED_ATTESTATION_CREATE_SCHEMA', true));
        self::assertFalse($settings->bool('TRUSTED_ATTESTATION_TLS_TERMINATED_BY_PROXY', false));
        self::assertSame([], $settings->problems());
    }

    #[Test]
    public function a_boolean_that_does_not_parse_is_a_complaint_naming_the_setting(): void
    {
        // The case the cast got wrong: "maybe" is neither, and (bool) "maybe" is true.
        $settings = self::settings(['TRUSTED_ATTESTATION_CREATE_SCHEMA' => 'maybe']);

        self::assertTrue($settings->bool('TRUSTED_ATTESTATION_CREATE_SCHEMA', true));
        self::assertSame(
            ['TRUSTED_ATTESTATION_CREATE_SCHEMA must be true or false, was "maybe".'],
            $settings->problems(),
        );
    }

    // -- whole numbers -------------------------------------------------------------------------------

    #[Test]
    public function a_whole_number_in_range_is_read(): void
    {
        $settings = self::settings(['TRUSTED_ATTESTATION_AUDIT_RETENTION_DAYS' => ' 30 ']);

        self::assertSame(30, $settings->int('TRUSTED_ATTESTATION_AUDIT_RETENTION_DAYS', 1825, 1, 36500));
        self::assertSame([], $settings->problems());
    }

    /** @return iterable<string, array{string}> */
    public static function notRetentionDays(): iterable
    {
        yield 'text' => ['abc'];
        yield 'zero, which would prune every row' => ['0'];
        yield 'negative' => ['-5'];
        yield 'a fraction' => ['1.5'];
        yield 'above a century' => ['36501'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('notRetentionDays')]
    public function a_retention_that_is_not_a_whole_number_from_1_to_36500_is_refused(string $raw): void
    {
        $settings = self::settings(['TRUSTED_ATTESTATION_AUDIT_RETENTION_DAYS' => $raw]);

        self::assertSame(1825, $settings->int('TRUSTED_ATTESTATION_AUDIT_RETENTION_DAYS', 1825, 1, 36500));
        self::assertSame(
            ['TRUSTED_ATTESTATION_AUDIT_RETENTION_DAYS must be a whole number from 1 to 36500, was "'.$raw.'".'],
            $settings->problems(),
        );
    }

    #[Test]
    public function the_interval_and_the_probability_carry_their_own_ranges(): void
    {
        $settings = self::settings([
            'TRUSTED_ATTESTATION_AUDIT_INTERVAL_HOURS' => '8761',
            'TRUSTED_ATTESTATION_AUDIT_CHECK_PROBABILITY' => '0',
        ]);

        self::assertSame(24, $settings->int('TRUSTED_ATTESTATION_AUDIT_INTERVAL_HOURS', 24, 1, 8760));
        self::assertSame(200, $settings->int('TRUSTED_ATTESTATION_AUDIT_CHECK_PROBABILITY', 200, 1, PHP_INT_MAX));
        self::assertSame([
            'TRUSTED_ATTESTATION_AUDIT_INTERVAL_HOURS must be a whole number from 1 to 8760, was "8761".',
            'TRUSTED_ATTESTATION_AUDIT_CHECK_PROBABILITY must be a whole number from 1 or more, was "0".',
        ], $settings->problems());
    }

    // -- lists ---------------------------------------------------------------------------------------

    #[Test]
    public function a_list_is_split_on_commas_with_each_item_trimmed_and_empty_items_dropped(): void
    {
        // "employee, client" configures the same thing as "employee,client", as it does on the other two
        // implementations, where a trailing comma or a space after one is not a relationship type.
        $settings = self::settings(['TRUSTED_ATTESTATION_MANIFEST_RELATIONSHIP_TYPES' => ' employee, client ,, ']);

        self::assertSame(['employee', 'client'], $settings->list('TRUSTED_ATTESTATION_MANIFEST_RELATIONSHIP_TYPES'));
        self::assertSame([], self::settings([])->list('TRUSTED_ATTESTATION_MANIFEST_JURISDICTIONS'));
    }

    // -- locale-keyed maps ---------------------------------------------------------------------------

    #[Test]
    public function a_locale_keyed_object_is_decoded_and_an_unset_or_empty_one_has_no_entries(): void
    {
        $settings = self::settings(['TRUSTED_ATTESTATION_MANIFEST_NAME' => '{"en":"Acme Bank","fr":"Banque Acme"}', 'EMPTY' => '']);

        self::assertSame(['en' => 'Acme Bank', 'fr' => 'Banque Acme'], $settings->localeMap('TRUSTED_ATTESTATION_MANIFEST_NAME'));
        self::assertSame([], $settings->localeMap('EMPTY'));
        self::assertSame([], $settings->localeMap('UNSET'));
        self::assertSame([], $settings->problems());
    }

    /** @return iterable<string, array{string}> */
    public static function notLocaleMaps(): iterable
    {
        yield 'not JSON' => ['{en:Acme Bank}'];
        yield 'a bare string' => ['"Acme Bank"'];
        yield 'a number' => ['42'];
        yield 'a JSON array' => ['["Acme Bank"]'];
    }

    #[Test]
    #[DataProvider('notLocaleMaps')]
    public function a_manifest_value_that_is_not_a_locale_keyed_object_is_a_complaint_not_an_empty_object(string $raw): void
    {
        // Served as an empty object, a misquoted name would stop the instance with a manifest complaint
        // that never names the setting. The complaint names it.
        $settings = self::settings(['TRUSTED_ATTESTATION_MANIFEST_NAME' => $raw]);

        self::assertSame([], $settings->localeMap('TRUSTED_ATTESTATION_MANIFEST_NAME'));
        self::assertCount(1, $settings->problems());
        self::assertStringStartsWith(
            'TRUSTED_ATTESTATION_MANIFEST_NAME must be a JSON object keyed by locale',
            $settings->problems()[0],
        );
        self::assertStringContainsString('was "'.$raw.'".', $settings->problems()[0]);
    }

    #[Test]
    public function problems_accumulate_in_the_order_the_settings_were_read(): void
    {
        $settings = self::settings([
            'TRUSTED_ATTESTATION_TLS_TERMINATED_BY_PROXY' => 'sometimes',
            'TRUSTED_ATTESTATION_AUDIT_RETENTION_DAYS' => 'abc',
        ]);
        $settings->bool('TRUSTED_ATTESTATION_TLS_TERMINATED_BY_PROXY', false);
        $settings->int('TRUSTED_ATTESTATION_AUDIT_RETENTION_DAYS', 1825, 1, 36500);

        self::assertSame([
            'TRUSTED_ATTESTATION_TLS_TERMINATED_BY_PROXY must be true or false, was "sometimes".',
            'TRUSTED_ATTESTATION_AUDIT_RETENTION_DAYS must be a whole number from 1 to 36500, was "abc".',
        ], $settings->problems());
    }
}
