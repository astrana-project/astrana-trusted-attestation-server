<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\IdentityProviderException;
use App\Services\Oidc\OidcClient;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What this implementation accepts as proof that a member logged in.
 *
 * The token checks decision record 23 in docs/adr requires of all three implementations, run against a scripted
 * issuer rather than a live one. Of the three implementations this is the one that needs it most: .NET
 * and Java validate ID tokens with their framework's own battle-tested code, and this one does it by hand.
 *
 * Every case here is a token that is well-formed and correctly signed but wrong in exactly one way. A
 * check that is missing does not announce itself. The sign-in succeeds, which looks identical to a
 * sign-in that should have succeeded.
 *
 * No network and no live provider: the discovery document and key set are served from Http::fake, and
 * tokens are minted with the same library the client verifies them with. Negative-path cases like these
 * stay mock-only and must never be pointed at a real account, because repeated failed authentication is
 * precisely the pattern provider abuse-detection exists to catch.
 *
 * A Feature test rather than a Unit one because it fakes the HTTP client and the cache, both of which
 * are facades and need the application container. It reaches no network and no live provider.
 */
final class IdTokenVerificationTest extends TestCase
{
    private const ISSUER = 'https://idp.example/realms/test';

    private const CLIENT_ID = 'trusted-attestation';

    private const NONCE = 'nonce-from-this-session';

    private string $privateKey;

    private string $kid = 'test-key-1';

    /** @var list<array<string, string>> what the provider currently publishes */
    private array $publishedKeys = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        // Exported via a local: PHP will not pass a typed property by reference.
        openssl_pkey_export($key, $exported);
        $this->privateKey = $exported;

        $this->publish($key, $this->kid);

        // One stub, reading whatever key set the test has published. A second Http::fake() call would
        // not replace this one: Laravel accumulates stubs and the earliest match wins, so a rotation
        // has to be a change of data rather than a change of stub.
        Http::fake([
            '*/.well-known/openid-configuration' => Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER.'/auth',
                'token_endpoint' => self::ISSUER.'/token',
                'jwks_uri' => self::ISSUER.'/jwks',
            ]),
            '*/jwks' => fn () => Http::response(['keys' => $this->publishedKeys]),
        ]);
    }

    /**
     * Replaces what the provider publishes, as a rotation would. `alg` is optional on a JWK (RFC 7517 4.4):
     * pass null to publish a key without it, as Zitadel and other providers do.
     */
    private function publish(\OpenSSLAsymmetricKey $key, string $kid, ?string $alg = 'RS256'): void
    {
        $details = openssl_pkey_get_details($key);

        $jwk = [
            'kty' => 'RSA',
            'kid' => $kid,
            'use' => 'sig',
            'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
            'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
        ];
        if ($alg !== null) {
            $jwk['alg'] = $alg;
        }

        $this->publishedKeys = [$jwk];
    }

    private function client(): OidcClient
    {
        return new OidcClient(self::ISSUER, self::CLIENT_ID, 'secret', false);
    }

    /** @param array<string, mixed> $overrides */
    private function token(array $overrides = []): string
    {
        $now = time();

        return JWT::encode(array_merge([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'member-subject',
            'nonce' => self::NONCE,
            'iat' => $now,
            'exp' => $now + 300,
            'name' => 'Alice Anderson',
        ], $overrides), $this->privateKey, 'RS256', $this->kid);
    }

    /** The well-formed token above with one claim left out. */
    private function tokenWithout(string $claim): string
    {
        $now = time();
        $claims = [
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'member-subject',
            'nonce' => self::NONCE,
            'iat' => $now,
            'exp' => $now + 300,
        ];
        unset($claims[$claim]);

        return JWT::encode($claims, $this->privateKey, 'RS256', $this->kid);
    }

    // ---------------------------------------------------------------------------------------------
    // The token that should be accepted
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_well_formed_token_is_accepted(): void
    {
        $claims = $this->client()->verifyIdToken($this->token(), self::NONCE);

        self::assertSame('member-subject', $claims['sub']);
        self::assertSame('Alice Anderson', $claims['name']);
    }

    #[Test]
    public function a_token_is_accepted_when_the_provider_publishes_a_key_without_the_optional_alg(): void
    {
        // "alg" is optional on a JWK (RFC 7517 4.4). Zitadel -- and other providers -- publish signing keys
        // without it, and the algorithm is then taken from the token header, exactly as the .NET and Java
        // validators do. firebase/php-jwt otherwise refuses such a key ("JWK must contain an alg
        // parameter"), which would reject every login against those providers. Re-publish the same key
        // with no alg and confirm a well-formed token is still accepted.
        $this->publish(openssl_pkey_get_private($this->privateKey), $this->kid, alg: null);

        $claims = $this->client()->verifyIdToken($this->token(), self::NONCE);

        self::assertSame('member-subject', $claims['sub']);
    }

    #[Test]
    public function an_audience_array_containing_this_client_is_accepted(): void
    {
        // aud is permitted to be an array, and providers that issue multi-audience tokens are within
        // spec. Treating it as a string only would reject a legitimate login. With more than one audience,
        // OpenID Connect requires an azp naming this client, so a genuine multi-audience token carries one
        // -- matching the accepted case the other two implementations test (Java's
        // anAudienceArrayThatIncludesThisClientIsAccepted).
        $claims = $this->client()->verifyIdToken(
            $this->token(['aud' => ['another-client', self::CLIENT_ID], 'azp' => self::CLIENT_ID]),
            self::NONCE
        );

        self::assertSame('member-subject', $claims['sub']);
    }

    #[Test]
    public function a_subject_of_any_shape_is_accepted(): void
    {
        // Providers differ: a GUID from Entra, an email from some SAML-derived setups, an opaque
        // pairwise identifier from a privacy-preserving one. This server stores whatever it is given and must
        // not have an opinion about the format.
        foreach (['9f8e7d6c-5b4a-3210-fedc-ba9876543210', 'alice@example.org', 'kZ9x_pairwise'] as $sub) {
            $claims = $this->client()->verifyIdToken($this->token(['sub' => $sub]), self::NONCE);
            self::assertSame($sub, $claims['sub']);
        }
    }

    #[Test]
    public function a_token_carrying_only_a_subject_is_still_accepted(): void
    {
        // Where only the openid scope was granted, there is no name claim at all. That is a display
        // problem for the page to degrade around, not a reason to refuse the login.
        $minimal = JWT::encode([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'bare-subject',
            'nonce' => self::NONCE,
            'iat' => time(),
            'exp' => time() + 300,
        ], $this->privateKey, 'RS256', $this->kid);

        $claims = $this->client()->verifyIdToken($minimal, self::NONCE);

        self::assertSame('bare-subject', $claims['sub']);
        self::assertArrayNotHasKey('name', $claims);
    }

    // ---------------------------------------------------------------------------------------------
    // Tokens that must be refused, each wrong in exactly one way
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_provider_rotating_its_signing_keys_does_not_lock_members_out(): void
    {
        // The behaviour the retry exists for. A provider is free to rotate its signing keys whenever it
        // likes, and members should not notice.
        $client = $this->client();

        // A login now, which caches the current key set.
        $client->verifyIdToken($this->token(), self::NONCE);

        // The provider rotates: a new keypair, published under a new key id.
        $rotated = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($rotated, $rotatedPrivate);
        $this->publish($rotated, 'rotated-key');

        $afterRotation = JWT::encode([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'member-subject',
            'nonce' => self::NONCE,
            'iat' => time(),
            'exp' => time() + 300,
        ], $rotatedPrivate, 'RS256', 'rotated-key');

        // The cached set still holds only the old key, so this succeeds only if the client notices the
        // key id it does not recognise and goes back for the current set.
        $claims = $client->verifyIdToken($afterRotation, self::NONCE);

        self::assertSame('member-subject', $claims['sub']);
    }

    // ---------------------------------------------------------------------------------------------
    // What a refused token costs
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_refused_token_does_not_send_the_app_back_to_the_provider_for_keys(): void
    {
        // The client drops its cached key set and retries once when verification fails, so that a
        // provider rotating its signing keys does not lock every member out until the cache expires.
        //
        // It should only apply when the signature is what failed. An expired token, a wrong audience,
        // a mistyped token: none of those say anything about the keys, and refetching for them turns
        // every rejected login into an outbound request to the provider. On a busy instance that is
        // avoidable load on someone else's infrastructure, and it is reachable by anyone who can post
        // a malformed token at the callback.
        $client = $this->client();

        $client->verifyIdToken($this->token(), self::NONCE);
        $afterGoodLogin = count(Http::recorded());

        try {
            $client->verifyIdToken($this->token(['iat' => time() - 7200, 'exp' => time() - 3600]), self::NONCE);
        } catch (\Throwable) {
            // Expected: the point of this test is what it cost, not that it failed.
        }

        self::assertSame(
            $afterGoodLogin,
            count(Http::recorded()),
            'an expired token caused the key set to be fetched again'
        );
    }

    #[Test]
    public function a_token_from_another_issuer_is_refused(): void
    {
        // The organisation's other tenant, or an entirely different provider. Correctly signed by
        // whoever issued it, and still not a login here.
        $this->expectException(IdentityProviderException::class);

        $this->client()->verifyIdToken($this->token(['iss' => 'https://idp.example/realms/other']), self::NONCE);
    }

    #[Test]
    public function a_token_issued_for_another_client_is_refused(): void
    {
        // A token minted for a different application at the same provider. Accepting it would let any
        // other application's session become a session here.
        $this->expectException(IdentityProviderException::class);

        $this->client()->verifyIdToken($this->token(['aud' => 'some-other-application']), self::NONCE);
    }

    #[Test]
    public function an_audience_array_that_excludes_this_client_is_refused(): void
    {
        // The array form of the same rule: a multi-audience token is only for us if we are one of its
        // audiences, so one listing only other clients is refused.
        $this->expectException(IdentityProviderException::class);

        $this->client()->verifyIdToken(
            $this->token(['aud' => ['client-a', 'client-b']]), self::NONCE);
    }

    #[Test]
    public function a_multi_audience_token_without_an_authorized_party_is_refused(): void
    {
        // OpenID Connect Core 3.1.3.7: with more than one audience, an azp naming this client is required.
        // A token that lists this client among several audiences but carries no azp was minted for some
        // other client, and accepting it would let that other application's session become one here. The
        // other two implementations refuse it (Java's aMultiAudienceTokenWithoutAnAuthorizedPartyIsRefused,
        // .NET's OpenIdConnectProtocolValidator); this must too.
        $this->expectException(IdentityProviderException::class);

        $this->client()->verifyIdToken(
            $this->token(['aud' => ['some-other-client', self::CLIENT_ID]]), self::NONCE);
    }

    #[Test]
    public function a_multi_audience_token_whose_authorized_party_is_another_client_is_refused(): void
    {
        // The azp is present but names a different client, so the token was authorized for that client,
        // not this one. Being merely listed in aud is not enough.
        $this->expectException(IdentityProviderException::class);

        $this->client()->verifyIdToken(
            $this->token(['aud' => ['some-other-client', self::CLIENT_ID], 'azp' => 'some-other-client']),
            self::NONCE);
    }

    #[Test]
    public function a_single_audience_token_whose_authorized_party_is_another_client_is_refused(): void
    {
        // Any azp present has to name this client, however many audiences there are, as on the other two
        // implementations. An azp naming another client says the token was authorized for that client.
        $this->expectException(IdentityProviderException::class);

        $this->client()->verifyIdToken($this->token(['azp' => 'some-other-client']), self::NONCE);
    }

    #[Test]
    public function a_single_audience_token_whose_authorized_party_is_this_client_is_accepted(): void
    {
        $claims = $this->client()->verifyIdToken($this->token(['azp' => self::CLIENT_ID]), self::NONCE);

        self::assertSame('member-subject', $claims['sub']);
    }

    #[Test]
    public function a_token_with_no_issued_at_time_is_refused(): void
    {
        // OpenID Connect Core 2 makes iat required, and the other two implementations refuse a token
        // without one.
        $this->expectException(IdentityProviderException::class);

        $this->client()->verifyIdToken($this->tokenWithout('iat'), self::NONCE);
    }

    #[Test]
    public function a_token_with_no_subject_is_refused(): void
    {
        // The subject is who the member is, so a token without one is not a login, as on the other two
        // implementations.
        $this->expectException(IdentityProviderException::class);

        $this->client()->verifyIdToken($this->tokenWithout('sub'), self::NONCE);
    }

    #[Test]
    public function a_token_with_no_expiry_is_refused(): void
    {
        // firebase/php-jwt only checks exp when it is present, so a token that omits it clears the
        // decoder's time checks with nothing to enforce. An ID token without an expiry is never valid
        // (OpenID Connect Core makes exp required), and the other two implementations reject it -- their
        // OIDC validators treat exp as a required claim. This is the case a check that leans entirely on
        // the decoder's optional guards would let through.
        $this->expectException(IdentityProviderException::class);

        $token = JWT::encode([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'member-subject',
            'nonce' => self::NONCE,
            'iat' => time(),
        ], $this->privateKey, 'RS256', $this->kid);

        $this->client()->verifyIdToken($token, self::NONCE);
    }

    #[Test]
    public function a_token_whose_nonce_does_not_match_this_session_is_refused(): void
    {
        // The nonce ties the token to the authorization request this browser started. Without the
        // check, a token captured elsewhere could be replayed into someone else's session.
        $this->expectException(IdentityProviderException::class);

        $this->client()->verifyIdToken($this->token(), 'a-different-sessions-nonce');
    }

    #[Test]
    public function a_token_with_no_nonce_at_all_is_refused(): void
    {
        // Absent must not read as matching. This is the case a null-safe comparison written slightly
        // wrong would let through.
        $this->expectException(IdentityProviderException::class);

        $token = JWT::encode([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'member-subject',
            'iat' => time(),
            'exp' => time() + 300,
        ], $this->privateKey, 'RS256', $this->kid);

        $this->client()->verifyIdToken($token, self::NONCE);
    }

    #[Test]
    public function an_expired_token_is_refused(): void
    {
        // Well past the 60 seconds of clock-skew leeway the client allows. Refused as expired
        // specifically, not merely refused: the type is the point, so a future bug that threw something
        // else here (or accepted it) would not pass by looking like an expiry rejection.
        $this->expectException(ExpiredException::class);

        $this->client()->verifyIdToken(
            $this->token(['iat' => time() - 7200, 'exp' => time() - 3600]),
            self::NONCE
        );
    }

    #[Test]
    public function a_token_expired_within_the_clock_skew_allowance_is_accepted(): void
    {
        // The other side of the same boundary: 30 seconds past expiry is inside the 60-second leeway, so
        // two clocks a little out of step do not sign a member out. Matches the other two implementations.
        $claims = $this->client()->verifyIdToken(
            $this->token(['iat' => time() - 330, 'exp' => time() - 30]),
            self::NONCE
        );

        self::assertSame('member-subject', $claims['sub']);
    }

    #[Test]
    public function a_token_not_yet_valid_beyond_the_clock_skew_allowance_is_refused(): void
    {
        $this->expectException(BeforeValidException::class);

        $this->client()->verifyIdToken(
            $this->token(['iat' => time() + 300, 'nbf' => time() + 300, 'exp' => time() + 600]),
            self::NONCE
        );
    }

    #[Test]
    public function a_token_signed_by_a_key_the_provider_does_not_publish_is_refused(): void
    {
        // The security-critical one. Everything else here is a claim check; this is whether the
        // signature is checked at all.
        $stranger = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($stranger, $strangerKey);

        $forged = JWT::encode([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'attacker-chosen-subject',
            'nonce' => self::NONCE,
            'iat' => time(),
            'exp' => time() + 300,
        ], $strangerKey, 'RS256', $this->kid);

        // Refused specifically because the signature does not verify -- not for some incidental reason.
        $this->expectException(SignatureInvalidException::class);

        $this->client()->verifyIdToken($forged, self::NONCE);
    }

    #[Test]
    public function a_token_that_is_not_a_jwt_is_refused(): void
    {
        // No dots, so not even three segments to read -- refused before any claim or signature check.
        $this->expectException(\UnexpectedValueException::class);

        $this->client()->verifyIdToken('this is not a token', self::NONCE);
    }
}
