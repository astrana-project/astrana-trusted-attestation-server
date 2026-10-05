package org.astrana.trustedattestation.config;

import static org.assertj.core.api.Assertions.assertThat;

import org.junit.jupiter.api.Test;

/**
 * The SAML signature-strength gate.
 *
 * <p>OpenSAML checks a signature is sound, not that it was made with a modern algorithm, so on its own it
 * would accept a SHA-1-signed assertion. The .NET stack (Sustainsys) refuses anything below SHA-256, and the
 * three implementations are meant to accept or refuse the same assertion; {@link SamlSignatureStrength} is
 * what makes Java refuse it too. Both halves matter -- the signature method and each reference digest -- so
 * the WSO2 shape (a strong RSA-SHA256 signature over a collidable SHA-1 digest) is caught on the digest.
 */
class SamlSignatureStrengthTest {

    private static final String RSA_SHA256 = "http://www.w3.org/2001/04/xmldsig-more#rsa-sha256";
    private static final String RSA_SHA1 = "http://www.w3.org/2000/09/xmldsig#rsa-sha1";
    private static final String SHA256 = "http://www.w3.org/2001/04/xmlenc#sha256";
    private static final String SHA1 = "http://www.w3.org/2000/09/xmldsig#sha1";

    @Test
    void aSha256SignedResponseHasNoWeakAlgorithms() {
        assertThat(SamlSignatureStrength.weakAlgorithms(signedResponse(RSA_SHA256, SHA256)))
                .isEmpty();
    }

    @Test
    void aSha1DigestUnderASha256SignatureIsFlagged() {
        // The WSO2 shape: a strong signature method over a weak reference digest. A SHA-256 signature is no
        // help when the content it signs is bound only by a collidable SHA-1 hash, so the digest is caught --
        // this is exactly the assertion the .NET stack refuses.
        assertThat(SamlSignatureStrength.weakAlgorithms(signedResponse(RSA_SHA256, SHA1)))
                .containsExactly(SHA1);
    }

    @Test
    void aSha1SignatureMethodIsFlagged() {
        assertThat(SamlSignatureStrength.weakAlgorithms(signedResponse(RSA_SHA1, SHA256)))
                .containsExactly(RSA_SHA1);
    }

    @Test
    void anUnsignedOrUnparseableResponseReportsNoWeakAlgorithms() {
        // No signature means no algorithms to judge here (an unsigned assertion is refused elsewhere), and a
        // response that will not parse has already failed the signature check.
        assertThat(SamlSignatureStrength.weakAlgorithms(
                        "<samlp:Response xmlns:samlp=\"urn:oasis:names:tc:SAML:2.0:protocol\"/>"))
                .isEmpty();
        assertThat(SamlSignatureStrength.weakAlgorithms("not xml at all")).isEmpty();
        assertThat(SamlSignatureStrength.weakAlgorithms("")).isEmpty();
        assertThat(SamlSignatureStrength.weakAlgorithms(null)).isEmpty();
    }

    /** A minimal SAML response carrying one XML signature with the given signature and digest algorithms. */
    private static String signedResponse(String signatureMethod, String digestMethod) {
        return """
                <samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"
                                xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion">
                  <saml:Assertion>
                    <ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#">
                      <ds:SignedInfo>
                        <ds:SignatureMethod Algorithm="%s"/>
                        <ds:Reference>
                          <ds:DigestMethod Algorithm="%s"/>
                          <ds:DigestValue>x</ds:DigestValue>
                        </ds:Reference>
                      </ds:SignedInfo>
                      <ds:SignatureValue>x</ds:SignatureValue>
                    </ds:Signature>
                  </saml:Assertion>
                </samlp:Response>
                """.formatted(signatureMethod, digestMethod);
    }
}
