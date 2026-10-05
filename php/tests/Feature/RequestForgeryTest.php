<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberRelationship;
use App\Services\AuditLog;
use App\Services\MemberIdentityResolver;
use App\Services\RelationshipStore;
use App\Services\TransactionRunner;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ImmediateTransactionRunner;
use Tests\Support\ProgrammableRelationshipStore;
use Tests\Support\RecordingAuditLog;
use Tests\TestCase;

/**
 * The anti-forgery token on the two state-changing POSTs a signed-in member's browser sends: the self-revoke
 * and the sign-out.
 *
 * Both are plain POSTs a form on another site, a sibling subdomain included, could submit with the member's
 * cookie attached, so a signed-in member's request has to carry the session's token, in the X-CSRF-TOKEN
 * header or the form's _token field. A missing or wrong token answers HTTP 403 - Forbidden with no body
 * and no Content-Type, as on the other two implementations. A request with no member signed in is not
 * asked for a token: the revoke answers 401 and the sign-out its redirect, as before.
 *
 * The testing kernel skips the token check outright, so the application is told it is not under test
 * here, and every request goes through the whole middleware stack. The store is a programmable fake, so
 * no database is touched.
 */
final class RequestForgeryTest extends TestCase
{
    private const REVOKE_PATHS = ['/api/v1/me/relationships/employee/revoke', '/api/me/relationships/employee/revoke'];

    private const SIGNED_IN = [MemberIdentityResolver::SESSION_KEY => ['sub' => 'alice']];

    private const TOKEN = 'the-session-token';

    private ProgrammableRelationshipStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'production';

        $this->store = new ProgrammableRelationshipStore;
        $this->app->instance(RelationshipStore::class, $this->store);
        $this->app->instance(AuditLog::class, new RecordingAuditLog);
        $this->app->instance(TransactionRunner::class, new ImmediateTransactionRunner);
    }

    private function grantedRelationship(): MemberRelationship
    {
        $row = new MemberRelationship;
        $row->id = 1;
        $row->iam_subject_id = 'alice';
        $row->relationship_type = 'employee';
        $row->relationship_subtype = null;
        $row->expires_at = null;
        $row->revoked_at = null;

        return $row;
    }

    /** @param array<string, string> $headers */
    private function signedInPost(string $path, array $headers = []): TestResponse
    {
        return $this->withSession(self::SIGNED_IN + ['_token' => self::TOKEN])->post($path, [], $headers);
    }

    private static function assertBareForbidden(TestResponse $response): void
    {
        $response->assertForbidden();
        self::assertSame('', $response->getContent());
        $response->assertHeaderMissing('Content-Type');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function forgedRequests(): iterable
    {
        foreach (self::REVOKE_PATHS as $path) {
            yield 'revoke at '.$path.' with no token' => [$path, []];
            yield 'revoke at '.$path.' with the wrong token' => [$path, ['X-CSRF-TOKEN' => 'not-the-token']];
        }

        yield 'sign-out with no token' => ['/signout', []];
        yield 'sign-out with the wrong token' => ['/signout', ['X-CSRF-TOKEN' => 'not-the-token']];

        // Laravel would otherwise accept a request the browser marks as same-origin without a token. The
        // other two implementations ask for the token whatever the request says about itself.
        yield 'revoke marked same-origin with no token' => [self::REVOKE_PATHS[0], ['Sec-Fetch-Site' => 'same-origin']];
        yield 'sign-out marked same-origin with no token' => ['/signout', ['Sec-Fetch-Site' => 'same-origin']];
    }

    /** @param array<string, string> $headers */
    #[Test]
    #[DataProvider('forgedRequests')]
    public function a_signed_in_members_post_without_the_right_token_is_a_bare_403(string $path, array $headers): void
    {
        $this->store->own = $this->grantedRelationship();

        self::assertBareForbidden($this->signedInPost($path, $headers));
        self::assertNotNull($this->store->own, 'the relationship was left alone');
    }

    #[Test]
    public function a_forged_sign_out_leaves_the_member_signed_in(): void
    {
        $this->signedInPost('/signout')->assertSessionHas(MemberIdentityResolver::SESSION_KEY, ['sub' => 'alice']);
    }

    #[Test]
    public function a_revoke_carrying_the_token_in_the_header_reaches_the_controller(): void
    {
        $this->store->own = $this->grantedRelationship();

        foreach (self::REVOKE_PATHS as $path) {
            $this->signedInPost($path, ['X-CSRF-TOKEN' => self::TOKEN])->assertNoContent();
        }
    }

    #[Test]
    public function a_revoke_with_no_member_signed_in_is_401_before_any_token_is_asked_for(): void
    {
        foreach (self::REVOKE_PATHS as $path) {
            $response = $this->post($path);

            $response->assertUnauthorized();
            self::assertSame('', $response->getContent());
        }
    }

    #[Test]
    public function a_sign_out_with_no_member_signed_in_redirects_whatever_the_token_says(): void
    {
        $this->post('/signout')->assertRedirect('/signed-out');
        $this->post('/signout', [], ['X-CSRF-TOKEN' => 'not-the-token'])->assertRedirect('/signed-out');
    }

    #[Test]
    public function the_key_operations_still_need_no_token(): void
    {
        // PUT and DELETE are JSON requests a browser preflights across origins, so they are unchanged.
        $this->store->own = $this->grantedRelationship();
        $this->store->deleteCount = 1;

        $this->withSession(self::SIGNED_IN + ['_token' => self::TOKEN])
            ->delete('/api/v1/me/relationships/employee/key')
            ->assertNoContent();
    }

    #[Test]
    public function the_anonymous_lookup_still_needs_no_token(): void
    {
        $this->postJson('/api/v1/attest', ['public_key' => base64_encode(str_repeat('k', 32))])->assertOk();
    }
}
