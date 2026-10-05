<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use App\Exceptions\ConfigurationException;
use App\Exceptions\IdentityProviderException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

/**
 * A generic OpenID Connect relying party.
 *
 * Written against the protocol rather than any vendor's SDK, because decision record 2 in docs/adr requires Astrana
 * Trusted Attestation to work with any standards-compliant provider, such as Keycloak, Entra ID, Okta,
 * Auth0, Ping or WSO2, without custom integration, so everything here comes from the provider's own
 * discovery document.
 *
 * Authorization Code + PKCE, and nothing else: no implicit grant, no resource-owner password credentials,
 * exact redirect URI matching. That is the OAuth 2.1 profile, which is not a separate protocol to choose
 * but simply the secure way to do OAuth 2.0.
 */
final class OidcClient
{
    private const CACHE_SECONDS = 3600;

    /** Leeway for clock skew between this server and the IdP, in seconds. */
    private const CLOCK_SKEW = 60;

    /**
     * The signature algorithms an ID token may be signed with: asymmetric only. "alg" is optional on a JWK
     * (RFC 7517 4.4), and a provider may publish signing keys without it -- Zitadel does, within spec -- in
     * which case the algorithm is taken from the token header, exactly as the .NET and Java validators do.
     * That header value is honoured only if it appears here (and is advertised by the IdP): a symmetric alg
     * verified against a public key is the classic key-confusion forgery, and "none" is not a signature at
     * all, so neither is ever accepted.
     */
    private const SIGNING_ALGORITHMS = [
        'RS256', 'RS384', 'RS512', 'ES256', 'ES384', 'ES512', 'PS256', 'PS384', 'PS512',
    ];

    public function __construct(
        private readonly string $issuer,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly bool $requireHttpsMetadata,
        /**
         * Where the discovery document is fetched from, when that differs from the issuer's own
         * well-known path. Only the location differs: tokens are still validated against the issuer.
         */
        private readonly ?string $discoveryUrl = null,
    ) {
        if ($this->issuer === '') {
            throw new ConfigurationException('No IAM issuer configured. Set TRUSTED_ATTESTATION_IAM_ISSUER.');
        }

        if ($this->clientId === '') {
            throw new ConfigurationException('No IAM client identifier configured. Set TRUSTED_ATTESTATION_IAM_CLIENT_ID.');
        }

        if ($this->requireHttpsMetadata && ! str_starts_with($this->issuer, 'https://')) {
            throw new ConfigurationException(
                'The IAM issuer must be an https:// URL. TRUSTED_ATTESTATION_IAM_REQUIRE_HTTPS_METADATA may be turned off '
                .'only to talk to a local development IdP.'
            );
        }
    }

    /**
     * Cache keys are derived from the issuer, so pointing the app at a different IdP cannot be served a
     * document fetched from the previous one. A fixed key would outlive the configuration it describes,
     * and the resulting failure -- being redirected to the old provider -- looks nothing like its cause.
     */
    private function cacheKey(string $purpose): string
    {
        return 'trusted_attestation.oidc.'.$purpose.'.'.hash('sha256', $this->issuer.'|'.($this->discoveryUrl ?? ''));
    }

    /**
     * The provider's discovery document, cached. Everything else is derived from it, so a provider that
     * moves an endpoint does not need a code change here.
     */
    public function discovery(): array
    {
        return Cache::remember($this->cacheKey('discovery'), self::CACHE_SECONDS, function (): array {
            $url = $this->discoveryUrl
                ?: rtrim($this->issuer, '/').'/.well-known/openid-configuration';
            $response = Http::timeout(10)->get($url);

            if (! $response->successful()) {
                throw new IdentityProviderException("Could not read the IdP discovery document at {$url}.");
            }

            $document = $response->json();
            if (! is_array($document) || ! isset($document['authorization_endpoint'], $document['token_endpoint'])) {
                throw new IdentityProviderException("The IdP discovery document at {$url} is not usable.");
            }

            return $document;
        });
    }

    /**
     * Reads the provider's discovery document once, so a misconfigured IdP stops the application from
     * starting rather than surfacing at the first member's login.
     *
     * Without this, the application would start cleanly with a completely unreachable identity provider,
     * because discovery is fetched lazily and nothing looks at it until somebody tries to sign in. That is
     * worse than an outage: the instance looks healthy to every check an operator has, and the only people who see the
     * problem are members, one at a time, long after whoever deployed it has moved on.
     *
     * Scoped to what is actually checkable without a live login. A syntactically valid but wrong client
     * secret cannot be caught this way by definition -- it only fails at a real token exchange -- and a
     * check that implied otherwise would be worse than no check, because an instance that started would
     * be believed to have working credentials.
     *
     * Costs nothing after startup: the document is cached, and it is the same one the first login needs.
     */
    public function assertReachable(): void
    {
        $this->discovery();
    }

    /**
     * Where to send the member to log in.
     *
     * The caller keeps state, nonce and the PKCE verifier in the session: state ties the callback to this
     * browser, nonce ties the ID token to this request, and the verifier proves the callback came from
     * whoever started the flow.
     */
    public function authorizationUrl(string $redirectUri, string $state, string $nonce, string $codeVerifier): string
    {
        $discovery = $this->discovery();

        $parameters = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => $this->scope(),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::codeChallenge($codeVerifier),
            'code_challenge_method' => 'S256',
        ];

        return $discovery['authorization_endpoint'].'?'.http_build_query($parameters);
    }

    /** Exchanges the authorization code for tokens. The client authenticates itself here as well. */
    public function exchangeCode(string $code, string $redirectUri, string $codeVerifier): array
    {
        $discovery = $this->discovery();

        $response = Http::asForm()
            ->timeout(10)
            // A confidential client authenticates even though PKCE is in use: PKCE proves the caller
            // started the flow, client authentication proves which application it is.
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->post($discovery['token_endpoint'], [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'code_verifier' => $codeVerifier,
            ]);

        if (! $response->successful()) {
            throw new IdentityProviderException('The IdP refused the authorization code exchange.');
        }

        $tokens = $response->json();
        if (! is_array($tokens) || ! isset($tokens['id_token'])) {
            throw new IdentityProviderException('The IdP returned no ID token.');
        }

        return $tokens;
    }

    /**
     * Verifies the ID token and returns its claims.
     *
     * Signature against the provider's published keys, then that an expiry, an issued-at time and a subject
     * are present and the expiry is in the future, then issuer, audience, authorized party and nonce. A
     * token that fails any of these is not a login.
     *
     * @return array<string, mixed>
     */
    public function verifyIdToken(string $idToken, string $expectedNonce): array
    {
        JWT::$leeway = self::CLOCK_SKEW;

        // The algorithm for keys whose JWK omits the optional "alg", taken from the token header and
        // constrained (see signingAlgorithm). Passed to parseKeySet as the default; a key that carries its
        // own "alg" keeps it, so this changes nothing for providers that publish one (Keycloak, Authentik)
        // and only supplies what Zitadel and others leave off.
        $algorithm = $this->signingAlgorithm($idToken);

        try {
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($this->jwks(), $algorithm));
        } catch (\Throwable $exception) {
            // Providers rotate their signing keys, and a cached key set outlives the rotation. Rather
            // than reject every login until the cache expires, drop the cached keys and try once more
            // against freshly fetched ones. A token that is genuinely bad fails again immediately.
            //
            // Only where the failure actually implicates the key set, though. An expired token, one for
            // another audience, a mistyped one: none of those say anything about the keys, and
            // refetching for them turns every rejected login into an outbound request to the provider.
            // The callback is reachable by anyone, so that is load on someone else's infrastructure
            // that an unauthenticated caller could trigger at will.
            if (! self::implicatesTheKeySet($exception)) {
                throw $exception;
            }

            Cache::forget($this->cacheKey('jwks'));

            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($this->jwks(), $algorithm));
        }

        // firebase/php-jwt validates exp only when it is present, so a token that simply omits it clears
        // the decoder's time checks with nothing to enforce. An ID token without an expiry is never valid
        // (OpenID Connect Core 2 makes exp required), and the other two implementations reject it -- their
        // OIDC validators treat exp as a required claim. Require it here too, so a login that would fail on
        // .NET or Java fails here as well rather than being accepted with no expiry at all.
        if (! isset($claims['exp'])) {
            throw new IdentityProviderException('The ID token has no expiry.');
        }

        // The same section makes iat and sub required, and the other two implementations refuse a token
        // without either. The subject is who the member is, so without one there is nobody to sign in.
        if (! isset($claims['iat'])) {
            throw new IdentityProviderException('The ID token has no issued-at time.');
        }

        if (! isset($claims['sub'])) {
            throw new IdentityProviderException('The ID token has no subject.');
        }

        // Compared with a trailing slash normalised off both sides. An issuer identifier means the
        // same thing with or without a trailing slash, but some providers -- Authentik among them --
        // put one in the token's iss while the configured value is stored without it, so an exact
        // comparison would reject a perfectly valid token from them.
        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== $this->issuer) {
            throw new IdentityProviderException('The ID token was issued by a different issuer than the one configured.');
        }

        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];
        if (! in_array($this->clientId, $audiences, true)) {
            throw new IdentityProviderException('The ID token was not issued for this client.');
        }

        // With more than one audience, OpenID Connect Core 3.1.3.7 requires an azp (authorized party)
        // claim naming the client the token was minted for, and it must be this client. Without this a
        // token issued for a different client that merely lists this one among its audiences would be
        // accepted, a cross-client token-confusion login the other two implementations refuse. An azp that
        // is present has to name this client whatever the number of audiences, as on the other two
        // implementations, because it says which client the token was authorized for.
        if (count($audiences) > 1 && ! array_key_exists('azp', $claims)) {
            throw new IdentityProviderException('The ID token has multiple audiences but no authorized party naming this client.');
        }

        if (array_key_exists('azp', $claims) && $claims['azp'] !== $this->clientId) {
            throw new IdentityProviderException('The ID token was authorized for a different client.');
        }

        // Ties this token to the authorization request this browser started, so a token captured
        // elsewhere cannot be replayed into someone else's session.
        if (($claims['nonce'] ?? null) !== $expectedNonce) {
            throw new IdentityProviderException('The ID token nonce does not match the one this session sent.');
        }

        return $claims;
    }

    /**
     * Claims from the UserInfo endpoint, which the caller merges beneath the ID token's so the verified
     * token wins on any overlap.
     *
     * Needed because some providers put the claims the session keeps, such as the email address used as
     * the subject, the display name or the locale, there rather than in the ID token. Returns an empty
     * array when the provider offers no UserInfo endpoint.
     *
     * @return array<string, mixed>
     */
    public function userInfo(string $accessToken): array
    {
        $endpoint = $this->discovery()['userinfo_endpoint'] ?? null;
        if (! is_string($endpoint) || $endpoint === '') {
            return [];
        }

        $response = Http::timeout(10)->withToken($accessToken)->get($endpoint);

        if (! $response->successful()) {
            return [];
        }

        $claims = $response->json();

        return is_array($claims) ? $claims : [];
    }

    /** Where to send the member to end their session at the IdP as well as here. */
    public function endSessionUrl(?string $idToken, string $postLogoutRedirectUri): ?string
    {
        $endpoint = $this->discovery()['end_session_endpoint'] ?? null;
        if (! is_string($endpoint) || $endpoint === '') {
            return null;
        }

        $parameters = ['post_logout_redirect_uri' => $postLogoutRedirectUri, 'client_id' => $this->clientId];
        if ($idToken !== null) {
            $parameters['id_token_hint'] = $idToken;
        }

        return $endpoint.'?'.http_build_query($parameters);
    }

    public static function randomValue(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function scope(): string
    {
        $scopes = array_merge(
            ['openid', 'profile', 'email'],
            array_map(trim(...), (array) config('trusted_attestation.iam.additional_scopes', []))
        );

        return implode(' ', array_unique(array_filter($scopes)));
    }

    /**
     * The algorithm an ID token is signed with, used as the default for signing keys whose JWK omits the
     * optional "alg". Read from the token's own header and accepted only if the IdP advertises it in its
     * discovery document AND it is one of the asymmetric algorithms in SIGNING_ALGORITHMS -- so a token can
     * never dictate a weaker or confused algorithm than the provider actually signs with. The signature is
     * still verified against the published key; this only decides which algorithm that verification uses
     * when the key itself does not say.
     */
    private function signingAlgorithm(string $idToken): string
    {
        $segments = explode('.', $idToken);
        $header = json_decode((string) JWT::urlsafeB64Decode($segments[0] ?? ''), true);
        $alg = is_array($header) ? ($header['alg'] ?? null) : null;

        // A well-formed JWS always carries "alg" in its header (RFC 7515 4.1.1), so a token from which no
        // header algorithm can be read is malformed, not merely using an algorithm we dislike. Leave it to
        // the decoder, which refuses a malformed token exactly as before, and hand back a harmless default
        // for the key set in the meantime -- the decode fails regardless.
        if (! is_string($alg)) {
            return self::SIGNING_ALGORITHMS[0];
        }

        // A header algorithm we can read is honoured only if it is asymmetric and the IdP advertises it, so
        // a token cannot force a weaker or confused algorithm than the provider actually signs with.
        $advertised = $this->discovery()['id_token_signing_alg_values_supported'] ?? self::SIGNING_ALGORITHMS;
        $advertised = is_array($advertised) ? $advertised : self::SIGNING_ALGORITHMS;

        if (! in_array($alg, self::SIGNING_ALGORITHMS, true) || ! in_array($alg, $advertised, true)) {
            throw new IdentityProviderException('The ID token is signed with an unsupported algorithm.');
        }

        return $alg;
    }

    private function jwks(): array
    {
        return Cache::remember($this->cacheKey('jwks'), self::CACHE_SECONDS, function (): array {
            $uri = $this->discovery()['jwks_uri'] ?? null;
            if (! is_string($uri) || $uri === '') {
                throw new IdentityProviderException('The IdP publishes no JWKS URI, so ID tokens cannot be verified.');
            }

            $response = Http::timeout(10)->get($uri);
            if (! $response->successful()) {
                throw new IdentityProviderException("Could not read the IdP signing keys at {$uri}.");
            }

            $keys = $response->json();
            if (! is_array($keys) || ! isset($keys['keys'])) {
                throw new IdentityProviderException("The IdP signing keys at {$uri} are not a usable JWK set.");
            }

            return $keys;
        });
    }

    /**
     * Whether a verification failure is one that a fresh key set could plausibly fix.
     *
     * Two shapes qualify. A signature that does not verify is the obvious one: the token may have been
     * signed with a key issued after this key set was cached. The other is a token naming a key id the
     * cached set does not contain, which is what a rotation looks like when the provider publishes a new
     * kid -- php-jwt reports that as an UnexpectedValueException, the same type it uses for a token that
     * is simply malformed, so the message is the only thing distinguishing them.
     *
     * Everything else -- expiry, not-yet-valid, wrong segment count -- is a fact about the token that no
     * amount of refetching changes.
     */
    private static function implicatesTheKeySet(\Throwable $exception): bool
    {
        if ($exception instanceof SignatureInvalidException) {
            return true;
        }

        return $exception instanceof UnexpectedValueException
            && str_contains(strtolower($exception->getMessage()), 'kid');
    }
}
