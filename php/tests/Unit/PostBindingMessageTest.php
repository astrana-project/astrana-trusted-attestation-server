<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Saml\PostBindingMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use Tests\Support\BuildsSamlLogoutMessages;

/**
 * The verification of a single logout message posted over the HTTP-POST binding, on its own, without the
 * controller or the container. What is pinned is the rule: a message is accepted only when its one
 * signature sits on the root element, references that root by an ID nothing else shares, uses SHA-256 or
 * stronger throughout, and verifies against the identity provider's certificate. Every way of falling
 * short is refused with a reason that names it, and nothing cryptographic runs before the structure is
 * known to be sound.
 */
final class PostBindingMessageTest extends TestCase
{
    use BuildsSamlLogoutMessages;

    private string $idpCert;

    private string $idpKey;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->idpCert, $this->idpKey] = self::selfSignedCertificate();
    }

    private function verify(string $xml): PostBindingMessage
    {
        return PostBindingMessage::verify(self::posted($xml), [$this->idpCert]);
    }

    private function signed(string $xml): string
    {
        return self::enveloped($xml, $this->idpKey, $this->idpCert);
    }

    private function assertRefused(PostBindingMessage $message, string $reasonFragment): void
    {
        self::assertFalse($message->verified());
        self::assertNotNull($message->reason);
        self::assertStringContainsString($reasonFragment, $message->reason);
    }

    #[Test]
    public function a_logout_request_signed_by_the_identity_provider_verifies(): void
    {
        $message = $this->verify($this->signed(self::logoutRequestXml()));

        self::assertTrue($message->verified());
        self::assertSame(PostBindingMessage::LOGOUT_REQUEST, $message->kind);
    }

    #[Test]
    public function a_logout_response_signed_by_the_identity_provider_verifies(): void
    {
        $message = $this->verify($this->signed(self::logoutResponseXml('ONELOGIN_the-request')));

        self::assertTrue($message->verified());
        self::assertSame(PostBindingMessage::LOGOUT_RESPONSE, $message->kind);
    }

    #[Test]
    public function a_message_signed_with_any_of_the_published_certificates_verifies(): void
    {
        // An identity provider rotating its key publishes both. The message signed with either verifies.
        [$otherCert] = self::selfSignedCertificate();

        $message = PostBindingMessage::verify(self::posted($this->signed(self::logoutRequestXml())), [$otherCert, $this->idpCert]);

        self::assertTrue($message->verified());
    }

    #[Test]
    public function an_unsigned_message_is_refused(): void
    {
        $this->assertRefused($this->verify(self::logoutRequestXml()), 'not signed');
    }

    #[Test]
    public function a_message_signed_with_another_key_is_refused(): void
    {
        [$otherCert, $otherKey] = self::selfSignedCertificate();

        $message = $this->verify(self::enveloped(self::logoutRequestXml(), $otherKey, $otherCert));

        $this->assertRefused($message, 'does not verify');
    }

    #[Test]
    public function a_signed_message_nested_inside_an_unsigned_one_is_refused(): void
    {
        $wrapped = self::wrappedInUnsignedLogoutRequest($this->signed(self::logoutRequestXml()));

        $this->assertRefused($this->verify($wrapped), 'not on the root element');
    }

    #[Test]
    public function a_signature_that_references_an_inner_element_is_refused(): void
    {
        $message = $this->verify(self::signedOverAnInnerElement(self::logoutRequestXml(), $this->idpKey, $this->idpCert));

        $this->assertRefused($message, 'does not reference the root element');
    }

    #[Test]
    public function a_root_id_another_element_shares_is_refused(): void
    {
        $this->assertRefused($this->verify(self::withDuplicateId($this->signed(self::logoutRequestXml()))), 'shared by another element');
    }

    #[Test]
    public function a_document_with_two_signatures_is_refused(): void
    {
        $this->assertRefused($this->verify(self::withTwoSignatures($this->signed(self::logoutRequestXml()))), '2 signatures');
    }

    #[Test]
    public function a_message_signed_with_sha1_is_refused(): void
    {
        $xml = self::enveloped(self::logoutRequestXml(), $this->idpKey, $this->idpCert, XMLSecurityKey::RSA_SHA1);

        $this->assertRefused($this->verify($xml), 'signature algorithm http://www.w3.org/2000/09/xmldsig#rsa-sha1 is weaker');
    }

    #[Test]
    public function a_message_with_a_sha1_digest_is_refused(): void
    {
        $xml = self::enveloped(self::logoutRequestXml(), $this->idpKey, $this->idpCert, XMLSecurityKey::RSA_SHA256, XMLSecurityDSig::SHA1);

        $this->assertRefused($this->verify($xml), 'digest algorithm http://www.w3.org/2000/09/xmldsig#sha1 is weaker');
    }

    #[Test]
    public function a_signature_that_names_no_signature_algorithm_is_refused(): void
    {
        // The floor is checked by reading the algorithms, so a message that leaves them out is refused there
        // rather than being left for the verifier to stumble over.
        $xml = preg_replace('/<ds:SignatureMethod[^>]*\/>/', '', $this->signed(self::logoutRequestXml()), 1);

        $this->assertRefused($this->verify((string) $xml), 'exactly one signature algorithm');
    }

    #[Test]
    public function a_signature_that_names_no_digest_algorithm_is_refused(): void
    {
        $xml = preg_replace('/<ds:DigestMethod[^>]*\/>/', '', $this->signed(self::logoutRequestXml()), 1);

        $this->assertRefused($this->verify((string) $xml), 'no digest algorithm');
    }

    #[Test]
    public function a_message_with_a_doctype_is_refused(): void
    {
        $this->assertRefused($this->verify(self::withDoctype($this->signed(self::logoutRequestXml()))), 'DOCTYPE');
    }

    #[Test]
    public function a_signed_message_that_is_not_a_logout_message_is_refused(): void
    {
        $xml = '<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" ID="_r" Version="2.0" IssueInstant="2026-01-01T00:00:00Z"/>';

        $this->assertRefused($this->verify($this->signed($xml)), 'not a LogoutRequest or a LogoutResponse');
    }

    #[Test]
    public function text_that_is_not_xml_or_not_base64_is_refused(): void
    {
        $this->assertRefused($this->verify('not xml'), 'not well-formed');
        $this->assertRefused(PostBindingMessage::verify('not base64!', [$this->idpCert]), 'not base64');
    }
}
