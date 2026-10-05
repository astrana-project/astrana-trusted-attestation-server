package org.astrana.trustedattestation.web;

import static org.assertj.core.api.Assertions.assertThat;

import org.junit.jupiter.api.Test;

/**
 * Writing direction for a resolved locale.
 *
 * <p>This is only ever handed a locale the page is actually rendered in -- see {@code UiStrings.resolve}
 * -- and the list is the one decision record 34 in docs/adr fixes: Arabic, Hebrew, Persian, Urdu and Pashto, the same
 * five the other two implementations key on, so all three flip the same pages and no others.
 */
class TextDirectionTest {

    @Test
    void shippedLeftToRightLocalesAreLtr() {
        assertThat(TextDirection.of("en")).isEqualTo("ltr");
        assertThat(TextDirection.of("fr")).isEqualTo("ltr");
        assertThat(TextDirection.of("de")).isEqualTo("ltr");
    }

    @Test
    void rightToLeftLanguagesAreRtl() {
        assertThat(TextDirection.of("ar")).isEqualTo("rtl");
        assertThat(TextDirection.of("he")).isEqualTo("rtl");
        assertThat(TextDirection.of("fa")).isEqualTo("rtl");
        assertThat(TextDirection.of("ur")).isEqualTo("rtl");
        assertThat(TextDirection.of("ps")).isEqualTo("rtl");
    }

    @Test
    void theListIsExplicitNotTheRuntimesCultureData() {
        // Yiddish and Syriac are right-to-left in Unicode's data but outside the list in decision record 34, so
        // they stay "ltr" here exactly as they do on the other two stacks.
        assertThat(TextDirection.of("yi")).isEqualTo("ltr");
        assertThat(TextDirection.of("syr")).isEqualTo("ltr");
    }

    @Test
    void theRegionSubtagDoesNotChangeTheDirection() {
        assertThat(TextDirection.of("ar-EG")).isEqualTo("rtl");
        assertThat(TextDirection.of("en_US")).isEqualTo("ltr");
    }

    @Test
    void anAbsentLocaleIsLeftToRight() {
        assertThat(TextDirection.of(null)).isEqualTo("ltr");
        assertThat(TextDirection.of("")).isEqualTo("ltr");
    }
}
