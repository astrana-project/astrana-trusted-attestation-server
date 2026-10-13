<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\LimitRequestBody;
use App\Models\MemberRelationship;
use App\Services\AuditLog;
use App\Services\MemberIdentityResolver;
use App\Services\RelationshipStore;
use App\Services\TransactionRunner;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ImmediateTransactionRunner;
use Tests\Support\ProgrammableRelationshipStore;
use Tests\Support\RecordingAuditLog;
use Tests\TestCase;

/**
 * The anti-forgery token on every state-changing request a signed-in member's browser sends: setting or
 * clearing a key, removing a relationship, the self-revoke and the sign-out.
 *
 * A page on another site, a sibling subdomain included, counts as the same site for the session cookie, so
 * the cookie alone does not show the member asked for the change. A signed-in member's request has to carry
 * the session's token, on the API in the X-CSRF-TOKEN header only, and on the sign-out in the header or the
 * form's _token field. A missing or wrong token answers HTTP 403 - Forbidden with no body and no
 * Content-Type, as on the other two implementations. A request with no member signed in is not asked for a
 * token: the API answers 401 and the sign-out its redirect.
 *
 * The testing kernel skips the token check outright, so the application is told it is not under test
 * here, and every request goes through the whole middleware stack. The store is a programmable fake, so
 * no database is touched.
 */
final class RequestForgeryTest extends TestCase
{
    private const REVOKE_PATHS = ['/api/v1/me/relationships/employee/revoke', '/api/me/relationships/employee/revoke'];

    private const KEY_PATHS = ['/api/v1/me/relationships/employee/key', '/api/me/relationships/employee/key'];

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

    /**
     * A body for the key PUT that would register a key, so only the token can stop it.
     *
     * @param  array<string, string>  $extra
     */
    private static function keyBody(array $extra = []): string
    {
        return (string) json_encode(['public_key' => base64_encode(str_repeat('k', 32))] + $extra);
    }

    /** @param array<string, string> $headers */
    private function signedIn(string $method, string $path, array $headers = [], ?string $body = null): TestResponse
    {
        return $this->withSession(self::SIGNED_IN + ['_token' => self::TOKEN])->send($method, $path, $headers, $body);
    }

    /**
     * The request as a browser sends it, with a JSON body on the key PUT unless another is given.
     *
     * @param  array<string, string>  $headers
     */
    private function send(string $method, string $path, array $headers = [], ?string $body = null): TestResponse
    {
        $body ??= $method === 'PUT' ? self::keyBody() : null;
        $server = $this->transformHeadersToServerVars($headers + ($body === null ? [] : ['Content-Type' => 'application/json']));

        return $this->call($method, $path, [], [], [], $server, $body);
    }

    private static function assertBareForbidden(TestResponse $response): void
    {
        $response->assertForbidden();
        self::assertSame('', $response->getContent());
        $response->assertHeaderMissing('Content-Type');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    /** @return iterable<int, array{string, string}> Every state-changing API request, at both prefixes. */
    private static function memberChanges(): iterable
    {
        foreach (self::KEY_PATHS as $path) {
            yield ['PUT', $path];
            yield ['DELETE', $path];
        }

        foreach (self::REVOKE_PATHS as $path) {
            yield ['POST', $path];
        }
    }

    /** @return iterable<string, array{string, string, array<string, string>}> */
    public static function forgedRequests(): iterable
    {
        foreach (self::memberChanges() as [$method, $path]) {
            yield $method.' '.$path.' with no token' => [$method, $path, []];
            yield $method.' '.$path.' with the wrong token' => [$method, $path, ['X-CSRF-TOKEN' => 'not-the-token']];
        }

        yield 'sign-out with no token' => ['POST', '/signout', []];
        yield 'sign-out with the wrong token' => ['POST', '/signout', ['X-CSRF-TOKEN' => 'not-the-token']];

        // Laravel would otherwise accept a request the browser marks as same-origin without a token. The
        // other two implementations ask for the token whatever the request says about itself.
        yield 'key PUT marked same-origin with no token' => ['PUT', self::KEY_PATHS[0], ['Sec-Fetch-Site' => 'same-origin']];
        yield 'revoke marked same-origin with no token' => ['POST', self::REVOKE_PATHS[0], ['Sec-Fetch-Site' => 'same-origin']];
        yield 'sign-out marked same-origin with no token' => ['POST', '/signout', ['Sec-Fetch-Site' => 'same-origin']];
    }

    /** @param array<string, string> $headers */
    #[Test]
    #[DataProvider('forgedRequests')]
    public function a_signed_in_members_change_without_the_right_token_is_a_bare_403(string $method, string $path, array $headers): void
    {
        $this->store->own = $this->grantedRelationship();
        $this->store->deleteCount = 1;

        self::assertBareForbidden($this->signedIn($method, $path, $headers));
        self::assertNotNull($this->store->own, 'the relationship was left alone');
    }

    #[Test]
    public function the_api_reads_the_token_from_the_header_only(): void
    {
        // The contract names the header, and the other two implementations look nowhere else on the API, so
        // a _token field in the body is not a token here either.
        $this->store->own = $this->grantedRelationship();

        self::assertBareForbidden($this->signedIn('PUT', self::KEY_PATHS[0], [], self::keyBody(['_token' => self::TOKEN])));
        self::assertBareForbidden($this->withSession(self::SIGNED_IN + ['_token' => self::TOKEN])
            ->post(self::REVOKE_PATHS[0], ['_token' => self::TOKEN]));
    }

    #[Test]
    public function a_forged_sign_out_leaves_the_member_signed_in(): void
    {
        $this->signedIn('POST', '/signout')->assertSessionHas(MemberIdentityResolver::SESSION_KEY, ['sub' => 'alice']);
    }

    #[Test]
    public function a_change_carrying_the_token_in_the_header_reaches_the_controller(): void
    {
        $this->store->own = $this->grantedRelationship();
        $this->store->deleteCount = 1;
        $expected = ['PUT' => 200, 'DELETE' => 204, 'POST' => 204];

        foreach (self::memberChanges() as [$method, $path]) {
            $this->signedIn($method, $path, ['X-CSRF-TOKEN' => self::TOKEN])->assertStatus($expected[$method]);
        }
    }

    #[Test]
    public function a_change_with_no_member_signed_in_is_401_before_any_token_is_asked_for(): void
    {
        foreach (self::memberChanges() as [$method, $path]) {
            foreach ([[], ['X-CSRF-TOKEN' => 'not-the-token']] as $headers) {
                $response = $this->send($method, $path, $headers);

                $response->assertUnauthorized();
                self::assertSame('', $response->getContent());
            }
        }
    }

    #[Test]
    public function the_session_is_checked_first_then_the_token_then_the_body_cap(): void
    {
        // A forged request is refused whatever its size, and only a request the member really sent is measured.
        $oversized = self::keyBody(['padding' => str_repeat('A', LimitRequestBody::MAX_BYTES)]);

        $this->send('PUT', self::KEY_PATHS[0], [], $oversized)->assertUnauthorized();
        self::assertBareForbidden($this->signedIn('PUT', self::KEY_PATHS[0], [], $oversized));
        $this->signedIn('PUT', self::KEY_PATHS[0], ['X-CSRF-TOKEN' => self::TOKEN], $oversized)
            ->assertStatus(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
    }

    #[Test]
    public function a_sign_out_with_no_member_signed_in_redirects_whatever_the_token_says(): void
    {
        $this->post('/signout')->assertRedirect('/signed-out');
        $this->post('/signout', [], ['X-CSRF-TOKEN' => 'not-the-token'])->assertRedirect('/signed-out');
    }

    #[Test]
    public function the_anonymous_lookup_needs_no_token_signed_in_or_not(): void
    {
        $key = ['public_key' => base64_encode(str_repeat('k', 32))];

        $this->postJson('/api/v1/attest', $key)->assertOk();
        $this->withSession(self::SIGNED_IN + ['_token' => self::TOKEN])->postJson('/api/v1/attest', $key)->assertOk();
    }
}
