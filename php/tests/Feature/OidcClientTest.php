<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ConfigurationException;
use App\Exceptions\IdentityProviderException;
use App\Services\Oidc\OidcClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The OIDC relying party's exchanges with the provider, everything except the two halves already pinned
 * elsewhere (the authorization URL's PKCE proof in OidcAuthorizationUrlTest, ID-token verification in
 * IdTokenVerificationTest).
 *
 * These are the three round-trips a login and logout actually make: the code-for-tokens exchange, the
 * UserInfo fetch that some providers need for custom claims, and the end-session URL that makes "sign out"
 * reach the IdP. Each is driven against a scripted provider (Http::fake), so what is asserted is the
 * request this client sends and how it reads the answer -- not that a mock returned what it was told to.
 *
 * A Feature test rather than a Unit one because it fakes the HTTP client and the cache, both facades that
 * need the application container. It reaches no network and no live provider.
 */
final class OidcClientTest extends TestCase
{
    private const ISSUER = 'https://idp.example';

    private const CLIENT_ID = 'ata-client';

    private const SECRET = 'a-client-secret';

    private const REDIRECT = 'https://ata.example/auth/callback';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** @param array<string, mixed> $extraDiscovery */
    private function fakeProvider(array $extraDiscovery = [], mixed $tokenResponse = null, mixed $userInfoResponse = null): void
    {
        Http::fake([
            '*/.well-known/openid-configuration' => Http::response(array_merge([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER.'/authorize',
                'token_endpoint' => self::ISSUER.'/token',
                'jwks_uri' => self::ISSUER.'/jwks',
                'userinfo_endpoint' => self::ISSUER.'/userinfo',
            ], $extraDiscovery)),
            '*/token' => $tokenResponse ?? Http::response(['id_token' => 'the.id.token', 'access_token' => 'the-access-token']),
            '*/userinfo' => $userInfoResponse ?? Http::response(['relationship_type' => 'employee']),
        ]);
    }

    private function client(): OidcClient
    {
        return new OidcClient(self::ISSUER, self::CLIENT_ID, self::SECRET, false, null);
    }

    // -- exchangeCode --------------------------------------------------------------------------------

    #[Test]
    public function exchange_code_returns_the_providers_tokens(): void
    {
        $this->fakeProvider(tokenResponse: Http::response([
            'id_token' => 'the.id.token',
            'access_token' => 'the-access-token',
            'token_type' => 'Bearer',
        ]));

        $tokens = $this->client()->exchangeCode('the-auth-code', self::REDIRECT, 'the-verifier');

        self::assertSame('the.id.token', $tokens['id_token']);
        self::assertSame('the-access-token', $tokens['access_token']);
    }

    #[Test]
    public function exchange_code_authenticates_the_client_and_sends_the_pkce_verifier(): void
    {
        // The security-load-bearing part: a confidential client authenticates itself (HTTP Basic) even
        // though PKCE is in use, and it is the authorization-code grant carrying the verifier that proves
        // the caller started the flow. A regression dropping either would still let a login succeed.
        $this->fakeProvider();

        $this->client()->exchangeCode('the-auth-code', self::REDIRECT, 'the-verifier');

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/token')) {
                return false;
            }

            $expectedAuth = 'Basic '.base64_encode(self::CLIENT_ID.':'.self::SECRET);

            return $request['grant_type'] === 'authorization_code'
                && $request['code'] === 'the-auth-code'
                && $request['redirect_uri'] === self::REDIRECT
                && $request['code_verifier'] === 'the-verifier'
                && $request->header('Authorization') === [$expectedAuth];
        });
    }

    #[Test]
    public function exchange_code_rejected_by_the_provider_is_refused(): void
    {
        // A wrong client secret, a replayed code, a redirect_uri mismatch: the token endpoint answers
        // non-2xx, and that is not a login.
        $this->fakeProvider(tokenResponse: Http::response(['error' => 'invalid_grant'], 400));

        $this->expectException(IdentityProviderException::class);

        $this->client()->exchangeCode('the-auth-code', self::REDIRECT, 'the-verifier');
    }

    #[Test]
    public function exchange_code_with_no_id_token_in_the_answer_is_refused(): void
    {
        // A 200 that carries an access token but no ID token is an OAuth answer, not an OIDC one. Without
        // an ID token there is nothing to verify the member's identity against, so it is refused rather
        // than treated as a login.
        $this->fakeProvider(tokenResponse: Http::response(['access_token' => 'only-this'], 200));

        $this->expectException(IdentityProviderException::class);

        $this->client()->exchangeCode('the-auth-code', self::REDIRECT, 'the-verifier');
    }

    // -- userInfo ------------------------------------------------------------------------------------

    #[Test]
    public function user_info_returns_the_providers_claims_bearing_the_access_token(): void
    {
        // Some providers put custom claims -- relationship_type among them -- at UserInfo rather than in
        // the ID token, and the endpoint is bearer-authenticated with the access token from the exchange.
        $this->fakeProvider(userInfoResponse: Http::response(['relationship_type' => 'director', 'email' => 'a@b.example']));

        $claims = $this->client()->userInfo('the-access-token');

        self::assertSame('director', $claims['relationship_type']);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/userinfo')
            && $request->header('Authorization') === ['Bearer the-access-token']);
    }

    #[Test]
    public function user_info_is_empty_when_the_provider_offers_no_such_endpoint(): void
    {
        // A provider may publish no userinfo_endpoint at all; the caller merges UserInfo under the ID
        // token's claims, so an empty array is the right "nothing extra" rather than a failure.
        $this->fakeProvider(extraDiscovery: ['userinfo_endpoint' => '']);

        self::assertSame([], $this->client()->userInfo('the-access-token'));
    }

    #[Test]
    public function user_info_is_empty_when_the_endpoint_refuses(): void
    {
        // A rejected or expired access token gets a non-2xx from UserInfo. The ID token is already
        // verified proof of the login, so a UserInfo failure degrades to no extra claims rather than
        // failing the login.
        $this->fakeProvider(userInfoResponse: Http::response('', 401));

        self::assertSame([], $this->client()->userInfo('the-access-token'));
    }

    #[Test]
    public function user_info_is_empty_when_the_endpoint_answers_with_a_non_object(): void
    {
        // A 200 whose body is a JSON scalar or array rather than a claims object has no claims to merge.
        $this->fakeProvider(userInfoResponse: Http::response('"a string, not claims"', 200));

        self::assertSame([], $this->client()->userInfo('the-access-token'));
    }

    // -- endSessionUrl -------------------------------------------------------------------------------

    #[Test]
    public function end_session_url_carries_the_id_token_hint_and_the_post_logout_target(): void
    {
        $this->fakeProvider(extraDiscovery: ['end_session_endpoint' => self::ISSUER.'/logout']);

        $url = $this->client()->endSessionUrl('the.id.token', 'https://ata.example/signed-out');

        self::assertStringStartsWith(self::ISSUER.'/logout?', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
        self::assertSame('the.id.token', $params['id_token_hint']);
        self::assertSame('https://ata.example/signed-out', $params['post_logout_redirect_uri']);
        self::assertSame(self::CLIENT_ID, $params['client_id']);
    }

    #[Test]
    public function end_session_url_omits_the_hint_when_there_is_no_id_token(): void
    {
        // Logout can be reached without a stored ID token (an already-flushed session). The end-session
        // URL is still built, just without a hint the IdP cannot be given.
        $this->fakeProvider(extraDiscovery: ['end_session_endpoint' => self::ISSUER.'/logout']);

        $url = $this->client()->endSessionUrl(null, 'https://ata.example/signed-out');

        self::assertNotNull($url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
        self::assertArrayNotHasKey('id_token_hint', $params);
        self::assertSame('https://ata.example/signed-out', $params['post_logout_redirect_uri']);
    }

    #[Test]
    public function end_session_url_is_null_when_the_provider_publishes_no_end_session_endpoint(): void
    {
        // Most of the point of returning null: a provider with no RP-initiated logout endpoint leaves the
        // caller to end the local session and land the member on /signed-out itself.
        $this->fakeProvider();

        self::assertNull($this->client()->endSessionUrl('the.id.token', 'https://ata.example/signed-out'));
    }

    // -- constructor guards --------------------------------------------------------------------------

    #[Test]
    public function an_empty_issuer_is_refused_at_construction(): void
    {
        // No issuer means nothing to fetch discovery from or validate tokens against; the instance should
        // refuse to construct rather than fail obscurely at the first login.
        $this->expectException(ConfigurationException::class);

        new OidcClient('', self::CLIENT_ID, self::SECRET, false, null);
    }

    #[Test]
    public function an_empty_client_identifier_is_refused_at_construction_naming_the_setting(): void
    {
        // Required, like the issuer, so a deployment that never set it stops at start rather than signing
        // members in as a client the identity provider does not know.
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('TRUSTED_ATTESTATION_IAM_CLIENT_ID');

        new OidcClient(self::ISSUER, '', 'secret', true);
    }

    #[Test]
    public function the_client_identifier_has_no_default(): void
    {
        // Nothing in the test environment sets it, so this is what an operator who left it out gets.
        self::assertSame('', config('trusted_attestation.iam.client_id'));
    }

    #[Test]
    public function a_non_https_issuer_is_refused_when_https_metadata_is_required(): void
    {
        // The https requirement is only ever relaxed to talk to a local development IdP. With it on, an
        // http:// issuer is a misconfiguration refused at construction, not a downgrade to accept quietly.
        $this->expectException(ConfigurationException::class);

        new OidcClient('http://idp.example', self::CLIENT_ID, self::SECRET, true, null);
    }

    // -- jwks error handling (reached through verifyIdToken) -----------------------------------------

    #[Test]
    public function a_provider_that_publishes_no_jwks_uri_cannot_verify_a_token(): void
    {
        // Without a JWKS URI there are no keys to check a signature against, so verification cannot even
        // begin -- refused rather than treated as a token that happens to verify.
        $this->fakeProvider(extraDiscovery: ['jwks_uri' => '']);

        $this->expectException(IdentityProviderException::class);

        $this->client()->verifyIdToken('any.token.here', 'the-nonce');
    }

    #[Test]
    public function unreachable_signing_keys_are_refused(): void
    {
        // The keys endpoint answering non-2xx is an infrastructure failure, not a verified token; it must
        // not be swallowed into a silent accept.
        Http::fake([
            '*/.well-known/openid-configuration' => Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER.'/authorize',
                'token_endpoint' => self::ISSUER.'/token',
                'jwks_uri' => self::ISSUER.'/jwks',
            ]),
            '*/jwks' => Http::response('', 500),
        ]);

        $this->expectException(IdentityProviderException::class);

        $this->client()->verifyIdToken('any.token.here', 'the-nonce');
    }

    #[Test]
    public function signing_keys_that_are_not_a_jwk_set_are_refused(): void
    {
        // A 200 whose body has no "keys" array is not a usable key set. Reading it as one would hand the
        // JWT library nothing to verify against.
        Http::fake([
            '*/.well-known/openid-configuration' => Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER.'/authorize',
                'token_endpoint' => self::ISSUER.'/token',
                'jwks_uri' => self::ISSUER.'/jwks',
            ]),
            '*/jwks' => Http::response(['not' => 'a key set'], 200),
        ]);

        $this->expectException(IdentityProviderException::class);

        $this->client()->verifyIdToken('any.token.here', 'the-nonce');
    }
}
