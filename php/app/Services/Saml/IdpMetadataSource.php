<?php

declare(strict_types=1);

namespace App\Services\Saml;

use App\Exceptions\IdentityProviderException;
use DOMDocument;
use Illuminate\Http\Client\Factory;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\IdPMetadataParser;
use OneLogin\Saml2\Utils;

/**
 * Fetches and reads the identity provider's published metadata, with the server's TLS certificate verified.
 *
 * The metadata carries the certificate every assertion is checked against, so whoever can answer this
 * fetch decides which signatures are trusted. The request always verifies the certificate. A private
 * certificate authority is trusted through curl.cainfo in php.ini, as docs/installation/php.md describes,
 * and there is no setting that turns the check off.
 *
 * The document is fetched here rather than by OneLogin's own remote parser, because the parser does not
 * report whether the identity provider wants signed authentication requests, and that is read from the
 * same document. Everything else is OneLogin's parser.
 *
 * Not final, so that a test can replace fetch() with metadata of its own.
 */
class IdpMetadataSource
{
    /**
     * The key, under the parsed 'idp' settings, saying whether the identity provider's metadata asks for
     * signed authentication requests (WantAuthnRequestsSigned on its IDPSSODescriptor). OneLogin ignores it.
     */
    public const WANTS_SIGNED_REQUESTS = 'wantAuthnRequestsSigned';

    private const TIMEOUT_SECONDS = 10;

    /** @param Factory $http the HTTP client, replaced only in tests */
    public function __construct(private readonly Factory $http = new Factory) {}

    /**
     * @return array<string, mixed>
     */
    public function fetch(string $url): array
    {
        $response = $this->http->withOptions(['verify' => true])->timeout(self::TIMEOUT_SECONDS)->get($url);

        if (! $response->successful()) {
            throw new IdentityProviderException("Could not read the IdP metadata at {$url}.");
        }

        $xml = $response->body();

        // No particular entity, no preferred NameID format, and the HTTP-Redirect binding for sign-in and
        // sign-out, which are the parser's own defaults.
        $parsed = IdPMetadataParser::parseXML($xml, null, null, Constants::BINDING_HTTP_REDIRECT, Constants::BINDING_HTTP_REDIRECT);

        if (isset($parsed['idp'])) {
            $parsed['idp'][self::WANTS_SIGNED_REQUESTS] = self::wantsSignedRequests($xml);
        }

        return $parsed;
    }

    /**
     * Whether the first IDPSSODescriptor, the one the parser reads, carries WantAuthnRequestsSigned as true.
     * An XML Schema boolean, so "1" counts as true as well. The parser has already loaded this document, so
     * it is well formed.
     */
    private static function wantsSignedRequests(string $xml): bool
    {
        $document = Utils::loadXML(new DOMDocument, $xml);
        $descriptor = Utils::query($document, '//md:EntityDescriptor/md:IDPSSODescriptor')->item(0);
        $value = $descriptor instanceof \DOMElement ? trim($descriptor->getAttribute('WantAuthnRequestsSigned')) : '';

        return in_array($value, ['true', '1'], true);
    }
}
