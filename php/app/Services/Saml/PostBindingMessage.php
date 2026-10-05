<?php

declare(strict_types=1);

namespace App\Services\Saml;

use DOMDocument;
use DOMElement;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\Utils;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * A single logout message the identity provider delivered over the HTTP-POST binding, with its signature
 * checked against the identity provider's certificate.
 *
 * Over the HTTP-Redirect binding the signature is detached, made over the query string, and the library
 * checks it. Over the HTTP-POST binding the signature is enveloped in the XML, and the library's
 * LogoutRequest and LogoutResponse never look at it. So the enveloped signature is verified here, before
 * the library sees the message, and only a message that verifies goes on to the library's other checks.
 *
 * Verification refuses, in order, a message that is not base64, that does not parse, that carries a
 * DOCTYPE, whose root is not a LogoutRequest or LogoutResponse, that carries any number of signatures other
 * than one, whose one signature is not a direct child of the root, whose root has no ID or an ID another
 * element shares, whose signature references anything but the root, whose signature or digest algorithm is
 * weaker than SHA-256, and whose signature does not verify against the identity provider's certificates.
 * The order matters. The structural checks are the defence against signature wrapping, where a signed
 * element is placed inside, or next to, unsigned content, so they run before any cryptography, and the
 * cryptographic check is then pointed at the one signature the structure allows.
 */
final class PostBindingMessage
{
    public const LOGOUT_REQUEST = 'LogoutRequest';

    public const LOGOUT_RESPONSE = 'LogoutResponse';

    /**
     * The algorithm floor from decision record 23 in docs/adr. RSA with SHA-256 or stronger, and the matching
     * digests. Anything else, SHA-1 and MD5 included, is refused before the signature is checked.
     */
    private const SIGNATURE_ALGORITHMS = [
        XMLSecurityKey::RSA_SHA256,
        XMLSecurityKey::RSA_SHA384,
        XMLSecurityKey::RSA_SHA512,
    ];

    private const DIGEST_ALGORITHMS = [
        XMLSecurityDSig::SHA256,
        XMLSecurityDSig::SHA384,
        XMLSecurityDSig::SHA512,
    ];

    /**
     * @param  string|null  $kind  LOGOUT_REQUEST or LOGOUT_RESPONSE once the root element is known, null before
     * @param  string|null  $reason  why the message was refused, in plain English for the log, null when it verified
     */
    private function __construct(
        public readonly ?string $kind,
        public readonly ?string $reason,
    ) {}

    /** Whether the message verified. Only then may it be acted on. */
    public function verified(): bool
    {
        return $this->reason === null;
    }

    /**
     * Verifies a posted message. The HTTP-POST binding carries the plain XML base64-encoded, not deflated.
     *
     * @param  string  $encoded  the SAMLRequest or SAMLResponse form field as posted
     * @param  list<string>  $certificates  the identity provider's signing certificates, PEM, from its metadata
     */
    public static function verify(string $encoded, array $certificates): self
    {
        $xml = base64_decode($encoded, true);
        if ($xml === false || trim($xml) === '') {
            return self::refused(null, 'the message is not base64');
        }

        $document = self::parse($xml);
        if (is_string($document)) {
            return self::refused(null, $document);
        }

        $root = $document->documentElement;
        $kind = self::kindOf($root);
        if ($kind === null) {
            return self::refused(null, 'the message is not a LogoutRequest or a LogoutResponse');
        }

        $structure = self::checkStructure($document, $root);
        if ($structure !== null) {
            return self::refused($kind, $structure);
        }

        $algorithms = self::checkAlgorithms($document);
        if ($algorithms !== null) {
            return self::refused($kind, $algorithms);
        }

        try {
            // The xpath names the root's own signature, the only one the structure checks have allowed, so the
            // library verifies that one and nothing else. Every certificate the identity provider publishes
            // for signing is tried, as the library does for an assertion.
            $valid = Utils::validateSign($document, null, null, 'sha1', "/samlp:{$kind}/ds:Signature", $certificates);
        } catch (\Throwable $exception) {
            return self::refused($kind, 'the signature could not be checked: '.$exception->getMessage());
        }

        if (! $valid) {
            return self::refused($kind, "the signature does not verify against the identity provider's certificate");
        }

        return new self($kind, null);
    }

    private static function refused(?string $kind, string $reason): self
    {
        return new self($kind, $reason);
    }

    /**
     * Parses with the library's loader, which refuses a DOCTYPE and so any entity expansion. Parse errors are
     * kept out of the PHP error handler and reported as a reason instead.
     */
    private static function parse(string $xml): DOMDocument|string
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = Utils::loadXML(new DOMDocument, $xml);
        } catch (\Throwable $exception) {
            return 'the message was refused by the XML loader: '.$exception->getMessage();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $document instanceof DOMDocument || $document->documentElement === null) {
            return 'the message is not well-formed XML';
        }

        return $document;
    }

    private static function kindOf(DOMElement $root): ?string
    {
        if ($root->namespaceURI !== Constants::NS_SAMLP) {
            return null;
        }

        return in_array($root->localName, [self::LOGOUT_REQUEST, self::LOGOUT_RESPONSE], true)
            ? $root->localName
            : null;
    }

    /**
     * The signature-wrapping defence: one signature, on the root, referencing the root by an ID nothing else
     * in the document carries. Returns the reason for refusal, or null when the structure is sound.
     */
    private static function checkStructure(DOMDocument $document, DOMElement $root): ?string
    {
        $signatures = Utils::query($document, '//ds:Signature');
        if ($signatures->length === 0) {
            return 'the message is not signed';
        }
        if ($signatures->length > 1) {
            return "the message carries {$signatures->length} signatures where one is allowed";
        }

        $signature = $signatures->item(0);
        if (! $signature instanceof DOMElement || ! $root->isSameNode($signature->parentNode)) {
            return 'the signature is not on the root element';
        }

        $id = $root->getAttribute('ID');
        if ($id === '') {
            return 'the root element has no ID for the signature to reference';
        }
        if (self::elementsWithId($document, $id) !== 1) {
            return "the root element's ID \"{$id}\" is shared by another element";
        }

        $references = Utils::query($document, 'ds:SignedInfo/ds:Reference', $signature);
        if ($references->length !== 1) {
            return "the signature carries {$references->length} references where one is allowed";
        }

        $reference = $references->item(0);
        if (! $reference instanceof DOMElement || $reference->getAttribute('URI') !== '#'.$id) {
            return 'the signature does not reference the root element';
        }

        return null;
    }

    /** Counted by walking the document rather than by an xpath, so the ID's own characters cannot shape a query. */
    private static function elementsWithId(DOMDocument $document, string $id): int
    {
        $count = 0;
        foreach ($document->getElementsByTagName('*') as $element) {
            if ($element instanceof DOMElement && $element->getAttribute('ID') === $id) {
                $count++;
            }
        }

        return $count;
    }

    /** Returns the reason for refusal when any algorithm is below the floor, or null when all are at or above it. */
    private static function checkAlgorithms(DOMDocument $document): ?string
    {
        $signatureMethods = Utils::query($document, '//ds:SignatureMethod/@Algorithm');
        if ($signatureMethods->length !== 1) {
            return 'the signature does not name exactly one signature algorithm';
        }
        foreach ($signatureMethods as $attribute) {
            if (! in_array($attribute->nodeValue, self::SIGNATURE_ALGORITHMS, true)) {
                return "the signature algorithm {$attribute->nodeValue} is weaker than RSA with SHA-256";
            }
        }

        $digestMethods = Utils::query($document, '//ds:DigestMethod/@Algorithm');
        if ($digestMethods->length === 0) {
            return 'the signature names no digest algorithm';
        }
        foreach ($digestMethods as $attribute) {
            if (! in_array($attribute->nodeValue, self::DIGEST_ALGORITHMS, true)) {
                return "the digest algorithm {$attribute->nodeValue} is weaker than SHA-256";
            }
        }

        return null;
    }
}
