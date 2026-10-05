package org.astrana.trustedattestation.service;

import static org.assertj.core.api.Assertions.assertThat;

import java.io.IOException;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.stream.Collectors;
import org.junit.jupiter.api.Test;
import org.springframework.boot.json.JsonParserFactory;
import org.springframework.core.io.ClassPathResource;

/**
 * The landing page and the self-service page, in the member's own language.
 *
 * <p>Rendering in the member's language is a requirement rather than polish, and the failure modes here
 * are all quiet ones: a missing key shows an untranslated label, a broken fallback shows English to
 * someone who asked for French, and neither throws or logs anything. The page renders either way, so
 * nothing downstream notices. The .NET implementation has the same tests for the same reasons.
 *
 * <p>These run against the packaged resource rather than a fixture, because the shipped file being
 * complete is part of what is under test: a translation added for {@code fr} and forgotten for
 * {@code de} survives every other check in this project, and only the member ever sees it.
 */
class UiStringsTest {

    private static final UiStrings STRINGS = new UiStrings();

    // ---------------------------------------------------------------------------------------------
    // Falling back
    // ---------------------------------------------------------------------------------------------

    @Test
    void anExactLocaleIsUsedWhenItExists() {
        assertThat(STRINGS.all("fr")).containsEntry("sign_out", "Se déconnecter");
    }

    @Test
    void aRegionFallsBackToItsLanguage() {
        // fr-CA is not shipped; fr is. A Canadian member gets French rather than English, which is the
        // whole point of the fallback and the case a lookup written as an exact match would miss.
        assertThat(STRINGS.all("fr-CA").get("sign_out"))
                .isEqualTo(STRINGS.all("fr").get("sign_out"));
    }

    @Test
    void anUnderscoreLocaleWorksLikeAHyphenOne() {
        // Java language tags use '-', but a Locale rendered with toString() uses '_', and both arrive
        // here depending on which API produced the value. fr_CA silently falling back to English would
        // be invisible to every test that only tried the hyphen form.
        assertThat(STRINGS.all("fr_CA").get("sign_out"))
                .isEqualTo(STRINGS.all("fr").get("sign_out"));
    }

    @Test
    void anUnknownLanguageFallsBackToEnglish() {
        assertThat(STRINGS.all("cy-GB")).isEqualTo(STRINGS.all("en"));
    }

    @Test
    void noLocaleAtAllIsEnglishRatherThanAnException() {
        assertThat(STRINGS.all(null)).isEqualTo(STRINGS.all("en"));
        assertThat(STRINGS.all("")).isEqualTo(STRINGS.all("en"));
        assertThat(STRINGS.all("   ")).isEqualTo(STRINGS.all("en"));
    }

    // ---------------------------------------------------------------------------------------------
    // Resolving the rendered locale -- what the html lang and dir must reflect
    // ---------------------------------------------------------------------------------------------

    @Test
    void resolveReturnsThePackagedLocaleThatBacksARequest() {
        assertThat(STRINGS.resolve("fr")).isEqualTo("fr");
        assertThat(STRINGS.resolve("de")).isEqualTo("de");
        assertThat(STRINGS.resolve("en")).isEqualTo("en");
    }

    @Test
    void resolveFallsARegionBackToItsLanguage() {
        assertThat(STRINGS.resolve("fr-CA")).isEqualTo("fr");
        assertThat(STRINGS.resolve("de_AT")).isEqualTo("de");
    }

    @Test
    void resolveClampsAnUntranslatedLanguageToEnglish() {
        // The bug this guards: a request for a language we do not have was answered in English but the
        // page still advertised itself -- lang and dir -- as that language. For a right-to-left one that
        // meant an English page mirrored. resolve is what the page's lang and dir are computed from, so
        // an unknown language, and any right-to-left one we do not ship, must come back "en".

        // A right-to-left language we do ship resolves to itself -- the page renders correctly right-to-left.
        assertThat(STRINGS.resolve("ar")).isEqualTo("ar");
        assertThat(STRINGS.resolve("he")).isEqualTo("he");

        // A language we do not ship -- including a right-to-left one -- clamps to English. Yiddish (RTL)
        // and Welsh are not in the shipped set.
        assertThat(STRINGS.resolve("yi")).isEqualTo("en");
        assertThat(STRINGS.resolve("cy-GB")).isEqualTo("en");
    }

    @Test
    void resolveIsEnglishForNoLocaleAtAll() {
        assertThat(STRINGS.resolve(null)).isEqualTo("en");
        assertThat(STRINGS.resolve("")).isEqualTo("en");
    }

    // ---------------------------------------------------------------------------------------------
    // The whole set, as the page receives it
    // ---------------------------------------------------------------------------------------------

    @Test
    void everyLocaleGetsACompleteEnglishBackfilledSet() {
        // The page's template and script index into this map directly; a missing key renders as an
        // empty label or "undefined" in the member's browser.
        Set<String> english = STRINGS.all("en").keySet();

        for (String locale : List.of("fr", "de", "cy-GB")) {
            assertThat(STRINGS.all(locale).keySet()).as("keys for %s", locale).isEqualTo(english);
        }
    }

    @Test
    void aTranslatedValueWinsOverTheEnglishItWasBackfilledFrom() {
        // The ordering that makes backfill safe: English first, then the more specific locale over it.
        // Reversed, every member would see English no matter what they asked for.
        assertThat(STRINGS.all("fr").get("sign_out"))
                .isNotEqualTo(STRINGS.all("en").get("sign_out"));
    }

    // ---------------------------------------------------------------------------------------------
    // The shipped file itself
    // ---------------------------------------------------------------------------------------------

    /**
     * The locale blocks exactly as the shipped file declares them, with no fallback applied. Read
     * directly rather than through the lookup, because the lookup cannot tell "not translated" from
     * "translates to the same word" -- French for status_active really is "Active".
     */
    @SuppressWarnings("unchecked")
    private static Map<String, Set<String>> shippedLocales() throws IOException {
        try (InputStream stream = new ClassPathResource("ui-strings.json").getInputStream()) {
            Map<String, Object> raw = JsonParserFactory.getJsonParser()
                    .parseMap(new String(stream.readAllBytes(), StandardCharsets.UTF_8));

            return raw.entrySet().stream()
                    .filter(entry -> !entry.getKey().startsWith("_") && entry.getValue() instanceof Map)
                    .collect(Collectors.toMap(
                            Map.Entry::getKey, entry -> ((Map<String, Object>) entry.getValue()).keySet()));
        }
    }

    @Test
    void everyShippedLocaleCarriesEveryKey() throws IOException {
        // The check that catches a string added in one language and forgotten in the others. Nothing
        // else would: the page renders, the member simply reads English in the middle of their own
        // language, and only they can see it.
        Map<String, Set<String>> shipped = shippedLocales();
        Set<String> english = shipped.get("en");

        shipped.forEach((locale, keys) ->
                assertThat(keys).as("keys declared for %s", locale).isEqualTo(english));
    }

    @Test
    void theShippedFileCoversTheLocalesTheOtherImplementationsShip() throws IOException {
        // The same file is meant to be shared across all three implementations, so a locale dropped
        // here while the others kept it would make the same member's page change language by stack. This
        // list is a deliberate tripwire: adding or removing a locale is a cross-implementation change, so
        // this set and the equivalent in .NET and PHP have to move together (the differential gate checks
        // the same thing across running instances).
        assertThat(shippedLocales().keySet())
                .containsExactlyInAnyOrder(
                        "af", "am", "ar", "az", "bg", "bn", "cs", "de", "el", "en", "es", "fa", "fil", "fr", "gu", "ha",
                        "he", "hi", "hr", "hu", "id", "it", "ja", "jv", "kk", "km", "kn", "ko", "ml", "mr", "ms", "my",
                        "ne", "nl", "or", "pa", "pl", "ps", "pt", "ro", "ru", "si", "sr", "sv", "sw", "ta", "te", "th",
                        "tr", "uk", "ur", "uz", "vi", "xh", "yo", "zh-Hans", "zh-Hant", "zu");
    }
}
