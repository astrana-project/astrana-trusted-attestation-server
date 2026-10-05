package org.astrana.trustedattestation.web;

import java.util.Locale;
import java.util.Set;

/**
 * The writing direction for a resolved locale: {@code "rtl"} or {@code "ltr"}.
 *
 * <p>Computed from the locale the page is actually rendered in (see {@code UiStrings.resolve}), never the
 * raw request locale, so an English fallback page is never mirrored because the browser asked for a
 * right-to-left language the instance does not translate. The set is the same short, explicit list the
 * other implementations key on, Arabic, Hebrew, Persian, Urdu and Pashto, as decision record 34 in docs/adr fixes
 * it. All five ship. The list is used rather than the runtime's own culture data, whose answer could differ
 * by implementation and shift with the Java development kit's Unicode data. A consumer must not be able to
 * tell the implementations apart.
 */
final class TextDirection {

    private static final Set<String> RIGHT_TO_LEFT = Set.of("ar", "he", "fa", "ur", "ps");

    private TextDirection() {}

    static String of(String tag) {
        String language =
                tag == null ? "" : tag.replace('_', '-').split("-", 2)[0].toLowerCase(Locale.ROOT);
        return RIGHT_TO_LEFT.contains(language) ? "rtl" : "ltr";
    }
}
