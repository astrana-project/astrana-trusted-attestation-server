<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Saml\IdpMetadataSource;
use App\Services\Saml\SamlClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a member's browser receives when a SAML sign-in cannot be started because the identity provider asks
 * for signed authentication requests and this server has no signing key. The .NET implementation (Sustainsys
 * refuses to build the request) and the Java implementation (Spring Security cannot sign it) both answer
 * HTTP 500 - Internal Server Error with no body, and log the cause, so this one does the same rather than
 * sending a request the identity provider would refuse.
 *
 * A Feature test because it sends the request through the router, the controller and the exception handler,
 * which means the application has to have booted with the SAML routes registered.
 */
final class SamlSignInRouteTest extends TestCase
{
    private const IDP_METADATA_URL = 'https://idp.example/metadata';

    protected function setUp(): void
    {
        // Set before the application boots: routes are registered during boot.
        putenv('TRUSTED_ATTESTATION_IAM_PROTOCOL=saml');
        $_ENV['TRUSTED_ATTESTATION_IAM_PROTOCOL'] = 'saml';

        parent::setUp();
        Cache::flush();

        config([
            'trusted_attestation.iam.saml.idp_metadata_url' => self::IDP_METADATA_URL,
            'trusted_attestation.iam.saml.sp_certificate_path' => null,
            'trusted_attestation.iam.saml.sp_private_key_path' => null,
        ]);
    }

    protected function tearDown(): void
    {
        putenv('TRUSTED_ATTESTATION_IAM_PROTOCOL');
        unset($_ENV['TRUSTED_ATTESTATION_IAM_PROTOCOL']);

        parent::tearDown();
    }

    #[Test]
    public function a_sign_in_the_identity_provider_would_refuse_unsigned_is_never_sent(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_x509_export(openssl_csr_sign(openssl_csr_new(['commonName' => 'idp'], $key), null, $key, 1), $idpCertificate);

        Cache::put(SamlClient::metadataCacheKey(self::IDP_METADATA_URL), ['idp' => [
            'entityId' => 'https://idp.example/saml',
            'singleSignOnService' => [
                'url' => 'https://idp.example/sso',
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
            ],
            'x509cert' => $idpCertificate,
            IdpMetadataSource::WANTS_SIGNED_REQUESTS => true,
        ]], 3600);
        Log::spy();

        $response = $this->get('/auth/saml/login');

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertFalse($response->headers->has('Location'), 'the member was sent on to the identity provider');
        $response->assertCookieMissing('ata_saml_authn');
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message): bool => str_contains($message, 'The identity provider asks for signed authentication requests and no SAML signing key is configured'))
            ->once();
    }
}
