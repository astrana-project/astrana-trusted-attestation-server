package org.astrana.trustedattestation.config;

import java.math.BigInteger;
import java.security.GeneralSecurityException;
import java.security.KeyPair;
import java.security.KeyPairGenerator;
import java.security.cert.X509Certificate;
import java.time.Instant;
import java.time.temporal.ChronoUnit;
import java.util.Date;
import org.bouncycastle.asn1.x500.X500Name;
import org.bouncycastle.cert.jcajce.JcaX509CertificateConverter;
import org.bouncycastle.cert.jcajce.JcaX509v3CertificateBuilder;
import org.bouncycastle.operator.jcajce.JcaContentSignerBuilder;

/** RSA keys and self-signed certificates for the SAML tests, made fresh for each test run. */
final class TestCredentials {

    private TestCredentials() {}

    static KeyPair keyPair() {
        try {
            KeyPairGenerator generator = KeyPairGenerator.getInstance("RSA");
            generator.initialize(2048);
            return generator.generateKeyPair();
        } catch (GeneralSecurityException exception) {
            throw new IllegalStateException(exception);
        }
    }

    /** A certificate for {@code keys}, issued to and by {@code CN=commonName}, valid from yesterday to tomorrow. */
    static X509Certificate certificate(String commonName, KeyPair keys) {
        try {
            X500Name name = new X500Name("CN=" + commonName);
            Instant now = Instant.now();
            return new JcaX509CertificateConverter()
                    .getCertificate(new JcaX509v3CertificateBuilder(
                                    name,
                                    BigInteger.ONE,
                                    Date.from(now.minus(1, ChronoUnit.DAYS)),
                                    Date.from(now.plus(1, ChronoUnit.DAYS)),
                                    name,
                                    keys.getPublic())
                            .build(new JcaContentSignerBuilder("SHA256withRSA").build(keys.getPrivate())));
        } catch (Exception exception) {
            throw new IllegalStateException(exception);
        }
    }
}
