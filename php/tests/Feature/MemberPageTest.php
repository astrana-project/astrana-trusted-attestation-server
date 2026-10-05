<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberRelationship;
use App\Services\MemberIdentityResolver;
use App\Services\RelationshipStore;
use App\Support\PublicKeys;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProgrammableRelationshipStore;
use Tests\TestCase;

/**
 * What the rendered self-service page shows for a relationship, where the template itself decides.
 *
 * PageControllerTest pins the view model. This renders the Blade file through the router, for the few
 * decisions the template makes on its own: whether a subtype is shown, and that the expiry is printed as
 * the model gives it. A Feature test because it renders a real page. The store is a fake bound into the
 * container, so no database is touched.
 */
final class MemberPageTest extends TestCase
{
    private ProgrammableRelationshipStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new ProgrammableRelationshipStore;
        $this->app->instance(RelationshipStore::class, $this->store);
    }

    private function relationship(string $type, ?string $subtype, ?string $expiresAt = null): MemberRelationship
    {
        $row = new MemberRelationship;
        $row->id = 1;
        $row->iam_subject_id = 'alice';
        $row->relationship_type = $type;
        $row->relationship_subtype = $subtype;
        $row->setAttribute('public_key_hex', bin2hex(str_repeat('k', PublicKeys::LENGTH)));
        // Raw, as a row hydrated from the database arrives, so a fraction of a second survives.
        $row->setRawAttributes(['expires_at' => $expiresAt, 'revoked_at' => null] + $row->getAttributes());

        return $row;
    }

    private function page(): string
    {
        return $this->withSession([MemberIdentityResolver::SESSION_KEY => ['sub' => 'alice', 'name' => 'Alice']])
            ->get('/me')
            ->assertOk()
            ->getContent();
    }

    #[Test]
    public function a_subtype_of_zero_is_shown_because_it_is_set_and_not_empty(): void
    {
        // PHP reads the string "0" as false, so a bare truthiness test hid this subtype while the other two
        // implementations showed it. A subtype is shown when it is not null and not empty, whatever it says.
        $this->store->all = [$this->relationship('employee', '0')];

        self::assertStringContainsString('&middot; <span class="fw-normal text-body-secondary">0</span>', $this->page());
    }

    #[Test]
    public function an_absent_or_empty_subtype_is_not_shown(): void
    {
        $this->store->all = [$this->relationship('employee', null), $this->relationship('client', '')];

        self::assertStringNotContainsString('&middot; <span class="fw-normal', $this->page());
    }

    #[Test]
    public function the_page_carries_the_sessions_anti_forgery_token_for_its_script(): void
    {
        $page = $this->withSession([
            MemberIdentityResolver::SESSION_KEY => ['sub' => 'alice', 'name' => 'Alice'],
            '_token' => 'the-session-token',
        ])->get('/me')->assertOk()->getContent();

        self::assertStringContainsString('<meta name="csrf-token" content="the-session-token">', $page);
    }

    #[Test]
    public function the_script_sends_the_token_on_revoke_in_the_shared_header(): void
    {
        $this->store->all = [$this->relationship('employee', null)];
        $page = $this->page();

        $revoke = substr($page, (int) strpos($page, 'async function revoke('));
        $revoke = substr($revoke, 0, (int) strpos($revoke, 'async function remove('));

        self::assertStringContainsString('"X-CSRF-TOKEN": csrfToken', $revoke);
        self::assertStringContainsString(
            'const csrfToken = document.querySelector("meta[name=csrf-token]").content;',
            $page
        );
    }

    /** The opening tag of the relationship's key field, from its start to its closing bracket. */
    private static function keyInput(string $page): string
    {
        $start = (int) strrpos(substr($page, 0, (int) strpos($page, 'data-key-input')), '<input');

        return substr($page, $start, (int) strpos($page, '>', $start) - $start + 1);
    }

    #[Test]
    public function the_key_field_reads_left_to_right_also_on_a_right_to_left_page(): void
    {
        // A key is base64 text, so it reads left to right whatever the page's direction. Arabic is the case
        // that matters, as the page itself is right to left there.
        config(['trusted_attestation.manifest.supported_locales' => ['en', 'ar']]);
        $this->store->all = [$this->relationship('employee', null)];

        $arabic = $this->withUnencryptedCookie('ata_locale', 'ar')->page();
        self::assertStringContainsString('<html lang="ar" dir="rtl">', $arabic);

        self::assertStringContainsString('dir="ltr"', self::keyInput($arabic));
        self::assertStringContainsString('dir="ltr"', self::keyInput($this->withUnencryptedCookie('ata_locale', 'en')->page()));
    }

    #[Test]
    public function the_expiry_is_printed_as_the_api_writes_it(): void
    {
        $this->store->all = [$this->relationship('employee', null, '2030-06-01T12:00:00.250000Z')];

        self::assertStringContainsString('2030-06-01T12:00:00.25Z', $this->page());
    }
}
