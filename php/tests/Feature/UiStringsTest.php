<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\UiStrings;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The landing page and the self-service page, in the member's own language.
 *
 * Rendering in the member's language is a requirement rather than polish, and the failure modes here are
 * all quiet ones: a missing key shows an untranslated label, a broken fallback shows English to someone
 * who asked for French, and neither throws nor logs. The .NET and Java implementations have the same
 * tests for the same reasons.
 *
 * A Feature test rather than a Unit one because the default constructor resolves its path through
 * resource_path(), which needs the application container. It touches no database and no network.
 */
final class UiStringsTest extends TestCase
{
    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ata-ui').'.json';
        file_put_contents($path, $contents);
        $this->written[] = $path;

        return $path;
    }

    // ---------------------------------------------------------------------------------------------
    // Falling back, against the shipped file
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function an_exact_locale_is_used_when_it_exists(): void
    {
        self::assertSame('Se déconnecter', (new UiStrings)->all('fr')['sign_out']);
    }

    #[Test]
    public function a_region_falls_back_to_its_language(): void
    {
        // fr-CA is not shipped; fr is. A Canadian member gets French rather than English, which is the
        // whole point of the fallback and the case a lookup written as an exact match would miss.
        $strings = new UiStrings;

        self::assertSame($strings->all('fr')['sign_out'], $strings->all('fr-CA')['sign_out']);
    }

    #[Test]
    public function an_unknown_language_falls_back_to_english(): void
    {
        $strings = new UiStrings;

        self::assertSame($strings->all('en'), $strings->all('cy-GB'));
    }

    #[Test]
    public function no_locale_at_all_is_english_rather_than_an_error(): void
    {
        $strings = new UiStrings;

        self::assertSame($strings->all('en'), $strings->all(null));
        self::assertSame($strings->all('en'), $strings->all(''));
    }

    // ---------------------------------------------------------------------------------------------
    // Resolving the rendered locale -- what the html lang and dir must reflect
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function resolve_returns_the_packaged_locale_that_backs_a_request(): void
    {
        $strings = new UiStrings;

        self::assertSame('fr', $strings->resolve('fr'));
        self::assertSame('de', $strings->resolve('de'));
        self::assertSame('en', $strings->resolve('en'));
    }

    #[Test]
    public function resolve_falls_a_region_back_to_its_language(): void
    {
        $strings = new UiStrings;

        self::assertSame('fr', $strings->resolve('fr-CA'));
        self::assertSame('de', $strings->resolve('de_AT'));
    }

    #[Test]
    public function resolve_clamps_an_untranslated_language_to_english(): void
    {
        // The bug this guards: a request for a language we do not have was answered in English, yet the
        // page advertised itself -- lang and dir -- as that language; for a right-to-left one that meant
        // an English page mirrored. The page's lang and dir are computed from resolve(), so an unknown
        // language, and any right-to-left one we do not ship, must come back "en".
        $strings = new UiStrings;

        // A right-to-left language we do ship resolves to itself -- the page renders correctly right-to-left.
        self::assertSame('ar', $strings->resolve('ar'));
        self::assertSame('he', $strings->resolve('he'));

        // A language we do not ship -- including a right-to-left one -- clamps to English, so the page is
        // never advertised as a language it is actually showing in English. Yiddish (RTL) and Welsh are not
        // in the shipped set.
        self::assertSame('en', $strings->resolve('yi'));
        self::assertSame('en', $strings->resolve('cy-GB'));
    }

    #[Test]
    public function resolve_is_english_for_no_locale_at_all(): void
    {
        $strings = new UiStrings;

        self::assertSame('en', $strings->resolve(null));
        self::assertSame('en', $strings->resolve(''));
    }

    #[Test]
    public function every_locale_gets_a_complete_english_backfilled_set(): void
    {
        // The page's template and script index into this array directly; a missing key renders as an
        // empty label in the member's browser.
        $strings = new UiStrings;
        $english = array_keys($strings->all('en'));
        sort($english);

        foreach (['fr', 'de', 'cy-GB'] as $locale) {
            $keys = array_keys($strings->all($locale));
            sort($keys);
            self::assertSame($english, $keys, "keys for {$locale}");
        }
    }

    // ---------------------------------------------------------------------------------------------
    // The shipped file itself
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function every_shipped_locale_carries_every_key(): void
    {
        // Read from the file directly rather than through the lookup, because the lookup backfills from
        // English and cannot tell "not translated" from "translates to the same word". This is the
        // check that catches a string added in one language and forgotten in the others; only the
        // member ever sees that.
        $decoded = json_decode((string) file_get_contents(resource_path('ui-strings.json')), true);
        self::assertIsArray($decoded);

        $locales = array_filter(
            $decoded,
            fn ($v, $k) => is_array($v) && ! str_starts_with((string) $k, '_'),
            ARRAY_FILTER_USE_BOTH
        );

        $english = array_keys($locales['en']);
        sort($english);

        foreach ($locales as $locale => $strings) {
            $keys = array_keys($strings);
            sort($keys);
            self::assertSame($english, $keys, "{$locale} declares a different key set to en");
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Refusing to start on a broken file
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_missing_file_names_itself(): void
    {
        // These messages are the whole diagnosis when the app refuses to start.
        try {
            new UiStrings('/nowhere/ui-strings.json');
            self::fail('a missing file was accepted');
        } catch (RuntimeException $refusal) {
            self::assertStringContainsString('ui-strings.json', $refusal->getMessage());
        }
    }

    #[Test]
    public function a_file_that_is_not_json_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        new UiStrings($this->file('<html>not json</html>'));
    }

    #[Test]
    public function a_file_with_no_english_block_is_refused(): void
    {
        // English is the backfill source; without it every lookup would fail somewhere less obvious.
        $this->expectException(RuntimeException::class);

        new UiStrings($this->file('{"fr": {"sign_out": "Se déconnecter"}}'));
    }

    #[Test]
    public function editor_notes_are_not_mistaken_for_locales(): void
    {
        $strings = new UiStrings($this->file(
            '{"_note": "for translators", "en": {"sign_out": "Sign out"}}'
        ));

        self::assertSame(['sign_out' => 'Sign out'], $strings->all('_note'));
    }
}
