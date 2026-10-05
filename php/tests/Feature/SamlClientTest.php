<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ConfigurationException;
use App\Exceptions\IdentityProviderException;
use App\Services\Saml\IdpMetadataSource;
use App\Services\Saml\SamlClient;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The SAML service provider's settings assembly -- everything the SP is built from except the security
 * posture, which is pinned on its own in SamlSecuritySettingsTest, and the assertion processing, which is
 * the OneLogin library's and is driven end to end by the conformance suite.
 *
 * What belongs to this implementation here is the wiring: that the SP metadata an IdP administrator registers is generated from
 * the same settings the app runs on (so the two cannot drift), that the IdP half is read from its published
 * metadata rather than configured by hand, that the SP signs its requests exactly when it holds a key, and
 * that unreadable or unusable configuration is refused loudly rather than half-applied.
 *
 * The IdP metadata is normally fetched over the network by the OneLogin parser; here the parse result is
 * seeded directly into the same cache the client reads, keyed exactly as the client keys it, so the
 * assembly runs with no network and no live IdP. A Feature test because it reads configuration and uses the
 * cache facade, both of which need the container.
 */
final class SamlClientTest extends TestCase
{
    private const APP_URL = 'https://ata.example';

    private const IDP_METADATA_URL = 'https://idp.example/metadata';

    private const IDP_ENTITY_ID = 'https://idp.example/saml';

    private string $idpCert;

    private string $spCert;

    private string $spKey;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        [$this->idpCert] = self::selfSignedCertificate();
        [$this->spCert, $this->spKey] = self::selfSignedCertificate();

        config([
            'app.url' => self::APP_URL,
            'trusted_attestation.iam.protocol' => 'saml',
            'trusted_attestation.iam.saml.idp_metadata_url' => self::IDP_METADATA_URL,
            'trusted_attestation.iam.saml.entity_id' => '',
            'trusted_attestation.iam.saml.sp_certificate_path' => null,
            'trusted_attestation.iam.saml.sp_private_key_path' => null,
        ]);

        $this->seedIdpMetadata([
            'idp' => [
                'entityId' => self::IDP_ENTITY_ID,
                'singleSignOnService' => [
                    'url' => 'https://idp.example/sso',
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'singleLogoutService' => [
                    'url' => 'https://idp.example/slo',
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'x509cert' => $this->idpCert,
            ],
        ]);
    }

    /** Seeds the parse result into the exact cache key the client reads, so no network fetch happens. */
    private function seedIdpMetadata(array $parsed): void
    {
        Cache::put('trusted_attestation.saml.idp-metadata.'.sha1(self::IDP_METADATA_URL), $parsed, 3600);
    }

    /** @return array{0: string, 1: string} the certificate body and the private key, both PEM-stripped bodies */
    private static function selfSignedCertificate(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'test'], $key);
        $cert = openssl_csr_sign($csr, null, $key, 365);

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);

        return [$certPem, $keyPem];
    }

    private function withSpKeypair(): void
    {
        $dir = sys_get_temp_dir().'/ata-saml-'.bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir.'/sp.crt', $this->spCert);
        file_put_contents($dir.'/sp.key', $this->spKey);

        config([
            'trusted_attestation.iam.saml.sp_certificate_path' => $dir.'/sp.crt',
            'trusted_attestation.iam.saml.sp_private_key_path' => $dir.'/sp.key',
        ]);
    }

    // -- NameID format ------------------------------------------------------------------------------

    #[Test]
    public function the_unspecified_name_id_format_is_requested_and_published_never_a_persistent_one(): void
    {
        // A persistent NameID is a pseudonym the identity system mints at first sign-in, which no grant can
        // name in advance (decision record 2), so neither the AuthnRequest nor the metadata asks for one. Unspecified
        // lets the identity system send the NameID it is configured to send, as .NET and Java request.
        $unspecified = 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified';

        $metadata = (new SamlClient)->metadata();
        self::assertStringContainsString('<md:NameIDFormat>'.$unspecified.'</md:NameIDFormat>', $metadata);
        self::assertStringNotContainsString('persistent', $metadata);

        $loginUrl = (new SamlClient)->auth()->login('https://ata.example/me', [], false, false, true);
        parse_str((string) parse_url((string) $loginUrl, PHP_URL_QUERY), $params);
        $authnRequest = (string) gzinflate((string) base64_decode((string) $params['SAMLRequest'], true));
        self::assertStringContainsString('Format="'.$unspecified.'"', $authnRequest);
        self::assertStringNotContainsString('persistent', $authnRequest);
    }

    // -- metadata ------------------------------------------------------------------------------------

    #[Test]
    public function the_generated_sp_metadata_is_well_formed_and_carries_the_acs_endpoint(): void
    {
        // This is what an IdP administrator registers. It is generated from the running settings rather
        // than written by hand, so a member's assertions are posted to exactly the ACS URL this app
        // serves. A malformed document, or one naming the wrong ACS, means no login can complete.
        $xml = (new SamlClient)->metadata();

        $document = new \DOMDocument;
        self::assertTrue($document->loadXML($xml), 'the SP metadata was not well-formed XML');

        $document->documentElement->setAttribute('xmlns:md', 'urn:oasis:names:tc:SAML:2.0:metadata');
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('md', 'urn:oasis:names:tc:SAML:2.0:metadata');

        $entityId = $document->documentElement->getAttribute('entityID');
        // The default entity id is this app's own metadata URL, the convention most IdPs expect.
        self::assertSame(self::APP_URL.'/auth/saml/metadata', $entityId);

        $acs = $xpath->query('//md:AssertionConsumerService')->item(0);
        self::assertNotNull($acs, 'the metadata declared no assertion consumer service');
        self::assertSame(self::APP_URL.'/auth/saml/acs', $acs->getAttribute('Location'));
    }

    #[Test]
    public function a_configured_entity_id_overrides_the_default(): void
    {
        // An org that registered this SP under a chosen identifier keeps it; the default only applies
        // when none is configured.
        config(['trusted_attestation.iam.saml.entity_id' => 'urn:acme:ata']);

        $xml = (new SamlClient)->metadata();

        $document = new \DOMDocument;
        self::assertTrue($document->loadXML($xml));
        self::assertSame('urn:acme:ata', $document->documentElement->getAttribute('entityID'));
    }

    #[Test]
    public function the_metadata_advertises_the_sp_signing_certificate_when_one_is_configured(): void
    {
        // With a keypair the SP publishes its certificate so the IdP can verify the AuthnRequests and
        // logout messages it signs. This exercises the readFile present-file branch through to the XML.
        $this->withSpKeypair();

        $xml = (new SamlClient)->metadata();

        self::assertStringContainsString('<ds:X509Certificate>', $xml);
    }

    #[Test]
    public function the_sp_metadata_is_generated_without_the_idp_being_reachable(): void
    {
        // The SP's own metadata is a pure function of the SP's configuration and must not depend on the
        // IdP. An administrator fetches it to register the SP, routinely before the IdP knows anything
        // about us, and it is served over HTTP where a slow or down IdP must not turn it into a 500. Here
        // the IdP is not merely unseeded but unconfigured -- auth() would refuse outright -- yet the SP
        // metadata is still produced. If metadata() ever reached for the IdP again, this configuration
        // would make it throw rather than return XML.
        Cache::flush();
        config(['trusted_attestation.iam.saml.idp_metadata_url' => '']);

        $xml = (new SamlClient)->metadata();

        $document = new \DOMDocument;
        self::assertTrue($document->loadXML($xml), 'the SP metadata was not well-formed XML');
        self::assertSame(
            self::APP_URL.'/auth/saml/metadata',
            $document->documentElement->getAttribute('entityID')
        );

        // And building auth() in this same state does fail, so the test above is proving decoupling rather
        // than passing because the IdP happened to be usable.
        $this->expectException(ConfigurationException::class);
        (new SamlClient)->auth();
    }

    // -- auth() and the IdP half ---------------------------------------------------------------------

    #[Test]
    public function auth_is_built_with_the_idp_read_from_its_published_metadata(): void
    {
        // The IdP endpoints are never configured by hand; they come from the parsed metadata. A regression
        // that dropped them would send members' AuthnRequests nowhere.
        $auth = (new SamlClient)->auth();

        $idp = $auth->getSettings()->getIdPData();
        self::assertSame(self::IDP_ENTITY_ID, $idp['entityId']);
        self::assertSame('https://idp.example/sso', $idp['singleSignOnService']['url']);
    }

    #[Test]
    public function the_idp_metadata_is_fetched_through_the_metadata_source(): void
    {
        // With nothing cached, the metadata comes from IdpMetadataSource, the one place that fetches it with
        // the server's certificate verified (IdpMetadataSourceTest pins that). A client that fetched it any
        // other way would bypass the check.
        Cache::flush();

        $source = new class($this->idpCert) extends IdpMetadataSource
        {
            /** @var list<string> */
            public array $fetched = [];

            public function __construct(private readonly string $certificate) {}

            public function fetch(string $url): array
            {
                $this->fetched[] = $url;

                return ['idp' => [
                    'entityId' => 'https://idp.example/saml',
                    'singleSignOnService' => [
                        'url' => 'https://idp.example/sso',
                        'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                    ],
                    'x509cert' => $this->certificate,
                ]];
            }
        };

        $auth = (new SamlClient($source))->auth();

        self::assertSame([self::IDP_METADATA_URL], $source->fetched);
        self::assertSame(self::IDP_ENTITY_ID, $auth->getSettings()->getIdPData()['entityId']);
    }

    #[Test]
    public function the_sp_signs_its_requests_exactly_when_it_holds_a_key(): void
    {
        // authnRequestsSigned tracks the presence of a private key: without one there is nothing to sign
        // with, and claiming to sign would produce AuthnRequests the IdP rejects. The security posture
        // itself is pinned in SamlSecuritySettingsTest; this checks it is wired to the real key material.
        $withoutKey = (new SamlClient)->auth()->getSettings()->getSecurityData();
        self::assertFalse($withoutKey['authnRequestsSigned']);
        // wantAssertionsSigned holds regardless of whether the SP has its own key.
        self::assertTrue($withoutKey['wantAssertionsSigned']);

        $this->withSpKeypair();
        $withKey = (new SamlClient)->auth()->getSettings()->getSecurityData();
        self::assertTrue($withKey['authnRequestsSigned']);
    }

    // -- refusals ------------------------------------------------------------------------------------

    #[Test]
    public function no_idp_metadata_url_configured_is_refused(): void
    {
        // A SAML deployment with no IdP metadata URL cannot build an IdP half at all, and says so rather
        // than constructing a half-settings that would fail confusingly at first login.
        config(['trusted_attestation.iam.saml.idp_metadata_url' => '']);

        $this->expectException(ConfigurationException::class);

        (new SamlClient)->auth();
    }

    #[Test]
    public function idp_metadata_missing_its_sso_endpoint_is_refused(): void
    {
        // Reachable and parseable but useless: metadata with an entity id but no single sign-on service
        // is not something a login can be started against.
        $this->seedIdpMetadata(['idp' => ['entityId' => self::IDP_ENTITY_ID]]);

        $this->expectException(IdentityProviderException::class);

        (new SamlClient)->auth();
    }

    #[Test]
    public function sp_key_material_that_cannot_be_read_is_refused(): void
    {
        // A configured certificate path that points at nothing is a deployment fault, refused loudly
        // rather than silently proceeding with no signing key.
        config(['trusted_attestation.iam.saml.sp_certificate_path' => '/no/such/ata-sp.crt']);

        $this->expectException(ConfigurationException::class);

        (new SamlClient)->auth();
    }

    // -- signature strength --------------------------------------------------------------------------

    #[Test]
    public function a_sha256_signed_response_has_no_weak_algorithms(): void
    {
        // The modern case every conformant IdP produces: nothing to refuse.
        $xml = self::signedResponse(
            'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
            'http://www.w3.org/2001/04/xmlenc#sha256'
        );

        self::assertSame([], (new SamlClient)->weakSignatureAlgorithms($xml));
    }

    #[Test]
    public function a_sha1_digest_under_a_sha256_signature_is_flagged(): void
    {
        // The WSO2 shape: a strong signature method over a weak reference digest. A SHA-256 signature is no
        // help when the content it signs is bound only by a collidable SHA-1 hash, so the digest is caught.
        // This is exactly the assertion the .NET stack refuses; PHP must refuse it too.
        $xml = self::signedResponse(
            'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
            'http://www.w3.org/2000/09/xmldsig#sha1'
        );

        self::assertSame(
            ['http://www.w3.org/2000/09/xmldsig#sha1'],
            (new SamlClient)->weakSignatureAlgorithms($xml)
        );
    }

    #[Test]
    public function a_sha1_signature_method_is_flagged(): void
    {
        // The other weak half: an RSA-SHA1 signature, regardless of the digest.
        $xml = self::signedResponse(
            'http://www.w3.org/2000/09/xmldsig#rsa-sha1',
            'http://www.w3.org/2001/04/xmlenc#sha256'
        );

        self::assertSame(
            ['http://www.w3.org/2000/09/xmldsig#rsa-sha1'],
            (new SamlClient)->weakSignatureAlgorithms($xml)
        );
    }

    #[Test]
    public function an_unsigned_or_unparseable_response_reports_no_weak_algorithms(): void
    {
        // No signature means no algorithms to judge here (an unsigned assertion is refused elsewhere by
        // wantAssertionsSigned), and a response that will not parse has already failed the signature check.
        $client = new SamlClient;
        self::assertSame([], $client->weakSignatureAlgorithms('<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"/>'));
        self::assertSame([], $client->weakSignatureAlgorithms('not xml at all'));
        self::assertSame([], $client->weakSignatureAlgorithms(''));
    }

    /** A minimal SAML response carrying one XML signature with the given signature and digest algorithms. */
    private static function signedResponse(string $signatureMethod, string $digestMethod): string
    {
        return <<<XML
            <samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"
                            xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion">
              <saml:Assertion>
                <ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#">
                  <ds:SignedInfo>
                    <ds:SignatureMethod Algorithm="{$signatureMethod}"/>
                    <ds:Reference>
                      <ds:DigestMethod Algorithm="{$digestMethod}"/>
                      <ds:DigestValue>x</ds:DigestValue>
                    </ds:Reference>
                  </ds:SignedInfo>
                  <ds:SignatureValue>x</ds:SignatureValue>
                </ds:Signature>
              </saml:Assertion>
            </samlp:Response>
            XML;
    }
}
