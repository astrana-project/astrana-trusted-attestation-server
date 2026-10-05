<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Oidc\OidcClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * How the authorization request is built -- specifically its PKCE proof.
 *
 * The security-critical part is the code_challenge: it must be the S256 (base64url of SHA-256) transform of
 * the verifier, not the verifier itself and not plain. Getting it wrong turns PKCE off silently -- the IdP
 * still redirects and login still works, so a black-box test sees nothing, yet the interception protection
 * is gone. A live IdP is faked here so the built
 * URL can be inspected directly; only the discovery lookup is stubbed, the parameter assembly is real.
 */
final class OidcAuthorizationUrlTest extends TestCase
{
    private const VERIFIER = 'a-code-verifier-of-sufficient-length-0123456789abcdef';

    private function client(): OidcClient
    {
        Http::fake([
            '*/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://idp.example',
                'authorization_endpoint' => 'https://idp.example/authorize',
                'token_endpoint' => 'https://idp.example/token',
                'jwks_uri' => 'https://idp.example/jwks',
            ]),
        ]);

        return new OidcClient('https://idp.example', 'ata-client', 'a-secret', false, null);
    }

    /** @return array<string, string> */
    private function paramsOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

        /** @var array<string, string> $params */
        return $params;
    }

    #[Test]
    public function the_code_challenge_is_the_s256_transform_of_the_verifier(): void
    {
        $url = $this->client()->authorizationUrl('https://ata.example/callback', 'the-state', 'the-nonce', self::VERIFIER);

        $expected = rtrim(strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'), '=');

        $params = $this->paramsOf($url);
        self::assertSame('S256', $params['code_challenge_method']);
        self::assertSame($expected, $params['code_challenge']);
        // Never the verifier in the clear, which would defeat the exchange-binding entirely.
        self::assertNotSame(self::VERIFIER, $params['code_challenge']);
    }

    #[Test]
    public function it_carries_the_authorization_code_flow_parameters(): void
    {
        $url = $this->client()->authorizationUrl('https://ata.example/callback', 'the-state', 'the-nonce', self::VERIFIER);

        self::assertStringStartsWith('https://idp.example/authorize?', $url);

        $params = $this->paramsOf($url);
        self::assertSame('code', $params['response_type']);
        self::assertSame('ata-client', $params['client_id']);
        self::assertSame('https://ata.example/callback', $params['redirect_uri']);
        self::assertSame('the-state', $params['state']);
        self::assertSame('the-nonce', $params['nonce']);
        // openid is mandatory for OIDC; without it the IdP need not return an id_token at all.
        self::assertStringContainsString('openid', $params['scope']);
    }
}
