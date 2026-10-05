<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Providers\TrustedAttestationServiceProvider;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The protocol setting is read without regard to case, in every place that reads it, as on the other two
 * implementations. "SAML" registers the SAML routes, sends a sign-in to them and skips the OpenID Connect
 * reachability check at start, exactly as "saml" does.
 *
 * A Feature test because the routes are registered while the application boots, so the setting is in the
 * environment before it does.
 */
final class ProtocolSettingTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('TRUSTED_ATTESTATION_IAM_PROTOCOL=SAML');
        $_ENV['TRUSTED_ATTESTATION_IAM_PROTOCOL'] = 'SAML';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('TRUSTED_ATTESTATION_IAM_PROTOCOL');
        unset($_ENV['TRUSTED_ATTESTATION_IAM_PROTOCOL']);

        parent::tearDown();
    }

    #[Test]
    public function the_saml_routes_are_registered(): void
    {
        self::assertNotNull(Route::getRoutes()->getByAction('App\Http\Controllers\SamlController@acs'));
    }

    #[Test]
    public function a_sign_in_is_sent_to_the_saml_flow(): void
    {
        $response = $this->get('/auth/login?next=/me');

        self::assertStringContainsString('/auth/saml/login?next=', (string) $response->headers->get('Location'));
    }

    #[Test]
    public function the_openid_connect_provider_is_not_contacted_at_start(): void
    {
        // No issuer is configured here, so reaching for the OpenID Connect provider would throw.
        config(['trusted_attestation.iam.issuer' => '']);

        $provider = new TrustedAttestationServiceProvider($this->app);
        (new ReflectionMethod($provider, 'assertIamReachable'))->invoke($provider);

        $this->expectNotToPerformAssertions();
    }
}
