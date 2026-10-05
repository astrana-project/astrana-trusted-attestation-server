package org.astrana.trustedattestation.service;

import static org.assertj.core.api.Assertions.assertThat;

import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import org.junit.jupiter.api.Test;

/**
 * Which locales the pages offer, and in what order.
 *
 * <p>The switcher's list is what a member sees, and the request resolver's supported set is what a request
 * may resolve to, so both come from this one object. What is pinned: the organisation's own order is kept
 * when it gives one, a locale it names but the app does not ship is dropped rather than offered empty, and
 * with no list the shipped locales sort by their own native name with the default pinned first -- in
 * exactly the order the .NET implementation produces, so the three stacks show the same menu.
 */
class LocalizationSettingsTest {

    private static final Map<String, String> ENDONYMS = Map.of(
            "en", "English",
            "fr", "Français",
            "de", "Deutsch",
            "es", "Español",
            "zh-Hans", "简体中文");

    private static final List<String> SHIPPED = List.of("en", "fr", "de", "es", "zh-Hans");

    private static LocalizationSettings with(List<String> configured, String defaultLocale) {
        return new LocalizationSettings(configured, SHIPPED, defaultLocale, ENDONYMS);
    }

    // ---------------------------------------------------------------------------------------------
    // The effective set
    // ---------------------------------------------------------------------------------------------

    @Test
    void aConfiguredListIsHonouredInItsOwnOrderAndIntersectedWithWhatIsShipped() {
        // The org puts its primary languages first; "it" is configured but not shipped, so it is dropped
        // rather than offered as an empty language.
        LocalizationSettings settings = with(List.of("fr", "it", "en"), "en");

        assertThat(settings.locales()).containsExactly("fr", "en");
        assertThat(settings.showSwitcher()).isTrue();
    }

    @Test
    void aConfiguredLocaleIsMatchedWithoutRegardToCaseButOfferedInItsShippedSpelling() {
        assertThat(with(List.of("FR", "zh-hans"), "en").locales()).containsExactly("fr", "zh-Hans");
    }

    @Test
    void anEmptyListOffersEveryShippedLocaleByEndonymWithTheDefaultFirst() {
        // Alphabetical by native name: Deutsch, English, Español, Français, then the CJK name. The default
        // locale, French here, is pinned to the front as the most likely choice.
        assertThat(with(List.of(), "fr").locales()).containsExactly("fr", "de", "en", "es", "zh-Hans");
    }

    @Test
    void blankEntriesCountAsNoList() {
        assertThat(with(List.of("", "  "), "en").locales()).containsExactly("en", "de", "es", "fr", "zh-Hans");
    }

    @Test
    void aListThatLeavesNothingFallsBackToEveryShippedLocaleRatherThanNone() {
        // Every configured locale unshipped is a misconfiguration, but it must not blank the UI.
        LocalizationSettings settings = with(List.of("it", "pt"), "en");

        assertThat(settings.locales()).containsExactlyInAnyOrderElementsOf(SHIPPED);
    }

    @Test
    void aSingleOfferedLocaleHidesTheSwitcher() {
        assertThat(with(List.of("en"), "en").showSwitcher()).isFalse();
    }

    // ---------------------------------------------------------------------------------------------
    // The default locale
    // ---------------------------------------------------------------------------------------------

    @Test
    void theDefaultIsTheManifestsWhenShippedOtherwiseTheFirstOffered() {
        assertThat(with(List.of("fr", "en"), "en").defaultLocale()).isEqualTo("en");
        // A default the app has no strings for cannot be served; the first offered stands in.
        assertThat(with(List.of("fr", "en"), "cy").defaultLocale()).isEqualTo("fr");
    }

    // ---------------------------------------------------------------------------------------------
    // Matching a requested tag to an offered locale
    // ---------------------------------------------------------------------------------------------

    @Test
    void offeredMatchesExactlyThenByLanguage() {
        LocalizationSettings settings = with(List.of("en", "fr", "zh-Hans"), "en");

        assertThat(settings.offered("fr")).contains("fr");
        assertThat(settings.offered("FR")).contains("fr");
        // A region falls back to its language, and an underscore form reads like the hyphen one.
        assertThat(settings.offered("fr-CA")).contains("fr");
        assertThat(settings.offered("fr_CA")).contains("fr");
        assertThat(settings.offered("zh")).contains("zh-Hans");
    }

    @Test
    void offeredChoosesTheChineseScriptByRegionWhenTheTagNamesNone() {
        // The one language shipped in two scripts. Matching zh-TW by language prefix would hand a Taiwanese
        // member Simplified Chinese, so without a script subtag the region decides.
        LocalizationSettings settings = bothChineseScripts();

        assertThat(settings.offered("zh-TW")).contains("zh-Hant");
        assertThat(settings.offered("zh-HK")).contains("zh-Hant");
        assertThat(settings.offered("zh-MO")).contains("zh-Hant");
        assertThat(settings.offered("zh_tw")).contains("zh-Hant");
        assertThat(settings.offered("zh-CN")).contains("zh-Hans");
        assertThat(settings.offered("zh-SG")).contains("zh-Hans");
        assertThat(settings.offered("zh")).contains("zh-Hans");
    }

    @Test
    void offeredLetsAScriptSubtagWinOverTheRegion() {
        LocalizationSettings settings = bothChineseScripts();

        assertThat(settings.offered("zh-Hant-TW")).contains("zh-Hant");
        // Unusual, but explicit: a member in Taiwan who asks for Simplified gets Simplified.
        assertThat(settings.offered("zh-Hans-TW")).contains("zh-Hans");
    }

    @Test
    void offeredFallsBackToTheLanguageWhenTheScriptAskedForIsNotOffered() {
        // Only Simplified offered: Traditional is not available, so zh-TW is served by its language, which
        // beats serving the default in a language the member may not read at all.
        assertThat(with(List.of("en", "fr", "zh-Hans"), "en").offered("zh-TW")).contains("zh-Hans");
    }

    private static LocalizationSettings bothChineseScripts() {
        List<String> offered = List.of("en", "fr", "zh-Hans", "zh-Hant");
        return new LocalizationSettings(offered, offered, "en", ENDONYMS);
    }

    @Test
    void offeredIsEmptyForAnythingNotOffered() {
        LocalizationSettings settings = with(List.of("en", "fr"), "en");

        // Shipped but not offered by this organisation, so not a valid choice here.
        assertThat(settings.offered("de")).isEmpty();
        assertThat(settings.offered("cy-GB")).isEmpty();
        assertThat(settings.offered(null)).isEmpty();
        assertThat(settings.offered("")).isEmpty();
    }

    @Test
    void theActiveEntryIsTheCurrentLocaleOrItsLanguage() {
        LocalizationSettings settings = with(List.of("en", "fr", "zh-Hans"), "en");

        assertThat(settings.isActive("fr", "fr")).isTrue();
        assertThat(settings.isActive("fr", "fr-CA")).isTrue();
        assertThat(settings.isActive("zh-Hans", "zh-Hans")).isTrue();
        assertThat(settings.isActive("en", "fr")).isFalse();
        assertThat(settings.active("fr")).isEqualTo("fr");
        // Nothing matches: the first offered is shown rather than nothing.
        assertThat(settings.active("de")).isEqualTo("en");
    }

    @Test
    void anEndonymFallsBackToTheTagWhenTheFileHasNone() {
        assertThat(with(List.of(), "en").endonym("en")).isEqualTo("English");
        assertThat(with(List.of(), "en").endonym("xx")).isEqualTo("xx");
    }

    // ---------------------------------------------------------------------------------------------
    // The shipped file, in the order the .NET implementation lists it
    // ---------------------------------------------------------------------------------------------

    @Test
    void theShippedLocalesSortExactlyAsTheDotNetImplementationSortsThem() {
        // The one place the three stacks could silently drift: each sorts the endonyms with its own
        // runtime's language-neutral collation. This is the order .NET produces with the invariant culture
        // (ICU root) over the shipped file, with the default English pinned first. A new locale is added
        // here when it is added to the file, which is what keeps the menus identical across the three.
        UiStrings strings = new UiStrings();
        Map<String, String> endonyms = new LinkedHashMap<>();
        strings.supportedLocales()
                .forEach(locale -> endonyms.put(locale, strings.all(locale).get("language_endonym")));

        LocalizationSettings settings = new LocalizationSettings(List.of(), strings.supportedLocales(), "en", endonyms);

        assertThat(settings.locales())
                .containsExactly(
                        "en", "af", "az", "id", "ms", "jv", "cs", "de", "es", "fil", "fr", "ha", "hr", "xh", "zu", "it",
                        "sw", "hu", "nl", "uz", "pl", "pt", "ro", "sv", "vi", "tr", "yo", "el", "bg", "kk", "ru", "sr",
                        "uk", "he", "ur", "ar", "ps", "fa", "am", "ne", "mr", "hi", "bn", "pa", "gu", "or", "ta", "te",
                        "kn", "ml", "si", "th", "my", "km", "ko", "ja", "zh-Hans", "zh-Hant");
    }
}
