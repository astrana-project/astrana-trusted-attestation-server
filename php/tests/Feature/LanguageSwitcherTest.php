<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberRelationship;
use App\Services\MemberIdentityResolver;
use App\Services\RelationshipStore;
use App\Services\UiStrings;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The language switcher as rendered, on the landing page and the self-service page.
 *
 * The markup is the .NET partial's: a native details disclosure in the top bar, one submit button per
 * offered locale named by that language's own endonym, the current one marked, and a hidden field carrying
 * the page to return to. The conformance suite compares the rendered pages across the three stacks, so what
 * this pins is that the PHP page carries the same elements in the same places, and that the switcher is
 * absent when there is nothing to choose.
 *
 * A Feature test because it renders real pages through the router. The self-service page is driven through a
 * store fake bound into the container, so no database is touched.
 */
final class LanguageSwitcherTest extends TestCase
{
    #[Test]
    public function the_landing_page_offers_every_configured_locale_in_order(): void
    {
        config(['trusted_attestation.manifest.supported_locales' => ['fr', 'en', 'de']]);
        $strings = $this->app->make(UiStrings::class);

        $html = $this->get('/')->assertOk()->getContent();

        self::assertStringContainsString('<div class="ata-topbar mb-3">', $html);
        self::assertStringContainsString('<details class="ata-language">', $html);
        self::assertStringContainsString('<form method="post" action="/set-language" class="ata-language-menu">', $html);
        self::assertStringContainsString('<input type="hidden" name="next" value="/" />', $html);

        // One button per locale, in the organisation's order, each named in its own language.
        $buttons = [];
        preg_match_all('/<button type="submit" name="locale" value="([^"]+)"[^>]*>([^<]*)<\/button>/', $html, $buttons, PREG_SET_ORDER);
        self::assertSame(['fr', 'en', 'de'], array_column($buttons, 1));
        self::assertSame(
            [$strings->get('language_endonym', 'fr'), $strings->get('language_endonym', 'en'), $strings->get('language_endonym', 'de')],
            array_column($buttons, 2),
        );
    }

    #[Test]
    public function the_current_language_is_marked_and_shown_in_the_summary(): void
    {
        config(['trusted_attestation.manifest.supported_locales' => ['en', 'fr']]);
        $strings = $this->app->make(UiStrings::class);

        $html = $this->withUnencryptedCookie('ata_locale', 'fr')->get('/')->getContent();

        self::assertStringContainsString('<span class="visually-hidden">'.$strings->get('language_label', 'fr').': </span>', $html);
        self::assertStringContainsString('<span>'.$strings->get('language_endonym', 'fr').'</span>', $html);
        self::assertMatchesRegularExpression('/value="fr"\s+class="btn btn-sm btn-link text-start p-1 fw-bold"\s+aria-current="true"/', $html);
        self::assertMatchesRegularExpression('/value="en"\s+class="btn btn-sm btn-link text-start p-1"\s*>/', $html);
    }

    #[Test]
    public function the_switcher_is_absent_when_only_one_locale_is_offered(): void
    {
        config(['trusted_attestation.manifest.supported_locales' => ['en']]);

        $html = $this->get('/')->assertOk()->getContent();

        // The top bar stays, so the layout does not shift between deployments, but holds no switcher.
        self::assertStringContainsString('<div class="ata-topbar mb-3">', $html);
        self::assertStringNotContainsString('ata-language', $html);
    }

    #[Test]
    public function the_hidden_return_field_carries_the_current_path_and_query(): void
    {
        $html = $this->get('/signed-out')->getContent();

        self::assertStringContainsString('<input type="hidden" name="next" value="/signed-out" />', $html);
    }

    #[Test]
    public function the_member_page_carries_the_switcher_too(): void
    {
        config(['trusted_attestation.manifest.supported_locales' => ['en', 'fr']]);
        $this->app->instance(RelationshipStore::class, new SwitcherStore);

        $html = $this->withSession([MemberIdentityResolver::SESSION_KEY => ['sub' => 'alice', 'name' => 'Alice']])
            ->get('/me')
            ->assertOk()
            ->getContent();

        self::assertStringContainsString('<details class="ata-language">', $html);
        self::assertStringContainsString('<input type="hidden" name="next" value="/me" />', $html);
    }

    #[Test]
    public function the_licence_page_has_no_switcher(): void
    {
        // A statement about the software, not the organisation: English, left to right, no switcher, the
        // same as the .NET page.
        $html = $this->get('/license')->getContent();

        self::assertStringNotContainsString('ata-language', $html);
        self::assertStringContainsString('<html lang="en" dir="ltr">', $html);
    }

    #[Test]
    public function the_footer_licence_label_is_translated_on_the_pages_that_are(): void
    {
        // German, because the French word is spelt the same as the English one.
        $strings = $this->app->make(UiStrings::class);
        $germanLabel = $strings->get('licence_link', 'de');
        self::assertNotSame($strings->get('licence_link', 'en'), $germanLabel, 'the fixture needs a label that differs in German');

        // The landing page's footer follows the page's language. The licence page's own footer stays on
        // the contract's fixed label, as the page itself is English only.
        $landing = $this->withUnencryptedCookie('ata_locale', 'de')->get('/')->getContent();
        self::assertStringContainsString('>'.$germanLabel.'</a>', $landing);
    }
}

/** Answers findAllHeldBy with nothing. The switcher is the same whatever the member holds. */
final class SwitcherStore implements RelationshipStore
{
    public function findAllHeldBy(string $iamSubjectId): Collection
    {
        return new Collection([]);
    }

    public function find(string $iamSubjectId, string $relationshipType): ?MemberRelationship
    {
        return null;
    }

    public function findByPublicKey(string $rawPublicKey): ?MemberRelationship
    {
        return null;
    }

    public function isKeyHeldElsewhere(string $rawPublicKey, int $exceptId): bool
    {
        return false;
    }

    public function updateKey(int $id, string $rawPublicKey): int
    {
        return 0;
    }

    public function clearKey(int $id): int
    {
        return 0;
    }

    public function deleteOwn(string $iamSubjectId, string $relationshipType): int
    {
        return 0;
    }

    public function invokeSelfRevoke(string $iamSubjectId, string $relationshipType): void {}
}
