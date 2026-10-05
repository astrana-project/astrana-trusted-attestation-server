<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Http\Middleware\PreventRequestForgeryUnlessSignedOut;
use App\Services\MemberIdentityResolver;
use App\Services\Oidc\OidcClient;
use Firebase\JWT\JWT;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The OIDC login controller's own decisions, isolated from a live provider.
 *
 * The cryptographic checks belong to OidcClient and are pinned in IdTokenVerificationTest; the redirect
 * safety belongs to SafeReturnPath and is pinned there. What is left here is the controller's orchestration
 * -- what it stashes in the session before sending the member to the IdP, which callbacks it refuses and
 * where each refusal lands, and that nothing reaches the session until every check has passed. A regression
 * in any of those weakens login while breaking no happy path, so it would pass a black-box smoke test.
 *
 * Driven with a real session (an array-backed Store, exactly as ApiControllerTest builds one) and a real
 * OidcClient whose provider is scripted through Http::fake. The token endpoint returns a genuinely signed
 * ID token, minted with the same library the client verifies it with, so the success path is a real
 * verification rather than a stubbed one.
 *
 * A Feature test rather than a Unit one because it leans on Laravel's redirect()/config()/url() helpers and
 * fakes the HTTP client and cache -- all facades that need the container. It reaches no network.
 */
final class AuthControllerTest extends TestCase
{
    private const ISSUER = 'https://idp.example';

    private const CLIENT_ID = 'ata-client';

    private const APP_URL = 'https://ata.example';

    private const KID = 'test-key-1';

    private string $privateKey;

    /** @var list<array<string, string>> */
    private array $publishedKeys = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config([
            'app.url' => self::APP_URL,
            'trusted_attestation.iam.protocol' => 'oidc',
        ]);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $exported);
        $this->privateKey = $exported;

        $details = openssl_pkey_get_details($key);
        $this->publishedKeys = [[
            'kty' => 'RSA',
            'kid' => self::KID,
            'alg' => 'RS256',
            'use' => 'sig',
            'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
            'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
        ]];
    }

    /**
     * Scripts the provider for one test. Called from the test body rather than setUp, because Laravel
     * accumulates Http stubs and the earliest match wins -- a per-test discovery document could not
     * override one registered in setUp, so each test owns its own from the start.
     *
     * @param  array<string, mixed>  $extraDiscovery  merged into (and able to override) the base document
     * @param  array<string, mixed>  $stubs  extra url-pattern stubs, e.g. the token endpoint's answer
     */
    private function fakeIdp(array $extraDiscovery = [], array $stubs = []): void
    {
        Http::fake(array_merge([
            '*/.well-known/openid-configuration' => Http::response(array_merge([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER.'/authorize',
                'token_endpoint' => self::ISSUER.'/token',
                'jwks_uri' => self::ISSUER.'/jwks',
            ], $extraDiscovery)),
            '*/jwks' => fn () => Http::response(['keys' => $this->publishedKeys]),
        ], $stubs));
    }

    private function controller(): AuthController
    {
        // The controller resolves OidcClient lazily from the container (only on the OIDC paths), so the
        // scripted client is bound there rather than passed in -- app(OidcClient::class) inside the
        // controller then returns exactly this instance.
        $this->app->instance(OidcClient::class, new OidcClient(self::ISSUER, self::CLIENT_ID, 'secret', false, null));

        return new AuthController;
    }

    private function newSession(): Store
    {
        return new Store('ata_session', new ArraySessionHandler(60));
    }

    /** @param array<string, string> $query */
    private function request(Store $session, array $query = []): Request
    {
        $request = Request::create('/auth/callback', 'GET', $query);
        $request->setLaravelSession($session);

        return $request;
    }

    /**
     * The path-and-query of a redirect. redirect() to a local path renders an absolute URL off the
     * current request host when the controller is driven directly, so the origin is not what these
     * assertions are about -- the destination path is.
     */
    private function pathOf(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /** @param array<string, mixed> $claims */
    private function idToken(array $claims): string
    {
        $now = time();

        return JWT::encode(array_merge([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'member-subject',
            'iat' => $now,
            'exp' => $now + 300,
        ], $claims), $this->privateKey, 'RS256', self::KID);
    }

    // -- login ---------------------------------------------------------------------------------------

    #[Test]
    public function login_stashes_state_nonce_and_verifier_then_sends_the_member_to_the_idp(): void
    {
        // The three secrets the callback will demand back have to be in the session before the redirect,
        // and the state carried to the IdP has to be the same one stored -- that pairing is what ties the
        // eventual callback to this browser.
        $this->fakeIdp();

        $session = $this->newSession();
        $response = $this->controller()->login($this->request($session, ['next' => '/me']));

        $target = $response->getTargetUrl();
        self::assertStringStartsWith(self::ISSUER.'/authorize?', $target);

        parse_str((string) parse_url($target, PHP_URL_QUERY), $params);
        self::assertNotEmpty($session->get('trusted_attestation.oidc.state'));
        self::assertNotEmpty($session->get('trusted_attestation.oidc.nonce'));
        self::assertNotEmpty($session->get('trusted_attestation.oidc.code_verifier'));
        self::assertSame($session->get('trusted_attestation.oidc.state'), $params['state']);
        self::assertSame($session->get('trusted_attestation.oidc.nonce'), $params['nonce']);
    }

    #[Test]
    public function login_remembers_only_a_safe_local_next(): void
    {
        // The stored destination is what the callback redirects to on success, so an off-site next must
        // not survive to become an open redirect after authentication.
        $this->fakeIdp();

        $session = $this->newSession();
        $this->controller()->login($this->request($session, ['next' => 'https://evil.example/steal']));

        self::assertSame('/me', $session->get('trusted_attestation.oidc.intended'));
    }

    #[Test]
    public function login_under_saml_hands_off_to_the_saml_entry_point(): void
    {
        // A SAML deployment starts its flow at a different route; the OIDC entry point delegates rather
        // than trying to build an authorization URL it has no discovery document for.
        config(['trusted_attestation.iam.protocol' => 'saml']);

        $response = $this->controller()->login($this->request($this->newSession(), ['next' => '/me']));

        self::assertStringStartsWith('/auth/saml/login?next=', $this->pathOf($response->getTargetUrl()));
    }

    // -- callback ------------------------------------------------------------------------------------

    #[Test]
    public function a_callback_whose_state_does_not_match_the_session_is_refused(): void
    {
        // The CSRF tie. A callback arriving with the wrong state, or none, is not this member's login,
        // whatever code it carries. It lands on the same failed sign-in marker as every other refusal, as
        // in the other two implementations.
        $session = $this->newSession();
        $session->put('trusted_attestation.oidc.state', 'the-real-state');

        $response = $this->controller()->callback($this->request($session, ['state' => 'a-forged-state', 'code' => 'x']));

        self::assertSame('/me?error=login', $this->pathOf($response->getTargetUrl()));
        // Nothing was established.
        self::assertNull($session->get(MemberIdentityResolver::SESSION_KEY));
    }

    #[Test]
    public function a_callback_the_idp_marked_as_an_error_lands_on_login_failed(): void
    {
        // The IdP can bounce the member back with ?error=access_denied and no code at all; that is a
        // failed login, not a state mismatch.
        $session = $this->newSession();
        $session->put('trusted_attestation.oidc.state', 'the-state');

        $response = $this->controller()->callback($this->request($session, ['error' => 'access_denied']));

        self::assertSame('/me?error=login', $this->pathOf($response->getTargetUrl()));
    }

    #[Test]
    public function a_callback_with_matching_state_but_no_code_is_a_failed_login(): void
    {
        // State proves the browser, but a callback with no authorization code has nothing to exchange.
        $session = $this->newSession();
        $session->put('trusted_attestation.oidc.state', 'the-state');
        $session->put('trusted_attestation.oidc.nonce', 'the-nonce');
        $session->put('trusted_attestation.oidc.code_verifier', 'the-verifier');

        $response = $this->controller()->callback($this->request($session, ['state' => 'the-state']));

        self::assertSame('/me?error=login', $this->pathOf($response->getTargetUrl()));
    }

    #[Test]
    public function a_token_exchange_the_provider_refuses_is_a_failed_login(): void
    {
        // The provider rejects the code (wrong secret, replayed code): the exception is caught and turned
        // into a failed-login redirect, and nothing reaches the session.
        $this->fakeIdp(stubs: ['*/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $session = $this->newSession();
        $session->put('trusted_attestation.oidc.state', 'the-state');
        $session->put('trusted_attestation.oidc.nonce', 'the-nonce');
        $session->put('trusted_attestation.oidc.code_verifier', 'the-verifier');

        $response = $this->controller()->callback($this->request($session, ['state' => 'the-state', 'code' => 'the-code']));

        self::assertSame('/me?error=login', $this->pathOf($response->getTargetUrl()));
        self::assertNull($session->get(MemberIdentityResolver::SESSION_KEY));
    }

    #[Test]
    public function a_token_whose_nonce_does_not_match_the_session_is_a_failed_login(): void
    {
        // A correctly signed token that answers a different session's nonce fails verification, and that
        // failure is caught and turned into a failed login rather than establishing a session.
        $this->fakeIdp(
            extraDiscovery: ['userinfo_endpoint' => self::ISSUER.'/userinfo'],
            stubs: [
                '*/token' => Http::response(['id_token' => $this->idToken(['nonce' => 'a-different-nonce'])]),
            ],
        );

        $session = $this->newSession();
        $session->put('trusted_attestation.oidc.state', 'the-state');
        $session->put('trusted_attestation.oidc.nonce', 'the-nonce');
        $session->put('trusted_attestation.oidc.code_verifier', 'the-verifier');

        $response = $this->controller()->callback($this->request($session, ['state' => 'the-state', 'code' => 'the-code']));

        self::assertSame('/me?error=login', $this->pathOf($response->getTargetUrl()));
        self::assertNull($session->get(MemberIdentityResolver::SESSION_KEY));
    }

    /**
     * Tokens that verify but name no usable subject under the configured claim.
     *
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function tokensWithNoUsableSubject(): iterable
    {
        yield 'the configured claim is missing' => ['email', ['sub' => 'opaque']];
        yield 'the configured claim is empty' => ['sub', ['sub' => '']];
        yield 'the configured claim is whitespace' => ['sub', ['sub' => "  \t "]];
        yield 'the configured claim is not a scalar' => ['sub', ['sub' => ['a', 'b']]];
    }

    #[Test]
    #[DataProvider('tokensWithNoUsableSubject')]
    public function a_verified_token_with_no_usable_subject_is_a_failed_login_and_establishes_nothing(string $subjectClaim, array $claims): void
    {
        // A session keyed by nobody would send /me back to sign in, which would come straight back to
        // the same session, without end. Refused at the callback instead, landing where every failed
        // sign-in lands, with nothing in the session.
        config(['trusted_attestation.iam.subject_claim' => $subjectClaim]);

        $nonce = 'the-nonce';
        $this->fakeIdp(stubs: [
            '*/token' => Http::response(['id_token' => $this->idToken(['nonce' => $nonce] + $claims)]),
        ]);

        $session = $this->newSession();
        $session->put('trusted_attestation.oidc.state', 'the-state');
        $session->put('trusted_attestation.oidc.nonce', $nonce);
        $session->put('trusted_attestation.oidc.code_verifier', 'the-verifier');

        $response = $this->controller()->callback($this->request($session, ['state' => 'the-state', 'code' => 'the-code']));

        self::assertSame('/me?error=login', $this->pathOf($response->getTargetUrl()));
        self::assertNull($session->get(MemberIdentityResolver::SESSION_KEY));
        self::assertNull($session->get('trusted_attestation.oidc.id_token'));
    }

    #[Test]
    public function a_verified_login_establishes_the_session_and_returns_to_the_intended_path(): void
    {
        // The happy path end to end: a real signed ID token is exchanged and verified, the subject, name
        // and locale land in the session, the ID token is kept for logout, and the member is returned to
        // the safe destination they set out for.
        $nonce = 'the-nonce';
        $this->fakeIdp(
            extraDiscovery: ['userinfo_endpoint' => self::ISSUER.'/userinfo'],
            stubs: [
                '*/token' => Http::response([
                    'id_token' => $this->idToken([
                        'nonce' => $nonce,
                        'name' => 'Alice Anderson',
                        'email' => 'alice@example.org',
                    ]),
                    'access_token' => 'the-access-token',
                ]),
                // UserInfo carries the locale and a directory attribute, and also tries to overwrite sub;
                // the verified ID token must win on any overlap.
                '*/userinfo' => Http::response([
                    'locale' => 'fr-CA',
                    'department' => 'Finance',
                    'sub' => 'userinfo-should-not-win',
                ]),
            ],
        );

        $session = $this->newSession();
        $session->put('trusted_attestation.oidc.state', 'the-state');
        $session->put('trusted_attestation.oidc.nonce', $nonce);
        $session->put('trusted_attestation.oidc.code_verifier', 'the-verifier');
        $session->put('trusted_attestation.oidc.intended', '/me?welcome=1');

        $response = $this->controller()->callback($this->request($session, ['state' => 'the-state', 'code' => 'the-code']));

        self::assertSame('/me?welcome=1', $this->pathOf($response->getTargetUrl()));

        // Exactly the subject, the display name and the locale, the last merged in from UserInfo, with the
        // verified ID token winning where the two disagree. The email address, the directory attribute and
        // the token's own iss, aud, nonce and timestamps are not kept: storing the whole claim set again
        // fails here.
        self::assertEquals(
            ['sub' => 'member-subject', 'name' => 'Alice Anderson', 'locale' => 'fr-CA'],
            $session->get(MemberIdentityResolver::SESSION_KEY),
        );
        // The ID token is kept only so logout can tell the IdP which session is ending.
        self::assertNotEmpty($session->get('trusted_attestation.oidc.id_token'));
    }

    #[Test]
    public function a_verified_login_keeps_the_configured_subject_claim_and_nothing_beyond_it(): void
    {
        // An organisation on Google or Microsoft grants to email addresses, so the subject claim is
        // email. The session then keeps the email under that name, and drops the opaque sub it no longer
        // needs.
        config(['trusted_attestation.iam.subject_claim' => 'email']);

        $nonce = 'the-nonce';
        $this->fakeIdp(stubs: [
            '*/token' => Http::response([
                'id_token' => $this->idToken(['nonce' => $nonce, 'email' => 'alice@example.org']),
            ]),
        ]);

        $session = $this->newSession();
        $session->put('trusted_attestation.oidc.state', 'the-state');
        $session->put('trusted_attestation.oidc.nonce', $nonce);
        $session->put('trusted_attestation.oidc.code_verifier', 'the-verifier');

        $this->controller()->callback($this->request($session, ['state' => 'the-state', 'code' => 'the-code']));

        self::assertSame(['email' => 'alice@example.org'], $session->get(MemberIdentityResolver::SESSION_KEY));
    }

    // -- signout -------------------------------------------------------------------------------------

    #[Test]
    public function signout_sends_the_member_to_the_idp_end_session_and_clears_the_session(): void
    {
        // Signing out has to reach the IdP, or the next visit is silently signed straight back in. The
        // local claims are cleared regardless.
        $this->fakeIdp(extraDiscovery: ['end_session_endpoint' => self::ISSUER.'/logout']);

        $session = $this->newSession();
        $session->put(MemberIdentityResolver::SESSION_KEY, ['sub' => 'member-subject']);
        $session->put('trusted_attestation.oidc.id_token', 'the.id.token');

        $response = $this->controller()->signout($this->request($session));

        self::assertStringStartsWith(self::ISSUER.'/logout?', $response->getTargetUrl());
        parse_str((string) parse_url($response->getTargetUrl(), PHP_URL_QUERY), $params);
        self::assertSame('the.id.token', $params['id_token_hint']);
        self::assertNull($session->get(MemberIdentityResolver::SESSION_KEY));
    }

    #[Test]
    public function signout_with_no_end_session_endpoint_lands_on_the_local_signed_out_page(): void
    {
        // A provider that offers no RP-initiated logout leaves the local session ended and the member on
        // /signed-out, which is all this app can honestly do.
        $this->fakeIdp();

        $session = $this->newSession();
        $session->put(MemberIdentityResolver::SESSION_KEY, ['sub' => 'member-subject']);

        $response = $this->controller()->signout($this->request($session));

        self::assertSame('/signed-out', $this->pathOf($response->getTargetUrl()));
        self::assertNull($session->get(MemberIdentityResolver::SESSION_KEY));
    }

    #[Test]
    public function signout_lands_on_the_local_signed_out_page_when_the_discovery_document_cannot_be_read(): void
    {
        // The local session is ended either way, and that is the part this service answers for. A
        // provider that is unreachable at the moment of sign-out must not turn that into a 500 after the
        // session is already gone. The reason goes to the log and the member sees /signed-out.
        Http::fake(['*/.well-known/openid-configuration' => Http::response('', 503)]);

        $session = $this->newSession();
        $session->put(MemberIdentityResolver::SESSION_KEY, ['sub' => 'member-subject']);
        $session->put('trusted_attestation.oidc.id_token', 'the.id.token');

        $response = $this->controller()->signout($this->request($session));

        self::assertSame('/signed-out', $this->pathOf($response->getTargetUrl()));
        self::assertNull($session->get(MemberIdentityResolver::SESSION_KEY));
        self::assertNull($session->get('trusted_attestation.oidc.id_token'));
    }

    #[Test]
    public function signout_with_no_live_session_lands_on_signed_out_without_reaching_the_idp(): void
    {
        // An expired or never-started session has nothing to end, here or at the identity provider, so the
        // answer is the redirect to /signed-out, on both protocols, as on the other two implementations.
        $this->fakeIdp(extraDiscovery: ['end_session_endpoint' => self::ISSUER.'/logout']);

        foreach (['oidc', 'saml'] as $protocol) {
            config(['trusted_attestation.iam.protocol' => $protocol]);

            $response = $this->controller()->signout($this->request($this->newSession()));

            self::assertSame('/signed-out', $this->pathOf($response->getTargetUrl()), $protocol);
        }
    }

    #[Test]
    public function a_sign_out_with_no_live_session_is_exempt_from_the_anti_forgery_check(): void
    {
        // The testing kernel skips the token check outright, so the exemption is read from the
        // middleware's own decision. A sign-out with no member is exempt. One with a signed-in member is
        // not, so a cross-site page cannot sign a member out, and neither is any other POST.
        $middleware = $this->app->make(PreventRequestForgeryUnlessSignedOut::class);
        $exempt = fn (Request $request): bool => (new \ReflectionMethod($middleware, 'inExceptArray'))
            ->invoke($middleware, $request);
        $post = function (string $path, Store $session): Request {
            $request = Request::create($path, 'POST');
            $request->setLaravelSession($session);

            return $request;
        };

        $signedIn = $this->newSession();
        $signedIn->put(MemberIdentityResolver::SESSION_KEY, ['sub' => 'member-subject']);

        self::assertTrue($exempt($post('/signout', $this->newSession())));
        self::assertFalse($exempt($post('/signout', $signedIn)));
        self::assertFalse($exempt($post('/somewhere-else', $this->newSession())));
    }

    #[Test]
    public function the_anti_forgery_check_in_the_web_group_is_the_sign_out_aware_one(): void
    {
        $web = $this->app->make(Kernel::class)->getMiddlewareGroups()['web'];

        self::assertContains(PreventRequestForgeryUnlessSignedOut::class, $web);
        self::assertNotContains(PreventRequestForgery::class, $web);
    }

    #[Test]
    public function signout_under_saml_ends_the_session_on_the_post_itself(): void
    {
        // The member's own sign-out happens on this CSRF-protected POST for SAML too, not by handing off to
        // a GET a cross-site page could trigger. With no IdP metadata configured here the IdP cannot be
        // told, so the local session is ended and the member lands on /signed-out.
        config(['trusted_attestation.iam.protocol' => 'saml']);

        $session = $this->newSession();
        $session->put(MemberIdentityResolver::SESSION_KEY, ['sub' => 'member-subject']);

        $response = $this->controller()->signout($this->request($session));

        self::assertSame('/signed-out', $this->pathOf($response->getTargetUrl()));
        self::assertNull($session->get(MemberIdentityResolver::SESSION_KEY));
    }

    #[Test]
    public function under_saml_the_entry_points_never_resolve_the_oidc_client(): void
    {
        // OidcClient is resolved only where it is used. Injected into the constructor, the container would
        // build it for every request to this controller, including /auth/login and /signout, whose SAML
        // branch never uses it. A SAML deployment leaves the (there-unused) OIDC issuer unset, OidcClient
        // throws without one, and the whole login/logout surface would answer 500 before it could redirect
        // to the SAML flow.
        //
        // Bind an OidcClient that explodes if resolved, and prove each entry point reaches its SAML
        // decision without touching it. /auth/callback is not part of a SAML login, so it refuses outright.
        config(['trusted_attestation.iam.protocol' => 'saml']);
        $this->app->bind(OidcClient::class, static function (): OidcClient {
            throw new \RuntimeException('OidcClient must not be resolved on a SAML deployment.');
        });

        $controller = new AuthController;

        $login = $controller->login($this->request($this->newSession(), ['next' => '/me']));
        self::assertStringStartsWith('/auth/saml/login?next=', $this->pathOf($login->getTargetUrl()));

        $signedIn = $this->newSession();
        $signedIn->put(MemberIdentityResolver::SESSION_KEY, ['sub' => 'member-subject']);
        $signout = $controller->signout($this->request($signedIn));
        self::assertSame('/signed-out', $this->pathOf($signout->getTargetUrl()));

        $callback = $controller->callback($this->request($this->newSession(), ['state' => 'x', 'code' => 'y']));
        self::assertSame('/me?error=login', $this->pathOf($callback->getTargetUrl()));
    }
}
