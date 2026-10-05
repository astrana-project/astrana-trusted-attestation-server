<?php

declare(strict_types=1);

namespace App\Services;

use App\Contract\RelationshipTypeCatalog;
use App\Exceptions\ConfigurationException;

/**
 * Text for the landing page and the self-service page, in the member's own language.
 *
 * Backed by a JSON data file rather than Laravel's lang files, for the same reason the governed vocabulary
 * is a data file. The set is small, and keeping it as data means a translator can add a locale without
 * touching the application, and the same file is shared verbatim across all three implementations.
 *
 * Relationship-type labels do not live here. They come from the contract's own file, so the same
 * relationship reads the same way whichever organisation issued it.
 */
final class UiStrings
{
    private const RESOURCE = 'ui-strings.json';

    /** @var array<string, array<string, string>> */
    private readonly array $byLocale;

    public function __construct(?string $path = null)
    {
        $path ??= resource_path(self::RESOURCE);

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new ConfigurationException(self::problem('could not be read.'));
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new ConfigurationException(self::problem('is not valid JSON.'));
        }

        $byLocale = [];
        foreach ($decoded as $locale => $strings) {
            // Keys beginning with "_" are notes to whoever edits the file, not a locale.
            if (! is_string($locale) || str_starts_with($locale, '_') || ! is_array($strings)) {
                continue;
            }

            $byLocale[$locale] = $strings;
        }

        if (! isset($byLocale['en'])) {
            throw new ConfigurationException(
                self::problem('has no "en" block. English is the fallback and must be complete.')
            );
        }

        $this->byLocale = $byLocale;
    }

    /**
     * Every string for one locale, English-backfilled. Handed to the page as one array rather than looked
     * up key by key, since the page's script needs the same strings the server-rendered markup does.
     *
     * Never fails for a missing key: a page with one untranslated label is better than a page that will
     * not render.
     *
     * @return array<string, string>
     */
    /**
     * The most specific packaged locale that actually backs a request for $locale, or "en".
     *
     * This is the locale the page is really rendered in, so it -- not the raw request locale, and not
     * Laravel's app locale, which this application never sets -- is what the html lang and dir must
     * reflect. Ask for a language we do not have and the page is English; it must say so rather than
     * advertise the language it was asked for and, if that language is right-to-left, mirror itself.
     */
    public function resolve(?string $locale): string
    {
        foreach (RelationshipTypeCatalog::localeFallbacks($locale) as $candidate) {
            if (isset($this->byLocale[$candidate])) {
                return $candidate;
            }
        }

        return 'en';
    }

    /**
     * The locales this instance can actually serve, taken from the shared strings file rather than a
     * list repeated in the controller -- so a translator adding a locale to ui-strings.json makes it
     * offered here without a second edit, and all three implementations offer exactly the same set.
     *
     * English first, as the guaranteed-complete fallback.
     *
     * @return list<string>
     */
    public function supported(): array
    {
        $others = array_values(array_filter(
            array_keys($this->byLocale),
            static fn (string $locale): bool => $locale !== 'en',
        ));

        return array_merge(['en'], $others);
    }

    public function all(?string $locale): array
    {
        $result = $this->byLocale['en'];

        foreach (array_reverse(RelationshipTypeCatalog::localeFallbacks($locale)) as $candidate) {
            if (isset($this->byLocale[$candidate])) {
                $result = array_merge($result, $this->byLocale[$candidate]);
            }
        }

        return $result;
    }

    /**
     * One string for one locale, falling back to the more general language (fr-CA to fr), then to English,
     * then to the key itself. This is how the language switcher names each locale it lists: by that
     * locale's own language_endonym, not the current page's.
     */
    public function get(string $key, ?string $locale): string
    {
        foreach (RelationshipTypeCatalog::localeFallbacks($locale) as $candidate) {
            if (isset($this->byLocale[$candidate][$key]) && is_string($this->byLocale[$candidate][$key])) {
                return $this->byLocale[$candidate][$key];
            }
        }

        $english = $this->byLocale['en'][$key] ?? $key;

        return is_string($english) ? $english : $key;
    }

    /**
     * Prefixes a complaint with the file it is about, so every one of them names it.
     *
     * <p>Same reasoning as the contract loaders: these messages are the whole diagnosis when the app
     * refuses to start, and a prefix written by hand at each throw is one that will eventually be
     * written without the filename.
     */
    private static function problem(string $detail): string
    {
        return 'Resource file "'.self::RESOURCE.'" '.$detail;
    }
}
