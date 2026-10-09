<?php

declare(strict_types=1);

namespace App\Services\Saml;

use App\Exceptions\ConfigurationException;
use App\Exceptions\IdentityProviderException;
use App\Support\ApplicationPath;
use Illuminate\Support\Facades\Cache;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\Settings;

/**
 * A generic SAML 2.0 service provider.
 *
 * Written against the protocol rather than any vendor's integration, because decision record 2 in docs/adr requires
 * Astrana Trusted Attestation to work with any standards-compliant provider, such as Active Directory
 * Federation Services, PingFederate, Entra ID or Keycloak, without custom code. Everything about the IdP is read from its published metadata, exactly as the OIDC
 * path reads everything from the discovery document.
 */
final class SamlClient
{
    private const CACHE_SECONDS = 3600;

    /** @param IdpMetadataSource $metadataSource where the identity provider's metadata comes from, replaced only in tests */
    public function __construct(private readonly IdpMetadataSource $metadataSource = new IdpMetadataSource) {}

    public function auth(): Auth
    {
        return new Auth($this->settings());
    }

    /**
     * The Auth a sign-in starts from, refused when the identity provider's metadata asks for signed
     * authentication requests and this service provider has no key to sign them with. Such a request would
     * only be refused by the identity provider, so it is never sent. The .NET (Sustainsys) and Java (Spring
     * Security) implementations refuse to build it too, and in all three the member gets HTTP 500 - Internal
     * Server Error and the cause goes to the log.
     */
    public function signInAuth(): Auth
    {
        $auth = $this->auth();
        $settings = $auth->getSettings();

        $wantsSigned = ($settings->getIdPData()[IdpMetadataSource::WANTS_SIGNED_REQUESTS] ?? false) === true;
        if ($wantsSigned && empty($settings->getSecurityData()['authnRequestsSigned'])) {
            throw new ConfigurationException(
                'The identity provider asks for signed authentication requests and no SAML signing key is configured, '
                .'so the sign-in was not started. Set TRUSTED_ATTESTATION_SAML_SP_CERTIFICATE and '
                .'TRUSTED_ATTESTATION_SAML_SP_PRIVATE_KEY.'
            );
        }

        return $auth;
    }

    /**
     * The SP's own metadata, which is what an IdP administrator registers. Generated from the same
     * settings the app runs on, so the two cannot disagree.
     */
    public function metadata(): string
    {
        // The SP's own metadata is a pure function of this service provider's configuration -- its entity
        // id, endpoints, NameID format and signing certificate -- and must not depend on the IdP being
        // reachable. An administrator fetches this to register the SP, routinely before the IdP has been
        // told anything about us; the .NET and Java implementations both render it from static
        // configuration with no per-request call to the IdP. Going through the full Auth pulled in
        // identityProvider(), which parses the live IdP metadata over the network, so a slow, unreachable
        // or not-yet-configured IdP turned this endpoint into a 500. A Settings built with
        // spValidationOnly = true validates and renders only the SP half, so it never touches the IdP.
        $settings = new Settings($this->serviceProviderSettings(), true);
        $metadata = $settings->getSPMetadata();

        $errors = $settings->validateMetadata($metadata);
        if ($errors !== []) {
            throw new ConfigurationException('Generated SP metadata is invalid: '.implode(', ', $errors));
        }

        return $metadata;
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        // The full runtime settings are the SP half plus the IdP half parsed from its published metadata.
        // The union operator keeps every SP key and adds the 'idp' key, which serviceProviderSettings()
        // deliberately omits.
        return $this->serviceProviderSettings() + ['idp' => $this->identityProvider()];
    }

    /**
     * The SP half of the settings: everything describing this service provider itself, with no dependency
     * on the IdP. Enough on its own to validate the SP and publish its metadata.
     *
     * @return array<string, mixed>
     */
    private function serviceProviderSettings(): array
    {
        $config = config('trusted_attestation.iam.saml');
        $baseUrl = rtrim((string) config('app.url'), '/');

        $certificate = $this->readFile($config['sp_certificate_path'] ?? null);
        $privateKey = $this->readFile($config['sp_private_key_path'] ?? null);

        return [
            // Rejects anything that does not validate, rather than logging and continuing. An assertion
            // that fails a check is not a login.
            'strict' => true,
            'debug' => false,
            'baseurl' => $baseUrl,

            'sp' => [
                'entityId' => $config['entity_id'] ?: $baseUrl.'/auth/saml/metadata',
                'assertionConsumerService' => [
                    'url' => $baseUrl.'/auth/saml/acs',
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
                'singleLogoutService' => [
                    'url' => $baseUrl.'/auth/saml/logout',
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],

                // Unspecified, so the identity system sends the NameID it is configured to send. Asking for
                // a persistent one would have it mint a pseudonym at first sign-in, which no grant can name
                // in advance (decision record 2). The same format is requested in the AuthnRequest's NameIDPolicy
                // and published in this service provider's metadata.
                'NameIDFormat' => Constants::NAMEID_UNSPECIFIED,

                'x509cert' => $certificate ?? '',
                'privateKey' => $privateKey ?? '',
            ],

            'security' => $this->securitySettings($privateKey !== null),
        ];
    }

    /**
     * The SP's security posture: what it signs, what it requires signed, and that it refuses anything
     * that does not validate. Separated from the rest of the settings, and taking no IdP metadata, so it
     * can be asserted on its own -- these are the choices that decide whether an assertion is trusted,
     * and a regression in any of them would still let every happy-path login through.
     *
     * @return array<string, mixed>
     */
    public function securitySettings(bool $hasSigningKey): array
    {
        return [
            // Sign what we send, and require the IdP to sign what it sends back. Without the latter an
            // assertion is just a POST body anyone could have written.
            'authnRequestsSigned' => $hasSigningKey,
            'logoutRequestSigned' => $hasSigningKey,
            'logoutResponseSigned' => $hasSigningKey,
            'wantAssertionsSigned' => true,
            'wantMessagesSigned' => false,
            'wantNameId' => true,
            'requestedAuthnContext' => false,
            'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
            'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
            'rejectUnsolicitedResponsesWithInResponseTo' => true,

            // Not settable here, but recorded because it is part of the posture: how far an assertion's
            // timestamps may be out before it is refused is OneLogin's Constants::ALLOWED_CLOCK_DRIFT,
            // fixed at 180 seconds. That is the value the .NET and Java implementations are explicitly set
            // to as well, so the same assertion is accepted or refused whichever stack receives it -- the
            // low end of the three-to-five-minute window the SAML ecosystem uses, and deliberately looser
            // than the 60s used for OIDC ID tokens. SamlSecuritySettingsTest pins the library constant so a
            // dependency update that moved it would be caught rather than silently changing the tolerance.
        ];
    }

    /**
     * The signature and digest algorithms in a received SAML response that are weaker than SHA-256.
     *
     * OneLogin validates that the signature is cryptographically sound but not that it was made with a
     * modern algorithm, so on its own it would accept an assertion signed with SHA-1 -- long broken for
     * collision resistance. The .NET implementation (Sustainsys) refuses any incoming signature or digest
     * weaker than SHA-256 by default, and the whole point is that the same assertion is accepted or refused
     * whichever stack receives it; this closes the gap so PHP and Java refuse it too. WSO2, for one, signs
     * with a SHA-1 digest under some configurations.
     *
     * Both halves of an XML signature are checked -- the SignatureMethod (how the SignedInfo is signed) and
     * every Reference's DigestMethod (how the signed content is hashed) -- because a signature is only as
     * strong as its weakest half: a SHA-256 signature over a SHA-1 digest is still forgeable through a
     * digest collision, which is exactly the shape WSO2 emits (RSA-SHA256 signature, SHA-1 digest).
     *
     * @return list<string> the distinct weak algorithm URIs found, empty when every one is SHA-256 or better
     */
    public function weakSignatureAlgorithms(string $responseXml): array
    {
        $document = new \DOMDocument;
        // A response that will not parse carries no verifiable signature; the signature check has already
        // failed it, so report nothing extra here rather than second-guessing the parser.
        if (trim($responseXml) === '' || ! @$document->loadXML($responseXml)) {
            return [];
        }

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        $weak = [];
        foreach ($xpath->query('//ds:SignatureMethod/@Algorithm | //ds:DigestMethod/@Algorithm') as $attribute) {
            $algorithm = (string) $attribute->nodeValue;
            if (! self::isStrongAlgorithm($algorithm)) {
                $weak[] = $algorithm;
            }
        }

        return array_values(array_unique($weak));
    }

    /**
     * Whether a signature or digest algorithm URI names SHA-256, SHA-384 or SHA-512. SHA-1 (…#sha1, …#rsa-sha1)
     * and MD5 do not, and neither does an empty one.
     */
    public static function isStrongAlgorithm(string $algorithm): bool
    {
        return preg_match('/sha(256|384|512)$/i', $algorithm) === 1;
    }

    /**
     * The certificates the IdP signs with, as its metadata publishes them: the single x509cert, or every
     * signing entry when the metadata lists more than one key, as it does while a key is being rotated. What
     * PostBindingMessage checks a posted logout message against.
     *
     * @return list<string>
     */
    public static function signingCertificates(Settings $settings): array
    {
        $idp = $settings->getIdPData();

        $certificates = $idp['x509certMulti']['signing'] ?? [];
        if (isset($idp['x509cert']) && is_string($idp['x509cert']) && $idp['x509cert'] !== '') {
            $certificates[] = $idp['x509cert'];
        }

        return array_values(array_filter($certificates, static fn ($certificate): bool => is_string($certificate) && $certificate !== ''));
    }

    /**
     * The IdP half of the settings, parsed from its published metadata and cached. Endpoints, bindings
     * and the certificate assertions are verified against all come from there; none of it is configured
     * by hand. Because that certificate decides which assertions are trusted, the metadata is fetched
     * through IdpMetadataSource, which verifies the server's TLS certificate. It also records whether the
     * IdP wants signed authentication requests, which signInAuth() reads.
     *
     * @return array<string, mixed>
     */
    private function identityProvider(): array
    {
        $config = config('trusted_attestation.iam.saml');
        $metadataUrl = (string) ($config['idp_metadata_url'] ?? '');

        if ($metadataUrl === '') {
            throw new ConfigurationException('No IdP metadata URL configured. Set TRUSTED_ATTESTATION_SAML_IDP_METADATA_URL.');
        }

        // Keyed by the metadata URL for the same reason the OIDC client keys by issuer: pointing the app
        // at a different IdP must not be served the previous one's metadata.
        $parsed = Cache::remember(
            self::metadataCacheKey($metadataUrl),
            self::CACHE_SECONDS,
            fn (): array => $this->metadataSource->fetch($metadataUrl)
        );

        if (! isset($parsed['idp']['entityId'], $parsed['idp']['singleSignOnService'])) {
            throw new IdentityProviderException("The IdP metadata at {$metadataUrl} is not usable.");
        }

        return $parsed['idp'];
    }

    /**
     * Where the parsed metadata from the given address is cached. The version segment names the shape of
     * the cached entry, which includes whether the IdP wants signed authentication requests. It changes
     * whenever that shape does, so an entry of another shape is never read, and an entry that lacks the
     * signing flag cannot let an unsigned request through until it expires.
     */
    public static function metadataCacheKey(string $metadataUrl): string
    {
        return 'trusted_attestation.saml.idp-metadata.v2.'.hash('sha256', $metadataUrl);
    }

    private function readFile(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $absolute = ApplicationPath::resolve($path, base_path());
        $contents = @file_get_contents($absolute);

        if ($contents === false) {
            throw new ConfigurationException("SAML key material \"{$absolute}\" could not be read.");
        }

        return $contents;
    }
}
