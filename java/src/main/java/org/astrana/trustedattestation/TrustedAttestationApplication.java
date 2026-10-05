package org.astrana.trustedattestation;

import org.astrana.trustedattestation.config.TrustedAttestationProperties;
import org.astrana.trustedattestation.security.TlsRequirement;
import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;
import org.springframework.boot.context.properties.EnableConfigurationProperties;
import org.springframework.scheduling.annotation.EnableScheduling;

/**
 * Astrana Trusted Attestation Server.
 *
 * <p>A small self-hosted app an organisation runs beside its existing identity system. A member's
 * Astrana instance registers the public half of an Ed25519 keypair against the relationship the org
 * already holds for them; anyone else can then ask this server whether a given public key is on record,
 * and what kind of relationship it represents.
 *
 * <p>Nothing reusable ever changes hands. The private key never leaves the member's device, and a public
 * key is public by design.
 */
@SpringBootApplication
@EnableConfigurationProperties(TrustedAttestationProperties.class)
@EnableScheduling
public class TrustedAttestationApplication {

    public static void main(String[] args) {
        SpringApplication application = new SpringApplication(TrustedAttestationApplication.class);

        // Registered as a listener rather than checked in a bean, so the TLS requirement is decided
        // before the web server binds a port -- the app must fail to start, not start and then complain.
        application.addListeners(new TlsRequirement());

        application.run(args);
    }
}
