<?php

declare(strict_types=1);

namespace Tests\Support;

use DOMDocument;
use DOMElement;
use OneLogin\Saml2\Utils;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * Builds the SAML single logout messages an identity provider sends, and the malformed ones an attacker
 * might, for the tests of the single logout service. The identity provider's key and certificate come from
 * selfSignedCertificate(), and the messages are signed with the library's own Utils::addSign, which
 * envelopes a signature on the root element as a real identity provider does over the HTTP-POST binding.
 */
trait BuildsSamlLogoutMessages
{
    private const IDP_ENTITY_ID = 'https://idp.example/saml';

    /**
     * A fresh RSA key and self-signed certificate, PEM.
     *
     * @return array{0: string, 1: string} the certificate, then the private key
     */
    private static function selfSignedCertificate(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'test'], $key);
        $cert = openssl_csr_sign($csr, null, $key, 365);

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);

        return [$certPem, $keyPem];
    }

    private static function logoutRequestXml(
        ?string $destination = null,
        string $nameId = 'the-name-id',
        string $issuer = self::IDP_ENTITY_ID,
        ?int $notOnOrAfter = null,
    ): string {
        $destinationAttribute = $destination === null ? '' : ' Destination="'.$destination.'"';
        $notOnOrAfterAttribute = $notOnOrAfter === null ? '' : ' NotOnOrAfter="'.gmdate('Y-m-d\TH:i:s\Z', $notOnOrAfter).'"';

        return '<samlp:LogoutRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" '
            .'xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_request-1" Version="2.0" '
            .'IssueInstant="'.gmdate('Y-m-d\TH:i:s\Z').'"'.$destinationAttribute.$notOnOrAfterAttribute.'>'
            .'<saml:Issuer>'.$issuer.'</saml:Issuer>'
            .'<saml:NameID>'.$nameId.'</saml:NameID>'
            .'</samlp:LogoutRequest>';
    }

    private static function logoutResponseXml(string $inResponseTo, ?string $destination = null): string
    {
        $destinationAttribute = $destination === null ? '' : ' Destination="'.$destination.'"';

        return '<samlp:LogoutResponse xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" '
            .'xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_response-1" Version="2.0" '
            .'IssueInstant="'.gmdate('Y-m-d\TH:i:s\Z').'" InResponseTo="'.$inResponseTo.'"'.$destinationAttribute.'>'
            .'<saml:Issuer>'.self::IDP_ENTITY_ID.'</saml:Issuer>'
            .'<samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>'
            .'</samlp:LogoutResponse>';
    }

    /** The message with an enveloped signature on its root element, as the library signs one. */
    private static function enveloped(
        string $xml,
        string $key,
        string $certificate,
        string $signatureAlgorithm = XMLSecurityKey::RSA_SHA256,
        string $digestAlgorithm = XMLSecurityDSig::SHA256,
    ): string {
        return Utils::addSign($xml, $key, $certificate, $signatureAlgorithm, $digestAlgorithm);
    }

    /** As the HTTP-POST binding carries a message: the plain XML, base64-encoded. */
    private static function posted(string $xml): string
    {
        return base64_encode($xml);
    }

    /**
     * A signed LogoutRequest nested, as an extension, inside an unsigned LogoutRequest for another NameID.
     * The one signature in the document verifies, but it is not the root's.
     */
    private static function wrappedInUnsignedLogoutRequest(string $signedXml): string
    {
        $inner = self::withoutXmlDeclaration($signedXml);

        return '<samlp:LogoutRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" '
            .'xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_outer" Version="2.0" '
            .'IssueInstant="'.gmdate('Y-m-d\TH:i:s\Z').'">'
            .'<saml:Issuer>'.self::IDP_ENTITY_ID.'</saml:Issuer>'
            .'<samlp:Extensions>'.$inner.'</samlp:Extensions>'
            .'<saml:NameID>another-name-id</saml:NameID>'
            .'</samlp:LogoutRequest>';
    }

    /**
     * A LogoutRequest whose root-level signature references, and so covers, only an element inside it. The
     * signature verifies, but the NameID and everything else on the root are outside what was signed.
     */
    private static function signedOverAnInnerElement(string $xml, string $key, string $certificate): string
    {
        $document = new DOMDocument;
        $document->loadXML($xml);
        $root = $document->documentElement;

        $extensions = $document->createElementNS('urn:oasis:names:tc:SAML:2.0:protocol', 'samlp:Extensions');
        $inner = $document->createElementNS('urn:example:test', 'test:Note');
        $inner->setAttribute('ID', '_inner');
        $extensions->appendChild($inner);
        $root->insertBefore($extensions, $root->firstChild->nextSibling);

        $signer = new XMLSecurityDSig;
        $signer->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $signer->addReferenceList(
            [$inner],
            XMLSecurityDSig::SHA256,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N],
            ['id_name' => 'ID', 'overwrite' => false],
        );
        $signingKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $signingKey->loadKey($key);
        $signer->sign($signingKey);
        $signer->add509Cert($certificate);
        $signer->insertSignature($root, $extensions);

        return $document->saveXML();
    }

    /** The signed message with a second element carrying the root's ID, so the signature's reference is ambiguous. */
    private static function withDuplicateId(string $signedXml): string
    {
        return self::edited($signedXml, static function (DOMDocument $document, DOMElement $root): void {
            $extensions = $document->createElementNS('urn:oasis:names:tc:SAML:2.0:protocol', 'samlp:Extensions');
            $twin = $document->createElementNS('urn:example:test', 'test:Note');
            $twin->setAttribute('ID', $root->getAttribute('ID'));
            $extensions->appendChild($twin);
            $root->appendChild($extensions);
        });
    }

    /** The signed message with its signature duplicated, so the document carries two. */
    private static function withTwoSignatures(string $signedXml): string
    {
        return self::edited($signedXml, static function (DOMDocument $document, DOMElement $root): void {
            $signature = $document->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature')->item(0);
            $root->appendChild($signature->cloneNode(true));
        });
    }

    /** The message with a document type declaration in front of it. */
    private static function withDoctype(string $xml): string
    {
        return '<!DOCTYPE LogoutRequest>'.self::withoutXmlDeclaration($xml);
    }

    private static function withoutXmlDeclaration(string $xml): string
    {
        return (string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $xml);
    }

    /** @param  callable(DOMDocument, DOMElement): void  $edit */
    private static function edited(string $xml, callable $edit): string
    {
        $document = new DOMDocument;
        $document->loadXML($xml);
        $edit($document, $document->documentElement);

        return $document->saveXML();
    }
}
