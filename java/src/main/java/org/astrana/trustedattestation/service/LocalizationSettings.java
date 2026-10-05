package org.astrana.trustedattestation.service;

import java.util.ArrayList;
import java.util.Collection;
import java.util.Comparator;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Optional;
import java.util.Set;

/**
 * The locales this instance actually offers, resolved once at startup and shared by the request locale
 * resolver (which locale a request may resolve to) and the pages (which options the language switcher
 * shows, and in what order).
 *
 * <p>The effective set is the organisation's configured {@code supported-locales} intersected with the
 * locales the app ships strings for. If the organisation lists them explicitly, that order is honoured
 * verbatim -- it lets an organisation put its primary languages first. If it does not (the list is empty,
 * meaning "every locale the app has"), the languages are ordered alphabetically by their own native name
 * (endonym), so a speaker finds their language by how they themselves write it, with the organisation's
 * default locale pinned to the front as the most likely choice. A configured value with no shipped strings
 * is dropped rather than offered as an empty language. The same rules, in the same order, as the .NET
 * implementation's LocalizationSettings, so the three stacks offer the same list.
 */
public class LocalizationSettings {

    /** Lower-cased, as {@link #key} leaves a tag: the regions whose Chinese is written in Traditional script. */
    private static final Set<String> TRADITIONAL_CHINESE_REGIONS = Set.of("tw", "hk", "mo");

    private final List<String> locales;
    private final String defaultLocale;
    private final Map<String, String> endonyms;

    /**
     * @param configured the organisation's ordered list, possibly empty
     * @param available the locales the strings file ships, in file order
     * @param defaultLocale the manifest's default locale
     * @param endonyms each shipped locale's own name for itself, keyed by locale
     */
    public LocalizationSettings(
            Collection<String> configured,
            Collection<String> available,
            String defaultLocale,
            Map<String, String> endonyms) {
        this.endonyms = Map.copyOf(endonyms);

        // Shipped spellings, matched without regard to case, so a configured "FR" offers the shipped "fr".
        Map<String, String> have = new LinkedHashMap<>();
        available.forEach(locale -> have.put(key(locale), locale));

        List<String> wanted = configured.stream()
                .filter(locale -> locale != null && !locale.isBlank())
                .map(String::trim)
                .toList();

        List<String> effective = new ArrayList<>();
        if (!wanted.isEmpty()) {
            // The organisation chose the order; honour it, keeping only locales the app actually ships.
            for (String locale : wanted) {
                String shipped = have.get(key(locale));
                if (shipped != null && !effective.contains(shipped)) {
                    effective.add(shipped);
                }
            }
        } else {
            // No explicit order: alphabetical by native name, then the default locale pinned to the front.
            effective.addAll(have.values());
            effective.sort(byEndonym());

            String pin = have.get(key(defaultLocale));
            if (pin != null) {
                effective.remove(pin);
                effective.addFirst(pin);
            }
        }

        // A misconfiguration that leaves nothing (every configured locale unshipped) must not blank the UI:
        // fall back to what the app has rather than serve no language at all.
        this.locales = List.copyOf(effective.isEmpty() ? have.values() : effective);

        String shippedDefault = have.get(key(defaultLocale));
        this.defaultLocale = shippedDefault != null ? shippedDefault : locales.getFirst();
    }

    /**
     * Case-insensitive, accent-aware ordering of the languages by their own names, in the order the other two
     * implementations produce -- see {@link EndonymOrder} for why the JDK's own collator is not enough.
     */
    private Comparator<String> byEndonym() {
        EndonymOrder order = new EndonymOrder();
        return (left, right) -> order.compare(endonym(left), endonym(right));
    }

    private static String key(String locale) {
        return locale == null ? "" : locale.trim().toLowerCase(Locale.ROOT);
    }

    /** The offered locales, in display order. Always at least one. */
    public List<String> locales() {
        return locales;
    }

    /** The fallback locale when a request resolves to nothing offered. */
    public String defaultLocale() {
        return defaultLocale;
    }

    /** The switcher is shown only when there is a genuine choice. */
    public boolean showSwitcher() {
        return locales.size() > 1;
    }

    /** A language's own name for itself, falling back to its tag when the strings file has none. */
    public String endonym(String locale) {
        return endonyms.getOrDefault(locale, locale);
    }

    /**
     * The offered locale that serves a requested tag, if any. This is the one matching rule all three
     * implementations apply, to the switcher's cookie, the IAM claim and each Accept-Language entry alike,
     * so the same request renders the same language on every stack. In order: the tag itself when it is
     * offered, ignoring case and reading {@code _} as {@code -}. Then, for a language that ships in more than
     * one script (today only Chinese, {@code zh-Hans} and {@code zh-Hant}), the offered locale in the script
     * the tag asks for, see {@link #scriptOf}. Then the first offered locale in the tag's language ({@code
     * fr-CA} is served by {@code fr}, and {@code zh-TW} by {@code zh-Hans} when that is the only Chinese
     * offered). Empty when the tag names nothing this instance offers.
     */
    public Optional<String> offered(String tag) {
        if (tag == null || tag.isBlank()) {
            return Optional.empty();
        }

        String requested = key(tag.replace('_', '-'));
        return exact(requested).or(() -> byScript(requested)).or(() -> byLanguage(languageOf(requested)));
    }

    private Optional<String> exact(String requested) {
        return locales.stream().filter(locale -> key(locale).equals(requested)).findFirst();
    }

    private Optional<String> byScript(String requested) {
        String script = scriptOf(requested);
        return script == null ? Optional.empty() : exact(languageOf(requested) + "-" + script);
    }

    private Optional<String> byLanguage(String language) {
        return locales.stream()
                .filter(locale -> languageOf(key(locale)).equals(language))
                .findFirst();
    }

    /**
     * The script a lower-cased tag asks for, or null when it asks for none. A script subtag the tag carries
     * itself ({@code zh-Hant-TW}) wins outright. Without one, only Chinese implies a script, and its region
     * decides: Taiwan, Hong Kong and Macao write Traditional, any other region or none ({@code zh-CN},
     * {@code zh-SG}, {@code zh}) means Simplified. A language prefix alone would send {@code zh-TW} to
     * whichever Chinese happens to be offered first, which is what this rule exists to prevent.
     */
    private static String scriptOf(String tag) {
        String[] subtags = tag.split("-");
        if (subtags.length > 1 && subtags[1].length() == 4) {
            return subtags[1];
        }

        if (!"zh".equals(subtags[0])) {
            return null;
        }

        String region = subtags.length > 1 ? subtags[1] : "";
        return TRADITIONAL_CHINESE_REGIONS.contains(region) ? "hant" : "hans";
    }

    /**
     * Whether a switcher entry is the one the page is currently rendered in. An entry is active when it is
     * the current locale, or when it is the bare language of a regional current locale.
     */
    public boolean isActive(String locale, String current) {
        if (locale == null || current == null) {
            return false;
        }

        String entry = key(locale);
        String rendered = key(current.replace('_', '-'));
        return entry.equals(rendered) || entry.equals(languageOf(rendered));
    }

    /** The switcher entry shown as current: the active one, or the first offered when none matches. */
    public String active(String current) {
        return locales.stream()
                .filter(locale -> isActive(locale, current))
                .findFirst()
                .orElse(locales.getFirst());
    }

    private static String languageOf(String tag) {
        int separator = tag.indexOf('-');
        return separator > 0 ? tag.substring(0, separator) : tag;
    }
}
