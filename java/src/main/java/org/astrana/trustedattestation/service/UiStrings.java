package org.astrana.trustedattestation.service;

import java.io.IOException;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import org.astrana.trustedattestation.contract.RelationshipTypeCatalog;
import org.springframework.boot.json.JsonParserFactory;
import org.springframework.core.io.ClassPathResource;
import org.springframework.stereotype.Component;

/**
 * Text for the landing page and the self-service page, in the member's own language.
 *
 * <p>Backed by a JSON data file rather than a {@code messages.properties} bundle, for the same reason the
 * governed vocabulary is a data file. The set is small, and keeping it as data means a translator can add
 * a locale without touching the build, and the same file can be shared across all three implementations.
 *
 * <p>Relationship-type labels do not live here. Those come from the contract's own file so the same
 * relationship reads the same way whichever organisation issued it.
 */
@Component
public class UiStrings {

    private static final String RESOURCE = "ui-strings.json";

    private final Map<String, Map<String, String>> byLocale;

    public UiStrings() {
        Map<String, Object> raw;
        try (InputStream stream = new ClassPathResource(RESOURCE).getInputStream()) {
            raw = JsonParserFactory.getJsonParser().parseMap(new String(stream.readAllBytes(), StandardCharsets.UTF_8));
        } catch (IOException exception) {
            throw new IllegalStateException("Packaged resource '" + RESOURCE + "' could not be read.", exception);
        }

        Map<String, Map<String, String>> loaded = new LinkedHashMap<>();

        raw.forEach((locale, value) -> {
            // Keys beginning with "_" are notes to whoever edits the file, not a locale.
            if (locale.startsWith("_") || !(value instanceof Map<?, ?> entries)) {
                return;
            }

            Map<String, String> strings = new LinkedHashMap<>();
            entries.forEach((key, text) -> strings.put(String.valueOf(key), String.valueOf(text)));
            loaded.put(locale, Map.copyOf(strings));
        });

        if (!loaded.containsKey("en")) {
            throw new IllegalStateException("Packaged resource '" + RESOURCE + "' has no 'en' block. "
                    + "English is the fallback and must be complete.");
        }

        this.byLocale = Map.copyOf(loaded);
    }

    /**
     * The most specific packaged locale that actually backs a request for {@code tag}, or {@code "en"}.
     *
     * <p>This is the locale the page is really rendered in, so it, not the raw request locale, is what the
     * html {@code lang} and {@code dir} must reflect. Ask for a language we do not have and the page is
     * English. Advertising it as that language, and mirroring the layout when that language is
     * right-to-left, would describe a page that is not there. Mirrors what the other two implementations
     * do by clamping to their supported-culture list.
     */
    public String resolve(String tag) {
        for (String candidate : RelationshipTypeCatalog.localeFallbacks(tag)) {
            if (byLocale.containsKey(candidate)) {
                return candidate;
            }
        }
        return "en";
    }

    /**
     * Every string for one locale, English-backfilled. Handed to the page as one map rather than looked
     * up key by key, since the page's script needs the same strings the server-rendered markup does.
     *
     * <p>Never throws for a missing key, because a page with one untranslated label is better than a page
     * that will not render.
     */
    public Map<String, String> all(String locale) {
        Map<String, String> result = new LinkedHashMap<>(byLocale.get("en"));

        List<String> fallbacks = RelationshipTypeCatalog.localeFallbacks(locale);
        for (int i = fallbacks.size() - 1; i >= 0; i--) {
            Map<String, String> strings = byLocale.get(fallbacks.get(i));
            if (strings != null) {
                result.putAll(strings);
            }
        }

        return result;
    }

    /**
     * The locales this instance can actually serve, taken straight from the strings file. The request
     * locale resolver is built from this, so the set of languages offered and the set actually translated
     * are one list read from one source, not a second copy in a configuration class that could drift from
     * it, and the same source the other two implementations read as well.
     *
     * @return the packaged locale tags, always including {@code "en"}
     */
    public java.util.Set<String> supportedLocales() {
        return byLocale.keySet();
    }
}
