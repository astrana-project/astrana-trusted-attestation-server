<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LocalizationSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which locales an instance offers, and in what order.
 *
 * These rules decide what every page's language switcher lists and which locale a request may resolve to,
 * so they are pinned here against the same cases the .NET implementation tests: the organisation's own
 * order honoured verbatim, unshipped values dropped, the endonym order with the default pinned first when
 * nothing is configured, and the fallbacks that stop a misconfiguration blanking the UI.
 *
 * A Unit test: the class is framework-free and reads no configuration of its own.
 */
final class LocalizationSettingsTest extends TestCase
{
    private const SHIPPED = ['en', 'fr', 'de', 'es'];

    private const ENDONYMS = ['en' => 'English', 'fr' => 'Français', 'de' => 'Deutsch', 'es' => 'Español'];

    /** @param list<string> $configured */
    private static function settings(array $configured, string $default = 'en'): LocalizationSettings
    {
        return new LocalizationSettings($configured, self::SHIPPED, $default, self::ENDONYMS);
    }

    #[Test]
    public function a_configured_list_is_offered_in_the_configured_order(): void
    {
        // The organisation puts its primary languages first. That order is the one the switcher shows.
        self::assertSame(['fr', 'en'], self::settings(['fr', 'en'])->locales());
    }

    #[Test]
    public function configured_locales_the_app_does_not_ship_are_dropped(): void
    {
        // Offered as an empty language they would render English under a Welsh label.
        self::assertSame(['fr', 'en'], self::settings(['fr', 'cy', 'en'])->locales());
    }

    #[Test]
    public function configured_values_are_matched_without_regard_to_case_and_offered_under_the_shipped_spelling(): void
    {
        self::assertSame(['fr', 'en'], self::settings(['FR', ' en '])->locales());
    }

    #[Test]
    public function a_configured_duplicate_is_offered_once(): void
    {
        self::assertSame(['fr', 'en'], self::settings(['fr', 'en', 'fr'])->locales());
    }

    #[Test]
    public function an_empty_configuration_offers_every_shipped_locale_by_endonym_with_the_default_first(): void
    {
        // Alphabetical by native name (Deutsch, English, Español, Français), then the organisation's
        // default pinned to the front as the most likely choice.
        self::assertSame(['en', 'de', 'es', 'fr'], self::settings([])->locales());
        self::assertSame(['fr', 'de', 'en', 'es'], self::settings([], 'fr')->locales());
    }

    #[Test]
    public function the_endonym_order_ignores_case(): void
    {
        $settings = new LocalizationSettings(
            [],
            ['en', 'zu', 'it'],
            'en',
            ['en' => 'English', 'zu' => 'isiZulu', 'it' => 'Italiano'],
        );

        // isiZulu sorts under i with Italiano, not after every capitalised name.
        self::assertSame(['en', 'zu', 'it'], $settings->locales());
    }

    #[Test]
    public function the_endonym_order_files_an_accented_letter_under_its_base_letter(): void
    {
        // The order the .NET and Java implementations give (decision record 34). Byte order would put
        // Čeština and Íslenska after every unaccented name.
        $settings = new LocalizationSettings(
            [],
            ['en', 'is', 'it', 'cs', 'da'],
            'en',
            ['en' => 'English', 'is' => 'Íslenska', 'it' => 'Italiano', 'cs' => 'Čeština', 'da' => 'Dansk'],
        );

        self::assertSame(['en', 'cs', 'da', 'is', 'it'], $settings->locales());
    }

    #[Test]
    public function the_endonym_order_places_scripts_the_way_the_dotnet_implementation_does(): void
    {
        // The .NET side orders with the invariant culture, which is ICU root collation. PHP matches it
        // through the intl extension, which the application requires, so this runs everywhere.
        $settings = new LocalizationSettings(
            [],
            ['en', 'zh-Hans', 'ja', 'ko', 'ru', 'he', 'ar'],
            'en',
            [
                'en' => 'English', 'zh-Hans' => '简体中文', 'ja' => '日本語', 'ko' => '한국어',
                'ru' => 'Русский', 'he' => 'עברית', 'ar' => 'العربية',
            ],
        );

        // Latin, Cyrillic, Hebrew, Arabic, Hangul, then Han by code point.
        self::assertSame(['en', 'ru', 'he', 'ar', 'ko', 'ja', 'zh-Hans'], $settings->locales());
    }

    #[Test]
    public function a_configuration_that_leaves_nothing_falls_back_to_every_shipped_locale(): void
    {
        // Every configured value unshipped must not blank the UI.
        self::assertSame(self::SHIPPED, self::settings(['cy', 'ga'])->locales());
    }

    #[Test]
    public function blank_configured_entries_are_ignored(): void
    {
        // A trailing comma in the environment variable is not a locale.
        self::assertSame(['fr', 'en'], self::settings(['fr', '', '  ', 'en'])->locales());
        self::assertSame(['en', 'de', 'es', 'fr'], self::settings(['', ' '])->locales());
    }

    #[Test]
    public function the_default_locale_is_the_configured_one_when_shipped_otherwise_the_first_offered(): void
    {
        self::assertSame('fr', self::settings(['de', 'fr'], 'fr')->defaultLocale());
        // Not shipped: the first offered locale stands in rather than a language with no strings.
        self::assertSame('de', self::settings(['de', 'fr'], 'cy')->defaultLocale());
    }

    #[Test]
    public function the_switcher_is_shown_only_when_there_is_a_choice(): void
    {
        self::assertFalse(self::settings(['en'])->showSwitcher());
        self::assertTrue(self::settings(['en', 'fr'])->showSwitcher());
    }

    #[Test]
    public function offers_is_an_exact_match_ignoring_case_with_no_region_fallback(): void
    {
        // This is the check /set-language applies before storing a cookie: a region tag is not offered as
        // such, so it is not stored, and the page resolves it through the claim or Accept-Language instead.
        $settings = self::settings(['en', 'fr']);

        self::assertTrue($settings->offers('fr'));
        self::assertTrue($settings->offers('FR'));
        self::assertFalse($settings->offers('fr-CA'));
        self::assertFalse($settings->offers('de'));
        self::assertFalse($settings->offers(null));
    }

    #[Test]
    public function match_falls_a_region_back_to_its_offered_language(): void
    {
        $settings = self::settings(['en', 'fr']);

        self::assertSame('fr', $settings->match('fr'));
        self::assertSame('fr', $settings->match('fr-CA'));
        self::assertSame('fr', $settings->match('fr_CA'));
        self::assertSame('fr', $settings->match('FR'));
    }

    /** Chinese is the one shipped language offered in two scripts. */
    private static function chinese(): LocalizationSettings
    {
        return new LocalizationSettings(
            ['en', 'zh-Hans', 'zh-Hant'],
            ['en', 'zh-Hans', 'zh-Hant'],
            'en',
            ['en' => 'English', 'zh-Hans' => '简体中文', 'zh-Hant' => '繁體中文'],
        );
    }

    #[Test]
    public function match_reads_a_chinese_region_as_its_script(): void
    {
        // The rule pinned for all three implementations: Taiwan, Hong Kong and Macao write Traditional, any
        // other region or none writes Simplified. Symfony hands the browser's zh-TW over as zh_TW.
        $settings = self::chinese();

        self::assertSame('zh-Hant', $settings->match('zh-TW'));
        self::assertSame('zh-Hant', $settings->match('zh_TW'));
        self::assertSame('zh-Hant', $settings->match('zh-HK'));
        self::assertSame('zh-Hant', $settings->match('zh-MO'));
        self::assertSame('zh-Hans', $settings->match('zh-CN'));
        self::assertSame('zh-Hans', $settings->match('zh-SG'));
        self::assertSame('zh-Hans', $settings->match('zh'));
    }

    #[Test]
    public function match_lets_a_script_subtag_win_over_the_region(): void
    {
        $settings = self::chinese();

        self::assertSame('zh-Hant', $settings->match('zh-Hant-TW'));
        self::assertSame('zh-Hans', $settings->match('zh-Hans-TW'));
        self::assertSame('zh-Hant', $settings->match('zh_HANT_TW'));
    }

    #[Test]
    public function match_falls_a_chinese_tag_back_to_the_one_script_offered(): void
    {
        // Only Simplified offered: a Traditional reader still gets Chinese rather than the default locale.
        $settings = new LocalizationSettings(
            ['en', 'zh-Hans'],
            ['en', 'zh-Hans', 'zh-Hant'],
            'en',
            ['en' => 'English', 'zh-Hans' => '简体中文', 'zh-Hant' => '繁體中文'],
        );

        self::assertSame('zh-Hans', $settings->match('zh-TW'));
        self::assertSame('zh-Hans', $settings->match('zh-Hant-TW'));
    }

    #[Test]
    public function match_is_null_for_anything_not_offered(): void
    {
        // Shipped but not offered by this organisation counts as not offered.
        $settings = self::settings(['en', 'fr']);

        self::assertNull($settings->match('de'));
        self::assertNull($settings->match('cy-GB'));
        self::assertNull($settings->match(''));
        self::assertNull($settings->match('   '));
        self::assertNull($settings->match(null));
    }
}
