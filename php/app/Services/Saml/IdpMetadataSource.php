<?php

declare(strict_types=1);

namespace App\Services\Saml;

use OneLogin\Saml2\Constants;
use OneLogin\Saml2\IdPMetadataParser;

/**
 * Fetches and parses the identity provider's published metadata, with the server's TLS certificate
 * verified.
 *
 * The metadata carries the certificate every assertion is checked against, so whoever can answer this
 * fetch decides which signatures are trusted. OneLogin's parser skips certificate verification unless its
 * last argument says otherwise, so it is always passed true here. A private certificate authority is
 * trusted through curl.cainfo in php.ini, as docs/installation/php.md describes, and there is no setting
 * that turns the check off.
 *
 * Not final, so that a test can replace parseRemote() and see the arguments the library is given.
 */
class IdpMetadataSource
{
    /**
     * @return array<string, mixed>
     */
    public function fetch(string $url): array
    {
        // The arguments between the URL and the peer validation switch are the library's own defaults:
        // no particular entity, no preferred NameID format, and the HTTP-Redirect binding for sign-in and
        // sign-out.
        return $this->parseRemote(
            $url,
            null,
            null,
            Constants::BINDING_HTTP_REDIRECT,
            Constants::BINDING_HTTP_REDIRECT,
            true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseRemote(mixed ...$arguments): array
    {
        return IdPMetadataParser::parseRemoteXML(...$arguments);
    }
}
