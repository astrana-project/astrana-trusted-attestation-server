package org.astrana.trustedattestation.service;

import java.lang.Character.UnicodeScript;
import java.text.Collator;
import java.util.Comparator;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.stream.Collectors;

/**
 * Alphabetical order of language names written in their own scripts, matching the order the .NET and PHP
 * implementations produce.
 *
 * <p>Those two sort with ICU's root collation (the Unicode Collation Algorithm): the .NET invariant culture
 * and PHP's intl extension are both ICU. The JDK ships its own collator with no ICU behind it, and its root
 * rules cover the Latin alphabet and its diacritics only. Every other script falls back to raw code-point
 * order, which has two consequences for a menu of language names. Scripts come out in code-point order
 * rather than the algorithm's (Hangul and Han after Khmer, Ethiopic between Myanmar and Khmer), and a letter
 * encoded outside its alphabet's block files after the whole alphabet: Kazakh's Қ after every other
 * Cyrillic letter, Pashto's پ after every other Arabic one. Either would put the same language in a
 * different place on this stack than on the other two.
 *
 * <p>Rather than take on the ICU4J library (some thirteen megabytes, for one menu), this restores both.
 * Scripts are ranked in the algorithm's order. Within Latin the JDK collator decides, since it handles case
 * and accents correctly there. Within any other script the names compare by lower-cased code point, which
 * is the algorithm's order for these alphabets' core letters, with the letters known to sit outside their
 * block keyed to the letter they follow. A new locale whose name begins with a script or letter not covered
 * here shows up in the test that pins the shipped order against .NET's, which is where the tables grow.
 */
final class EndonymOrder implements Comparator<String> {

    /**
     * The Unicode Collation Algorithm's script order, as far as the shipped languages reach plus their
     * immediate neighbours. A script not listed ranks after all of these.
     */
    private static final List<UnicodeScript> SCRIPTS = List.of(
            UnicodeScript.LATIN,
            UnicodeScript.GREEK,
            UnicodeScript.CYRILLIC,
            UnicodeScript.ARMENIAN,
            UnicodeScript.HEBREW,
            UnicodeScript.ARABIC,
            UnicodeScript.ETHIOPIC,
            UnicodeScript.DEVANAGARI,
            UnicodeScript.BENGALI,
            UnicodeScript.GURMUKHI,
            UnicodeScript.GUJARATI,
            UnicodeScript.ORIYA,
            UnicodeScript.TAMIL,
            UnicodeScript.TELUGU,
            UnicodeScript.KANNADA,
            UnicodeScript.MALAYALAM,
            UnicodeScript.SINHALA,
            UnicodeScript.THAI,
            UnicodeScript.LAO,
            UnicodeScript.TIBETAN,
            UnicodeScript.MYANMAR,
            UnicodeScript.KHMER,
            UnicodeScript.HANGUL,
            UnicodeScript.HIRAGANA,
            UnicodeScript.KATAKANA,
            UnicodeScript.HAN);

    /**
     * Letters encoded outside their alphabet's block, keyed as the letter they follow plus the highest code
     * unit, so a name beginning with one sorts after every name beginning with that letter and before the
     * next letter of the alphabet: Kazakh's қ directly after к, Pashto's پ directly after ب.
     */
    private static final Map<Integer, String> OUT_OF_BLOCK = Map.of(
            0x049B, "к￿",
            0x067E, "ب￿");

    private final Collator latin;

    EndonymOrder() {
        // Case-insensitive, accent-aware: the same comparison the .NET implementation asks of its invariant
        // culture when it sorts with ignoreCase.
        this.latin = Collator.getInstance(Locale.ROOT);
        this.latin.setStrength(Collator.SECONDARY);
    }

    @Override
    public int compare(String left, String right) {
        int leftRank = rank(left);
        int byScript = Integer.compare(leftRank, rank(right));
        if (byScript != 0) {
            return byScript;
        }

        return leftRank == SCRIPTS.indexOf(UnicodeScript.LATIN)
                ? latin.compare(left, right)
                : key(left).compareTo(key(right));
    }

    /** The rank of the script the name is written in, read from its first letter. */
    private static int rank(String name) {
        int index = name.codePoints()
                .filter(Character::isLetter)
                .mapToObj(UnicodeScript::of)
                .mapToInt(SCRIPTS::indexOf)
                .findFirst()
                .orElse(-1);

        return index < 0 ? SCRIPTS.size() : index;
    }

    /** The name as a non-Latin sort key: lower-cased, with out-of-block letters put in their place. */
    private static String key(String name) {
        return name.codePoints()
                .map(Character::toLowerCase)
                .mapToObj(codePoint -> OUT_OF_BLOCK.getOrDefault(codePoint, Character.toString(codePoint)))
                .collect(Collectors.joining());
    }
}
