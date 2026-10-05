package org.astrana.trustedattestation.service;

import static org.assertj.core.api.Assertions.assertThat;

import java.util.ArrayList;
import java.util.List;
import org.junit.jupiter.api.Test;

/**
 * The two places the JDK's own collator parts from the Unicode algorithm the other implementations sort
 * with, each pinned by the language name that exposed it. The full shipped order is pinned against .NET's
 * in {@code LocalizationSettingsTest}; these are the mechanics that make it come out that way.
 */
class EndonymOrderTest {

    private static List<String> sorted(String... names) {
        List<String> list = new ArrayList<>(List.of(names));
        list.sort(new EndonymOrder());
        return list;
    }

    @Test
    void scriptsFollowTheUnicodeAlgorithmsOrderNotTheJdksRuleFile() {
        // Korean (Hangul) before Japanese (Han), Amharic (Ethiopic) between Arabic and Devanagari, Burmese
        // (Myanmar) before Khmer: the JDK alone would push every one of these to the end by code point.
        assertThat(sorted("日本語", "한국어", "简体中文", "नेपाली", "አማርኛ", "فارسی", "ខ្មែរ", "မြန်မာ", "ไทย"))
                .containsExactly("فارسی", "አማርኛ", "नेपाली", "ไทย", "မြန်မာ", "ខ្មែរ", "한국어", "日本語", "简体中文");
    }

    @Test
    void lettersTheJdkLacksAreFiledWhereTheAlgorithmPutsThem() {
        // Kazakh's Қ directly after К, so Қазақ precedes Русский rather than trailing all of Cyrillic.
        assertThat(sorted("Українська", "Русский", "Қазақ", "Български", "Српски"))
                .containsExactly("Български", "Қазақ", "Русский", "Српски", "Українська");

        // Pashto's پ directly after ب, so پښتو precedes فارسی rather than trailing all of Arabic.
        assertThat(sorted("فارسی", "پښتو", "العربية", "اردو")).containsExactly("اردو", "العربية", "پښتو", "فارسی");
    }

    @Test
    void caseIsIgnoredAndAccentsAreNot() {
        // The lower-case i of isiXhosa files under I like any other, and Čeština under C.
        assertThat(sorted("Italiano", "isiZulu", "Hrvatski", "isiXhosa", "Deutsch", "Čeština"))
                .containsExactly("Čeština", "Deutsch", "Hrvatski", "isiXhosa", "isiZulu", "Italiano");
    }

    @Test
    void aScriptOutsideTheTableRanksAfterEveryListedOneAndANameWithNoLettersLast() {
        // Georgian is not in the table (nothing shipped is written in it), so it ranks after Han. A name
        // with no letter at all has no script and ranks with the unlisted ones.
        assertThat(sorted("ქართული", "日本語", "English", "123")).containsExactly("English", "日本語", "123", "ქართული");
    }
}
