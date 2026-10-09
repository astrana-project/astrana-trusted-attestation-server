<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\IdentityProviderException;
use App\Services\Saml\IdpMetadataSource;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How the identity provider's metadata is fetched and read. The metadata carries the certificate every
 * assertion is checked against, so whoever can answer the fetch decides which signatures are trusted. The
 * fetch must therefore verify the server's TLS certificate, which is pinned here, along with what is read
 * from the document: the endpoints and certificate OneLogin's parser finds, and whether the identity provider
 * asks for signed authentication requests, which the parser does not report.
 */
final class IdpMetadataSourceTest extends TestCase
{
    private const URL = 'https://idp.example/metadata';

    private const REDIRECT = 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect';

    private const POST = 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST';

    /** The identity provider's metadata, with the given attributes added to its IDPSSODescriptor. */
    private static function metadata(string $descriptorAttributes = ''): string
    {
        $post = self::POST;
        $redirect = self::REDIRECT;

        return <<<XML
            <md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="https://idp.example/saml">
              <md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol"{$descriptorAttributes}>
                <md:SingleSignOnService Binding="{$post}" Location="https://idp.example/sso/post"/>
                <md:SingleSignOnService Binding="{$redirect}" Location="https://idp.example/sso/redirect"/>
              </md:IDPSSODescriptor>
            </md:EntityDescriptor>
            XML;
    }

    /** A metadata source whose HTTP client answers every request with the given body and status. */
    private static function answering(string $body, int $status = 200): IdpMetadataSource
    {
        $http = new Factory;
        $http->fake(['*' => Factory::response($body, $status)]);

        return new IdpMetadataSource($http);
    }

    #[Test]
    public function the_metadata_is_fetched_with_the_servers_certificate_verified(): void
    {
        $seen = [];
        $http = new Factory;
        $http->fake(function (Request $request, array $options) use (&$seen) {
            $seen = ['url' => $request->url(), 'verify' => $options['verify'] ?? null];

            return Factory::response(self::metadata());
        });

        (new IdpMetadataSource($http))->fetch(self::URL);

        self::assertSame(['url' => self::URL, 'verify' => true], $seen);
    }

    #[Test]
    public function the_entity_id_and_the_redirect_sign_in_endpoint_are_read_from_the_metadata(): void
    {
        // OneLogin's parser reads the document, preferring the HTTP-Redirect binding for sign-in, so the POST
        // endpoint listed first is passed over.
        $idp = self::answering(self::metadata())->fetch(self::URL)['idp'];

        self::assertSame('https://idp.example/saml', $idp['entityId']);
        self::assertSame(['url' => 'https://idp.example/sso/redirect', 'binding' => self::REDIRECT], $idp['singleSignOnService']);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function signedRequestPreferences(): iterable
    {
        yield 'true' => [' WantAuthnRequestsSigned="true"', true];
        yield 'one' => [' WantAuthnRequestsSigned="1"', true];
        yield 'false' => [' WantAuthnRequestsSigned="false"', false];
        yield 'zero' => [' WantAuthnRequestsSigned="0"', false];
        yield 'not stated' => ['', false];
    }

    #[Test]
    #[DataProvider('signedRequestPreferences')]
    public function whether_the_identity_provider_wants_signed_requests_is_read_from_its_descriptor(string $attribute, bool $wanted): void
    {
        $idp = self::answering(self::metadata($attribute))->fetch(self::URL)['idp'];

        self::assertSame($wanted, $idp[IdpMetadataSource::WANTS_SIGNED_REQUESTS]);
    }

    #[Test]
    public function metadata_that_describes_no_identity_provider_yields_nothing_to_sign_in_against(): void
    {
        $parsed = self::answering('<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="x"/>')
            ->fetch(self::URL);

        self::assertArrayNotHasKey('idp', $parsed);
    }

    #[Test]
    public function a_metadata_address_that_does_not_answer_successfully_is_refused(): void
    {
        $this->expectException(IdentityProviderException::class);

        self::answering('', 503)->fetch(self::URL);
    }
}
