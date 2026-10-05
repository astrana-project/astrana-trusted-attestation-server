<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Oidc\OidcClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The IdP is checked at startup, not at the first login.
 *
 * Decision record 31 in docs/adr is explicit that detectable IAM misconfiguration must stop the application from
 * starting rather than surfacing confusingly at first login. Discovery is fetched lazily, so without the
 * check the application would start cleanly with a completely unreachable identity provider and serve
 * the manifest normally.
 *
 * That failure mode is worse than an outage. The instance looks healthy to every check an operator has
 * -- it is serving, the manifest is right, /attest answers -- and the only people who see the problem
 * are members, one at a time, at the moment they try to sign in. Whoever deployed it has usually moved
 * on by then.
 *
 * The scoping is tested too: this covers what can be checked without a live
 * login. A syntactically valid but wrong client secret cannot be caught this way by definition, and
 * pretending otherwise would be worse than not checking.
 *
 * A Feature test rather than a Unit one because it fakes the HTTP client and the cache, both of which
 * are facades and need the application container. It reaches no network and no database.
 */
final class IamReachabilityTest extends TestCase
{
    private const ISSUER = 'https://idp.example/realms/test';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function client(?string $discoveryUrl = null): OidcClient
    {
        return new OidcClient(self::ISSUER, 'trusted-attestation', 'secret', false, $discoveryUrl);
    }

    // ---------------------------------------------------------------------------------------------
    // What the check refuses
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function an_unreachable_discovery_url_is_refused(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->expectException(RuntimeException::class);
        $this->client()->assertReachable();
    }

    #[Test]
    public function a_discovery_document_that_is_not_json_is_refused(): void
    {
        Http::fake(['*' => Http::response('<html>not a discovery document</html>', 200)]);

        $this->expectException(RuntimeException::class);
        $this->client()->assertReachable();
    }

    #[Test]
    public function a_discovery_document_missing_its_endpoints_is_refused(): void
    {
        // Reachable, parseable, and useless. A provider behind a captive portal or a misconfigured
        // reverse proxy answers 200 with something that is not a discovery document at all.
        Http::fake(['*' => Http::response(['issuer' => self::ISSUER], 200)]);

        $this->expectException(RuntimeException::class);
        $this->client()->assertReachable();
    }

    #[Test]
    public function the_refusal_names_the_url_it_could_not_read(): void
    {
        // The message is the entire diagnosis: the app is not running and there is nothing to inspect.
        // An operator needs to know which URL, because the issuer and the discovery URL can differ and
        // "the IdP is unreachable" does not say which one was tried.
        Http::fake(['*' => Http::response('', 500)]);

        try {
            $this->client('https://idp.example/somewhere-else/openid-configuration')->assertReachable();
            self::fail('an unreachable IdP was accepted');
        } catch (RuntimeException $refusal) {
            self::assertStringContainsString('somewhere-else', $refusal->getMessage());
        }
    }

    // ---------------------------------------------------------------------------------------------
    // What it accepts
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_reachable_provider_is_accepted(): void
    {
        Http::fake(['*' => Http::response([
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/auth',
            'token_endpoint' => self::ISSUER.'/token',
            'jwks_uri' => self::ISSUER.'/jwks',
        ], 200)]);

        // The point is that a reachable provider does not throw. assertTrue(true) cannot fail and so
        // asserts nothing; expectNotToPerformAssertions() states honestly that the absence of a thrown
        // exception is the whole test, and a throw here surfaces as an error rather than a silent pass.
        $this->expectNotToPerformAssertions();

        $this->client()->assertReachable();
    }

    #[Test]
    public function a_wrong_client_secret_is_not_caught_here_and_should_not_be(): void
    {
        // The honest limit of this check, asserted so nobody later "improves" it into something that
        // claims more than it can deliver. A secret is only tested by an actual token exchange, which
        // needs a member to have logged in. Overclaiming here would mean an instance that started
        // successfully was believed to have working credentials.
        Http::fake(['*' => Http::response([
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/auth',
            'token_endpoint' => self::ISSUER.'/token',
        ], 200)]);

        // The point is that startup does NOT throw over a secret it cannot check here. Stated as an
        // absence of assertions rather than a can't-fail assertTrue(true).
        $this->expectNotToPerformAssertions();

        $wrongSecret = new OidcClient(self::ISSUER, 'trusted-attestation', 'not-the-secret', false);
        $wrongSecret->assertReachable();
    }

    // ---------------------------------------------------------------------------------------------
    // What it costs afterwards
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_startup_check_warms_the_cache_rather_than_paying_twice(): void
    {
        // The document fetched at startup is the same one the first login needs. Fetching it twice
        // would make the check a cost rather than a check.
        Http::fake(['*' => Http::response([
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/auth',
            'token_endpoint' => self::ISSUER.'/token',
        ], 200)]);

        $client = $this->client();
        $client->assertReachable();

        $afterStartup = count(Http::recorded());
        $client->discovery();

        self::assertSame($afterStartup, count(Http::recorded()),
            'the first login fetched discovery again despite startup having just read it');
    }
}
