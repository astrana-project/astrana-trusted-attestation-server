<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A deployment speaks one protocol. The endpoints belonging to the other one should not be answering.
 *
 * Registered under OIDC, the SAML routes would not sit harmlessly unused. With no SAML configuration to
 * build a client from, each one would fail while being constructed, before the controller's own
 * try/catch could help, so an unauthenticated caller would get a 500 from an endpoint this deployment
 * does not implement. There is also no meaningful metadata to publish when no service provider is
 * configured, so nothing is lost by not routing it.
 *
 * A Feature test rather than a Unit one because it asks the router which routes exist, which means
 * the application has to have booted. It issues no requests and touches no database.
 */
final class SamlRouteExposureTest extends TestCase
{
    protected function setUp(): void
    {
        // Set before the application boots: routes are registered during boot, so changing config
        // afterwards would not affect what is routed.
        putenv('TRUSTED_ATTESTATION_IAM_PROTOCOL=oidc');
        $_ENV['TRUSTED_ATTESTATION_IAM_PROTOCOL'] = 'oidc';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('TRUSTED_ATTESTATION_IAM_PROTOCOL');
        unset($_ENV['TRUSTED_ATTESTATION_IAM_PROTOCOL']);

        parent::tearDown();
    }

    public static function samlRoutes(): iterable
    {
        yield 'metadata' => ['get', '/auth/saml/metadata'];
        yield 'login' => ['get', '/auth/saml/login'];
        yield 'assertion consumer service' => ['post', '/auth/saml/acs'];
        yield 'logout over the Redirect binding' => ['get', '/auth/saml/logout'];
        yield 'logout over the POST binding' => ['post', '/auth/saml/logout'];
    }

    #[Test]
    #[DataProvider('samlRoutes')]
    public function saml_routes_are_not_served_by_an_oidc_deployment(string $method, string $path): void
    {
        self::assertSame('oidc', config('trusted_attestation.iam.protocol'));

        $response = $this->call(strtoupper($method), $path);

        self::assertSame(
            404,
            $response->getStatusCode(),
            "{$method} {$path} answered {$response->getStatusCode()} on an OIDC deployment"
        );
    }

    #[Test]
    public function the_endpoints_this_deployment_does_implement_still_answer(): void
    {
        // Guards against the obvious over-correction: dropping the SAML routes must not disturb the
        // routes that carry the actual contract.
        self::assertNotSame(404, $this->get('/.well-known/ata-manifest.json')->getStatusCode());
        self::assertNotSame(404, $this->get('/auth/login')->getStatusCode());
        self::assertNotSame(404, $this->call('POST', '/api/v1/attest')->getStatusCode());
    }
}
