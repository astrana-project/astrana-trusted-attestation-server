package org.astrana.trustedattestation.config;

import java.util.List;
import org.astrana.trustedattestation.contract.RelationshipTypeCatalog;
import org.astrana.trustedattestation.manifest.LogoData;
import org.astrana.trustedattestation.manifest.ManifestDocument;
import org.astrana.trustedattestation.manifest.ManifestValidator;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;

@Configuration
public class ManifestConfig {

    static final String LOGO_SETTING = "trusted-attestation.manifest.logo-data";
    static final String DARK_LOGO_SETTING = "trusted-attestation.manifest.logo-data-dark";

    /**
     * The manifest is validated as the container builds it, so an instance with a broken manifest never
     * reaches the point of serving one. Same reasoning as the TLS check, better found at boot than by a
     * verifying Astrana instance whose lookup fails.
     */
    @Bean
    public ManifestDocument manifestDocument(TrustedAttestationProperties properties, RelationshipTypeCatalog catalog) {
        ManifestDocument manifest = ManifestDocument.from(
                properties.getManifest(),
                LogoData.resolve(LOGO_SETTING, properties.getManifest().getLogoData()),
                LogoData.resolve(DARK_LOGO_SETTING, properties.getManifest().getLogoDataDark()));

        List<String> errors = ManifestValidator.validate(manifest, catalog);
        if (!errors.isEmpty()) {
            throw new IllegalStateException("The configured manifest (/.well-known/ata-manifest.json) is invalid:"
                    + System.lineSeparator() + "  - "
                    + String.join(System.lineSeparator() + "  - ", errors));
        }

        return manifest;
    }
}
