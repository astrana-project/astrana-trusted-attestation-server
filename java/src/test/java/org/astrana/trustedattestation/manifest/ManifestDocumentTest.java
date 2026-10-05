package org.astrana.trustedattestation.manifest;

import static org.assertj.core.api.Assertions.assertThat;

import com.fasterxml.jackson.annotation.JsonInclude;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.databind.PropertyNamingStrategies;
import java.util.List;
import java.util.Map;
import org.astrana.trustedattestation.config.TrustedAttestationProperties;
import org.junit.jupiter.api.Test;

/**
 * How the served manifest is built from configuration.
 *
 * <p>The one decision worth pinning is the empty-to-absent rule: an organisation that configures no
 * description, website, privacy notice or jurisdiction list must serve a manifest that omits those keys
 * entirely, not one carrying empty collections a consumer would have to special-case. The required fields
 * pass straight through, and the logo arrives already resolved to a data URI.
 */
class ManifestDocumentTest {

    private static TrustedAttestationProperties.Manifest fullProperties() {
        var properties = new TrustedAttestationProperties.Manifest();
        properties.setManifestVersion(1);
        properties.setDefaultLocale("en");
        properties.setName(Map.of("en", "Acme Bank"));
        properties.setDescription(Map.of("en", "Retail banking"));
        properties.setWebsite(Map.of("en", "https://acme.example"));
        properties.setSupportUrl(Map.of("en", "https://acme.example/contact"));
        properties.setPrivacyNoticeUrl(Map.of("en", "https://acme.example/privacy"));
        properties.setJurisdictions(List.of("US", "US-NY"));
        properties.setRelationshipTypes(List.of("employee"));
        properties.setEnrollmentUrl("https://acme.example/me");
        properties.setAttestationUrl("https://acme.example/api/v1/attest");
        return properties;
    }

    @Test
    void fromMapsEveryConfiguredFieldAndPassesTheResolvedLogoThrough() {
        ManifestDocument document =
                ManifestDocument.from(fullProperties(), "data:image/png;base64,AAAA", "data:image/png;base64,BBBB");

        assertThat(document.manifestVersion()).isEqualTo(1);
        assertThat(document.defaultLocale()).isEqualTo("en");
        assertThat(document.name()).containsEntry("en", "Acme Bank");
        assertThat(document.description()).containsEntry("en", "Retail banking");
        assertThat(document.website()).containsEntry("en", "https://acme.example");
        assertThat(document.supportUrl()).containsEntry("en", "https://acme.example/contact");
        assertThat(document.privacyNoticeUrl()).containsEntry("en", "https://acme.example/privacy");
        assertThat(document.jurisdictions()).containsExactly("US", "US-NY");
        assertThat(document.relationshipTypes()).containsExactly("employee");
        assertThat(document.enrollmentUrl()).isEqualTo("https://acme.example/me");
        assertThat(document.attestationUrl()).isEqualTo("https://acme.example/api/v1/attest");
        assertThat(document.logoData()).isEqualTo("data:image/png;base64,AAAA");
        assertThat(document.logoDataDark()).isEqualTo("data:image/png;base64,BBBB");
    }

    @Test
    void emptyOptionalCollectionsBecomeNullRatherThanEmpty() {
        var properties = fullProperties();
        properties.setDescription(Map.of());
        properties.setWebsite(Map.of());
        properties.setSupportUrl(Map.of());
        properties.setPrivacyNoticeUrl(Map.of());
        properties.setJurisdictions(List.of());

        ManifestDocument document = ManifestDocument.from(properties, null, null);

        assertThat(document.description()).isNull();
        assertThat(document.website()).isNull();
        assertThat(document.supportUrl()).isNull();
        assertThat(document.privacyNoticeUrl()).isNull();
        assertThat(document.jurisdictions()).isNull();
        assertThat(document.logoData()).isNull();
        assertThat(document.logoDataDark()).isNull();

        // The required content is unaffected by the optionals being empty.
        assertThat(document.name()).containsEntry("en", "Acme Bank");
        assertThat(document.relationshipTypes()).containsExactly("employee");
    }

    @Test
    void aPopulatedOptionalIsKept() {
        // The other side of the empty-to-absent rule: a description that is actually configured survives.
        ManifestDocument document = ManifestDocument.from(fullProperties(), null, null);

        assertThat(document.description()).containsEntry("en", "Retail banking");
    }

    /**
     * The wire concern the .NET record gets from its [JsonPropertyName]/[JsonIgnore] attributes: here the
     * snake_case names and the omit-when-null behaviour come from the application-wide Jackson settings
     * (see application.yml), so this pins them with a mapper configured the same way -- otherwise the dark
     * logo could serialise under the wrong key, or leak an explicit null, and only the conformance suite
     * would notice.
     */
    @Test
    void theDarkLogoSerialisesUnderItsSnakeCaseNameAndIsOmittedWhenNull() throws Exception {
        ObjectMapper mapper = new ObjectMapper()
                .setPropertyNamingStrategy(PropertyNamingStrategies.SNAKE_CASE)
                .setSerializationInclusion(JsonInclude.Include.NON_NULL);

        ManifestDocument withDark =
                ManifestDocument.from(fullProperties(), "data:image/png;base64,AAAA", "data:image/png;base64,BBBB");
        String withDarkJson = mapper.writeValueAsString(withDark);
        assertThat(withDarkJson).contains("\"logo_data_dark\":\"data:image/png;base64,BBBB\"");
        // Right after logo_data, matching the .NET property order so the manifest is byte-identical.
        assertThat(withDarkJson.indexOf("logo_data_dark")).isGreaterThan(withDarkJson.indexOf("\"logo_data\""));

        ManifestDocument noDark = ManifestDocument.from(fullProperties(), "data:image/png;base64,AAAA", null);
        assertThat(mapper.writeValueAsString(noDark)).doesNotContain("logo_data_dark");
    }

    /**
     * Same wire concern for the new field: it serialises under its snake_case name, sits right after
     * {@code website} so the manifest is byte-identical across implementations, and is omitted entirely
     * when the org configures no support URL rather than leaking an explicit null.
     */
    @Test
    void theSupportUrlSerialisesUnderItsSnakeCaseNameAfterWebsiteAndIsOmittedWhenAbsent() throws Exception {
        ObjectMapper mapper = new ObjectMapper()
                .setPropertyNamingStrategy(PropertyNamingStrategies.SNAKE_CASE)
                .setSerializationInclusion(JsonInclude.Include.NON_NULL);

        ManifestDocument withSupport = ManifestDocument.from(fullProperties(), null, null);
        String withSupportJson = mapper.writeValueAsString(withSupport);
        assertThat(withSupportJson).contains("\"support_url\":{\"en\":\"https://acme.example/contact\"}");
        // Immediately after website, matching the .NET property order so the manifest is byte-identical.
        assertThat(withSupportJson.indexOf("support_url")).isGreaterThan(withSupportJson.indexOf("\"website\""));

        var noSupport = fullProperties();
        noSupport.setSupportUrl(Map.of());
        assertThat(mapper.writeValueAsString(ManifestDocument.from(noSupport, null, null)))
                .doesNotContain("support_url");
    }
}
