<?php

declare(strict_types=1);

namespace App\Services;

use Collator;

/**
 * The locales this instance actually offers, resolved once at startup and shared by the request pipeline
 * (which locale a request may resolve to) and the pages (which options the language switcher shows, and in
 * what order).
 *
 * The effective set is the organisation's configured supported_locales intersected with the locales the
 * app ships strings for. If the organisation lists them explicitly, that order is honoured verbatim -- it
 * lets an organisation put its primary languages first. If it does not (the list is empty, meaning "every
 * locale the app has"), the languages are ordered alphabetically by their own native name (endonym), so a
 * speaker finds their language by how they themselves write it, with the organisation's default locale
 * pinned to the front as the most likely choice. A configured value with no shipped strings is dropped
 * rather than offered as an empty language.
 *
 * Framework-free on purpose, like the .NET class it mirrors: the rules here decide what every page offers,
 * so they are exercised directly rather than only through a rendered page.
 */
final class LocalizationSettings
{
    /**
     * How a tag chooses between the scripts a language is offered in. Today only Chinese ships in two. The
     * script subtag wins when the tag carries one (zh-Hant-TW), otherwise the region decides: Taiwan, Hong
     * Kong and Macao read Traditional, any other region or none (zh-CN, zh-SG, zh) reads Simplified.
     *
     * @var array<string, array<string, string>>
     */
    private const SCRIPTS = ['zh' => ['default' => 'Hans', 'TW' => 'Hant', 'HK' => 'Hant', 'MO' => 'Hant']];

    /** @var list<string> */
    private readonly array $locales;

    private readonly string $defaultLocale;

    /**
     * @param  list<string>  $configured  the organisation's ordered list, empty for every shipped locale
     * @param  list<string>  $available  the locales the strings file ships
     * @param  array<string, string>  $endonyms  each shipped locale's own name for itself
     */
    public function __construct(array $configured, array $available, string $defaultLocale, array $endonyms)
    {
        // Keyed by lower-cased tag so a configured "FR" finds the shipped "fr" and is offered under the
        // shipped spelling, which is the one the strings file and the pages look up by.
        $have = [];
        foreach ($available as $locale) {
            $have[strtolower($locale)] = $locale;
        }

        $wanted = array_values(array_filter(array_map('trim', $configured), static fn (string $c): bool => $c !== ''));

        if ($wanted !== []) {
            // The organisation chose the order; honour it, keeping only locales the app actually ships.
            $effective = [];
            foreach ($wanted as $locale) {
                $shipped = $have[strtolower($locale)] ?? null;
                if ($shipped !== null && ! in_array($shipped, $effective, true)) {
                    $effective[] = $shipped;
                }
            }
        } else {
            $effective = self::byEndonym(array_values($have), $endonyms, $defaultLocale);
        }

        // A misconfiguration that leaves nothing (every configured locale unshipped) must not blank the UI:
        // fall back to what the app has rather than serve no language at all.
        $this->locales = $effective === [] ? array_values($have) : $effective;

        $this->defaultLocale = isset($have[strtolower($defaultLocale)]) ? $have[strtolower($defaultLocale)] : $this->locales[0];
    }

    /**
     * No explicit order: alphabetical by native name, then the default locale pinned to the front.
     *
     * @param  list<string>  $locales
     * @param  array<string, string>  $endonyms
     * @return list<string>
     */
    private static function byEndonym(array $locales, array $endonyms, string $defaultLocale): array
    {
        $compare = self::endonymComparer();
        usort($locales, static fn (string $a, string $b): int => $compare($endonyms[$a] ?? $a, $endonyms[$b] ?? $b));

        foreach ($locales as $index => $locale) {
            if (strcasecmp($locale, $defaultLocale) === 0) {
                array_splice($locales, $index, 1);
                array_unshift($locales, $locale);
                break;
            }
        }

        return $locales;
    }

    /**
     * Case-insensitive, culture-aware ordering of the native names, which span Latin, Cyrillic, Arabic,
     * Hebrew, Indic and CJK scripts. The .NET implementation sorts with the invariant culture, which is ICU
     * root collation, and this uses the same collation through the intl extension, which composer.json
     * requires, so the switcher lists the languages in the same order there and here (decision record 34).
     *
     * @return callable(string, string): int
     */
    private static function endonymComparer(): callable
    {
        $collator = new Collator('root');
        // Secondary strength compares letters and accents but not case, which is what the .NET side asks
        // for with ignoreCase.
        $collator->setStrength(Collator::SECONDARY);

        return static fn (string $a, string $b): int => (int) $collator->compare($a, $b);
    }

    /**
     * The offered locales, in display order. Always at least one.
     *
     * @return list<string>
     */
    public function locales(): array
    {
        return $this->locales;
    }

    /** The fallback locale when a request resolves to nothing offered. */
    public function defaultLocale(): string
    {
        return $this->defaultLocale;
    }

    /** The switcher is shown only when there is a genuine choice. */
    public function showSwitcher(): bool
    {
        return count($this->locales) > 1;
    }

    /**
     * Whether a value names an offered locale exactly (ignoring case). This is the check the language
     * switcher's POST applies before storing a choice: no region fallback, so a forged or stale value
     * cannot plant a cookie for a language this instance does not list.
     */
    public function offers(?string $locale): bool
    {
        return $this->offered($locale) !== null;
    }

    /**
     * The offered locale a value names exactly (ignoring case), in the shipped spelling, or null. The
     * language switcher stores this spelling rather than the posted one, as the other two implementations
     * do, so a posted "FR" plants the cookie "fr".
     */
    public function offered(?string $locale): ?string
    {
        return $locale === null ? null : $this->exact($locale);
    }

    /**
     * The offered locale that backs a requested one, or null when none does, compared without regard to
     * case and with _ read as -: the exact tag first, then, for a language offered in more than one script,
     * the script the tag names or its region implies (zh-TW to zh-Hant, zh-CN to zh-Hans), then the first
     * offered locale with the same language (fr-CA to fr, de_AT to de). This is how the cookie, the IAM
     * locale claim and each Accept-Language entry are constrained to the offered set, so an unknown or
     * no-longer-offered value is ignored and resolution falls through to the next source. The same rule
     * runs on all three implementations, so the same request renders the same language everywhere.
     */
    public function match(?string $locale): ?string
    {
        if ($locale === null || trim($locale) === '') {
            return null;
        }

        $subtags = explode('-', str_replace('_', '-', trim($locale)));
        $language = $subtags[0];

        return $this->exact(implode('-', $subtags))
            ?? $this->byScript($language, $subtags[1] ?? '')
            ?? $this->byLanguage($language);
    }

    private function exact(string $tag): ?string
    {
        foreach ($this->locales as $offered) {
            if (strcasecmp($offered, $tag) === 0) {
                return $offered;
            }
        }

        return null;
    }

    /**
     * The offered script of a multi-script language, chosen by the subtag after the language: a four-letter
     * script subtag is taken as it stands, anything else is read as a region and looked up, with the
     * language's default script for a region not listed or no subtag at all.
     */
    private function byScript(string $language, string $next): ?string
    {
        $scripts = self::SCRIPTS[strtolower($language)] ?? null;
        if ($scripts === null) {
            return null;
        }

        $script = preg_match('/^[a-z]{4}$/i', $next) === 1
            ? $next
            : ($scripts[strtoupper($next)] ?? $scripts['default']);

        return $this->exact($language.'-'.$script);
    }

    private function byLanguage(string $language): ?string
    {
        foreach ($this->locales as $offered) {
            if (strcasecmp(explode('-', $offered, 2)[0], $language) === 0) {
                return $offered;
            }
        }

        return null;
    }
}
