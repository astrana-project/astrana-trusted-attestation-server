<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contract\Attribution;
use App\Contract\RelationshipTypeCatalog;
use App\Http\Controllers\PageController;
use App\Models\MemberRelationship;
use App\Services\AuditLog;
use App\Services\LocalizationSettings;
use App\Services\ManifestBuilder;
use App\Services\MemberIdentityResolver;
use App\Services\RelationshipService;
use App\Services\RelationshipStore;
use App\Services\TransactionRunner;
use App\Services\UiStrings;
use App\Support\PublicKeys;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\View\View;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The page controller's decisions, isolated from the database and from rendering.
 *
 * The conformance suite renders these pages for real; this pins the reasoning the controller owns before
 * a template is ever touched -- redirect an unauthenticated caller rather than show them the self-service page,
 * let an IAM locale claim win over Accept-Language, resolve the org name through the locale-fallback
 * chain, and assemble the per-relationship view model. Assertions read the View's model rather than its
 * HTML: it is the model these tests are about, and the Blade file is the conformance suite's concern (and,
 * for the language switcher, LanguageSwitcherTest's). The locale cookie's precedence over the claim and the
 * browser, and the constraining of both to the offered set, are pinned here as well.
 *
 * A Feature test rather than a Unit one, and for the reason MemberIdentityResolverTest already is: the
 * controller and its collaborators read configuration and resource files, so they need the application
 * container -- but nothing here touches a database. The RelationshipService is real, driven from below by
 * a hand-written store fake; every other collaborator is the real one the container wires in production.
 */
final class PageControllerTest extends TestCase
{
    private PageStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new PageStore;
    }

    private function controller(?UiStrings $strings = null): PageController
    {
        $service = new RelationshipService(
            $this->store,
            new PageRunner,
            new PageAudit,
            $this->app->make(RelationshipTypeCatalog::class),
        );
        $strings ??= $this->app->make(UiStrings::class);

        // The offered set is built from the strings in play, so a fixture that ships Arabic offers Arabic.
        $configured = config('trusted_attestation.manifest.supported_locales', []);
        $localization = new LocalizationSettings(
            is_array($configured) ? $configured : [],
            $strings->supported(),
            (string) config('trusted_attestation.manifest.default_locale', 'en'),
            array_combine($strings->supported(), array_map(
                static fn (string $l): string => $strings->get('language_endonym', $l),
                $strings->supported(),
            )),
        );

        return new PageController(
            $this->app->make(MemberIdentityResolver::class),
            $service,
            $this->app->make(RelationshipTypeCatalog::class),
            $strings,
            $this->app->make(ManifestBuilder::class),
            $this->app->make(Attribution::class),
            $localization,
        );
    }

    /**
     * @param  array<string, mixed>|null  $claims
     * @param  array<string, string>  $cookies
     */
    private function request(?array $claims = null, ?string $acceptLanguage = null, array $cookies = []): Request
    {
        $session = new Store('ata_session', new ArraySessionHandler(60));
        if ($claims !== null) {
            $session->put(MemberIdentityResolver::SESSION_KEY, $claims);
        }

        $server = $acceptLanguage === null ? [] : ['HTTP_ACCEPT_LANGUAGE' => $acceptLanguage];
        $request = Request::create('/me', 'GET', [], $cookies, [], $server);
        $request->setLaravelSession($session);

        return $request;
    }

    private function keyedRelationship(string $type): MemberRelationship
    {
        $row = new MemberRelationship;
        $row->id = 1;
        $row->iam_subject_id = 'alice';
        $row->relationship_type = $type;
        $row->relationship_subtype = null;
        $row->setAttribute('public_key_hex', bin2hex(str_repeat('k', PublicKeys::LENGTH)));
        $row->expires_at = null;
        $row->revoked_at = null;

        return $row;
    }

    // -- landing -------------------------------------------------------------------------------------

    #[Test]
    public function the_landing_page_renders_the_landing_view_and_is_not_signed_out_by_default(): void
    {
        $view = $this->controller()->landing($this->request());

        self::assertInstanceOf(View::class, $view);
        self::assertSame('landing', $view->name());
        // The default: a plain visit is not the post-logout confirmation.
        self::assertFalse($view->getData()['signedOut']);
        self::assertArrayHasKey('attribution', $view->getData());
        self::assertIsArray($view->getData()['t']);
    }

    #[Test]
    public function the_signed_out_landing_page_carries_the_confirmation_flag(): void
    {
        // The /signed-out route's whole purpose: the same page, told to show the logout confirmation.
        $view = $this->controller()->landing($this->request(), true);

        self::assertTrue($view->getData()['signedOut']);
    }

    #[Test]
    public function the_landing_page_labels_itself_in_the_requested_language(): void
    {
        // lang/dir describe the language actually rendered, resolved from Accept-Language -- not Laravel's
        // app locale, which this application never sets.
        $view = $this->controller()->landing($this->request(acceptLanguage: 'fr'));

        self::assertSame('fr', $view->getData()['htmlLang']);
        self::assertSame('ltr', $view->getData()['htmlDir']);
    }

    #[Test]
    public function the_org_name_is_chosen_through_the_locale_fallback_chain(): void
    {
        config(['trusted_attestation.manifest.name' => ['en' => 'Acme Bank', 'fr' => 'Banque Acme']]);

        self::assertSame('Banque Acme', $this->controller()->landing($this->request(acceptLanguage: 'fr'))->getData()['orgName']);
    }

    #[Test]
    public function the_org_name_falls_back_to_the_default_locale_when_the_requested_one_is_absent(): void
    {
        // German is offered by the strings file but the org named itself only in en/fr; the manifest's
        // default_locale is where the name resolution lands rather than coming back empty.
        config(['trusted_attestation.manifest.name' => ['en' => 'Acme Bank', 'fr' => 'Banque Acme']]);

        self::assertSame('Acme Bank', $this->controller()->landing($this->request(acceptLanguage: 'de'))->getData()['orgName']);
    }

    #[Test]
    public function a_right_to_left_rendered_locale_mirrors_the_page(): void
    {
        // The dir logic keys off the resolved locale. This drives it with an Arabic fixture, so it holds
        // whatever the shipped strings file carries. A regression that dropped the right-to-left check
        // would leave an Arabic page laid out left-to-right.
        $strings = new UiStrings($this->fixture(['en' => ['x' => 'x'], 'ar' => ['x' => 'س']]));

        $view = $this->controller($strings)->landing($this->request(acceptLanguage: 'ar'));

        self::assertSame('ar', $view->getData()['htmlLang']);
        self::assertSame('rtl', $view->getData()['htmlDir']);
    }

    #[Test]
    public function pashto_is_rendered_right_to_left(): void
    {
        // The fifth member of the fixed right-to-left set.
        // The shipped strings file carries Pashto, so this runs against the real file.
        $view = $this->controller()->landing($this->request(acceptLanguage: 'ps'));

        self::assertSame('ps', $view->getData()['htmlLang']);
        self::assertSame('rtl', $view->getData()['htmlDir']);
    }

    #[Test]
    public function the_landing_page_falls_back_to_the_default_locale_when_nothing_matches(): void
    {
        config(['trusted_attestation.manifest.default_locale' => 'fr']);
        config(['trusted_attestation.manifest.name' => ['fr' => 'Banque Acme']]);

        // Welsh is not shipped: the organisation's own default is what the page renders in, not English
        // by virtue of being first in the file.
        $view = $this->controller()->landing($this->request(acceptLanguage: 'cy'));

        self::assertSame('fr', $view->getData()['htmlLang']);
    }

    #[Test]
    public function the_landing_page_offers_only_the_configured_locales_to_accept_language(): void
    {
        config(['trusted_attestation.manifest.supported_locales' => ['en', 'fr']]);

        // German is shipped but not offered by this organisation, so a German browser gets the default,
        // the same as it would from the .NET implementation's SupportedCultures.
        $view = $this->controller()->landing($this->request(acceptLanguage: 'de, fr;q=0.5'));

        self::assertSame('fr', $view->getData()['htmlLang']);
    }

    #[Test]
    public function accept_language_is_matched_entry_by_entry_in_the_headers_order(): void
    {
        // Nothing offered for the first entry, so the second is tried, and zh-TW reaches the script rule as
        // the raw tag: Symfony's preferred-language shortcut would have paired it with zh-Hans by prefix.
        $view = $this->controller()->landing($this->request(acceptLanguage: 'zz, zh-TW;q=0.8'));

        self::assertSame('zh-Hant', $view->getData()['htmlLang']);
    }

    #[Test]
    public function an_accept_language_entry_with_zero_quality_is_skipped(): void
    {
        // q=0 means "not this language", so French is passed over and German, the next entry, wins, as
        // it does on the other two implementations.
        $view = $this->controller()->landing($this->request(acceptLanguage: 'fr;q=0, de;q=0.5'));

        self::assertSame('de', $view->getData()['htmlLang']);
    }

    #[Test]
    public function a_chinese_region_in_accept_language_resolves_to_its_script(): void
    {
        self::assertSame('zh-Hans', $this->controller()->landing($this->request(acceptLanguage: 'zh-CN'))->getData()['htmlLang']);
        self::assertSame('zh-Hant', $this->controller()->landing($this->request(acceptLanguage: 'zh-HK'))->getData()['htmlLang']);
        self::assertSame('zh-Hans', $this->controller()->landing($this->request(acceptLanguage: 'zh-Hans-TW'))->getData()['htmlLang']);
    }

    #[Test]
    public function a_chinese_tag_falls_back_to_the_one_script_this_organisation_offers(): void
    {
        config(['trusted_attestation.manifest.supported_locales' => ['en', 'zh-Hans']]);

        $view = $this->controller()->landing($this->request(acceptLanguage: 'zh-TW'));

        self::assertSame('zh-Hans', $view->getData()['htmlLang']);
    }

    #[Test]
    public function a_regional_accept_language_still_falls_back_to_its_language(): void
    {
        self::assertSame('fr', $this->controller()->landing($this->request(acceptLanguage: 'fr-CA'))->getData()['htmlLang']);
    }

    // -- the locale cookie -----------------------------------------------------------------------------

    #[Test]
    public function the_locale_cookie_wins_over_the_iam_claim_and_accept_language(): void
    {
        // A member who picked a language with the switcher has said what they want.
        $view = $this->controller()->me($this->request(
            ['sub' => 'alice', 'locale' => 'fr'],
            acceptLanguage: 'de',
            cookies: ['ata_locale' => 'es'],
        ));

        self::assertSame('es', $view->getData()['htmlLang']);
    }

    #[Test]
    public function the_locale_cookie_wins_on_the_landing_page_too(): void
    {
        $view = $this->controller()->landing($this->request(acceptLanguage: 'de', cookies: ['ata_locale' => 'fr']));

        self::assertSame('fr', $view->getData()['htmlLang']);
    }

    #[Test]
    public function a_signed_in_members_locale_claim_applies_on_the_landing_page_as_on_me(): void
    {
        // The claim outranks Accept-Language on / as it does on /me, so a member does not see the landing
        // page in one language and their own page in another.
        $view = $this->controller()->landing($this->request(['sub' => 'alice', 'locale' => 'fr'], acceptLanguage: 'de'));

        self::assertSame('fr', $view->getData()['htmlLang']);
    }

    #[Test]
    public function a_cookie_naming_an_unshipped_locale_is_ignored(): void
    {
        // Forged or stale: resolution falls through to the claim as if the cookie were absent.
        $view = $this->controller()->me($this->request(
            ['sub' => 'alice', 'locale' => 'fr'],
            acceptLanguage: 'de',
            cookies: ['ata_locale' => 'cy'],
        ));

        self::assertSame('fr', $view->getData()['htmlLang']);
    }

    #[Test]
    public function a_cookie_naming_a_locale_this_organisation_does_not_offer_is_ignored(): void
    {
        // Shipped, but not in this organisation's list: the same as not shipped, so a cookie set when the
        // list was wider does not keep rendering a language no longer offered.
        config(['trusted_attestation.manifest.supported_locales' => ['en', 'fr']]);

        $view = $this->controller()->landing($this->request(acceptLanguage: 'fr', cookies: ['ata_locale' => 'de']));

        self::assertSame('fr', $view->getData()['htmlLang']);
    }

    #[Test]
    public function a_blank_cookie_is_ignored(): void
    {
        $view = $this->controller()->landing($this->request(acceptLanguage: 'fr', cookies: ['ata_locale' => '']));

        self::assertSame('fr', $view->getData()['htmlLang']);
    }

    #[Test]
    public function a_regional_cookie_falls_back_to_its_offered_language(): void
    {
        // Constrained the same way the .NET SupportedCultures constrain it: fr-CA renders as fr.
        $view = $this->controller()->landing($this->request(cookies: ['ata_locale' => 'fr-CA']));

        self::assertSame('fr', $view->getData()['htmlLang']);
    }

    #[Test]
    public function the_chrome_carries_what_the_switcher_renders_from(): void
    {
        $data = $this->controller()->landing($this->request(cookies: ['ata_locale' => 'fr']))->getData();

        self::assertInstanceOf(LocalizationSettings::class, $data['localization']);
        self::assertInstanceOf(UiStrings::class, $data['strings']);
        // The page to come back to after a choice: the request's own path and query.
        self::assertSame('/me', $data['returnTo']);
    }

    // -- me ------------------------------------------------------------------------------------------

    #[Test]
    public function me_redirects_an_unauthenticated_caller_to_login(): void
    {
        $response = $this->controller()->me($this->request(null));

        self::assertInstanceOf(RedirectResponse::class, $response);
        // next=/me so the member lands back where they were trying to go.
        self::assertStringContainsString('/auth/login?next=/me', $response->getTargetUrl());
    }

    #[Test]
    public function me_ends_a_session_that_holds_claims_but_no_subject_and_shows_the_landing_page(): void
    {
        // Signed in as nobody. Sent to sign in it would come straight back in the same state, without
        // end, so the session is ended and the landing page shown, from which a fresh sign-in starts.
        $request = $this->request(['name' => 'Nobody']);

        $response = $this->controller()->me($request);

        self::assertInstanceOf(View::class, $response);
        self::assertSame('landing', $response->name());
        self::assertFalse($request->session()->has(MemberIdentityResolver::SESSION_KEY));
    }

    #[Test]
    public function me_shows_the_member_their_name_and_every_relationship_they_hold(): void
    {
        $this->store->all = [$this->keyedRelationship('employee')];

        $view = $this->controller()->me($this->request(['sub' => 'alice', 'name' => 'Alice Anderson']));
        $data = $view->getData();

        self::assertInstanceOf(View::class, $view);
        self::assertSame('me', $view->name());
        self::assertSame('Alice Anderson', $data['memberName']);
        self::assertCount(1, $data['relationships']);
        self::assertSame('employee', $data['relationships'][0]['relationshipType']);
        self::assertSame(
            $this->app->make(RelationshipTypeCatalog::class)->label('employee', 'en'),
            $data['relationships'][0]['label'],
        );
        self::assertSame(base64_encode(str_repeat('k', PublicKeys::LENGTH)), $data['relationships'][0]['publicKey']);
    }

    #[Test]
    public function me_shows_the_expiry_as_the_api_returns_it(): void
    {
        // The page and the API write the same string for the same row, in the one format all three
        // implementations use: the fraction trimmed, and absent when zero.
        // Raw, as a row hydrated from the database arrives: assigning a date through setAttribute formats
        // it to whole seconds, and the fraction is the point.
        $fractional = $this->keyedRelationship('employee');
        $fractional->setRawAttributes(['expires_at' => '2030-06-01 12:00:00.250000'] + $fractional->getAttributes());
        $whole = $this->keyedRelationship('client');
        $whole->setRawAttributes(['expires_at' => '2030-06-01 12:00:00'] + $whole->getAttributes());
        $this->store->all = [$fractional, $whole];

        $relationships = $this->controller()->me($this->request(['sub' => 'alice']))->getData()['relationships'];

        self::assertSame('2030-06-01T12:00:00.25Z', $relationships[0]['expiresAt']);
        self::assertSame('2030-06-01T12:00:00Z', $relationships[1]['expiresAt']);
    }

    #[Test]
    public function me_renders_for_a_member_who_holds_nothing(): void
    {
        // An empty list is an ordinary state, not an error: the page still renders, it is not a redirect.
        $this->store->all = [];

        $view = $this->controller()->me($this->request(['sub' => 'dave']));

        self::assertInstanceOf(View::class, $view);
        self::assertSame([], $view->getData()['relationships']);
    }

    #[Test]
    public function me_prefers_the_iam_locale_claim_over_accept_language(): void
    {
        // The organisation knows which language it holds this member's record in, so its claim wins over
        // whatever the browser happens to advertise.
        $view = $this->controller()->me($this->request(['sub' => 'alice', 'locale' => 'fr'], acceptLanguage: 'de'));

        self::assertSame('fr', $view->getData()['htmlLang']);
    }

    #[Test]
    public function me_falls_back_to_accept_language_when_no_locale_claim_is_present(): void
    {
        $view = $this->controller()->me($this->request(['sub' => 'alice'], acceptLanguage: 'de'));

        self::assertSame('de', $view->getData()['htmlLang']);
    }

    #[Test]
    public function me_ignores_a_locale_claim_this_instance_does_not_offer(): void
    {
        // The claim is constrained to the offered set exactly as the cookie is, so an IdP that holds the
        // record in a language this instance has no strings for does not pre-empt the browser's choice.
        $view = $this->controller()->me($this->request(['sub' => 'alice', 'locale' => 'cy'], acceptLanguage: 'de'));

        self::assertSame('de', $view->getData()['htmlLang']);
    }

    #[Test]
    public function me_falls_a_regional_claim_back_to_its_language(): void
    {
        $view = $this->controller()->me($this->request(['sub' => 'alice', 'locale' => 'fr-CA'], acceptLanguage: 'de'));

        self::assertSame('fr', $view->getData()['htmlLang']);
    }

    /**
     * Writes a UiStrings fixture to a temp file and returns its path.
     *
     * @param  array<string, array<string, string>>  $blocks
     */
    private function fixture(array $blocks): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ui').'.json';
        file_put_contents($path, (string) json_encode($blocks, JSON_THROW_ON_ERROR));
        $this->fixtures[] = $path;

        return $path;
    }

    /** @var list<string> */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }
}

/** Runs the operation inline; the page controller only ever reads, so this is never really exercised. */
final class PageRunner implements TransactionRunner
{
    public function run(Closure $operation): mixed
    {
        return $operation();
    }
}

final class PageAudit implements AuditLog
{
    public function keyRegistered(string $iamSubjectId, string $relationshipType): void {}

    public function keyCleared(string $iamSubjectId, string $relationshipType): void {}

    public function keyRemoved(string $iamSubjectId, string $relationshipType): void {}
}

/** Answers findAllHeldBy from a list the test sets; the page controller reads nothing else. */
final class PageStore implements RelationshipStore
{
    /** @var list<MemberRelationship> */
    public array $all = [];

    public function findAllHeldBy(string $iamSubjectId): Collection
    {
        return new Collection($this->all);
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
