package org.astrana.trustedattestation.manifest;

import static org.assertj.core.api.Assertions.assertThat;

import java.util.List;
import java.util.Map;
import org.astrana.trustedattestation.contract.RelationshipTypeCatalog;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.ValueSource;

class ManifestValidatorTest {

    private final RelationshipTypeCatalog catalog = new RelationshipTypeCatalog();

    /** A one-pixel PNG. The rule under test is the shape of the value, not the image. */
    private static final String LOGO =
            "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==";

    private static ManifestDocument valid() {
        return new ManifestDocument(
                1,
                "en",
                Map.of("en", "Acme Bank", "fr", "Banque Acme"),
                Map.of("en", "Retail and commercial banking"),
                Map.of("en", "https://acmebank.example"),
                Map.of("en", "https://acmebank.example/contact"),
                LOGO,
                null,
                Map.of("en", "https://acmebank.example/privacy"),
                List.of("US", "US-NY"),
                List.of("employee", "client"),
                "https://ata.acmebank.example/me",
                "https://ata.acmebank.example/api/v1/attest");
    }

    @Test
    void acceptsTheSpecsOwnExample() {
        assertThat(ManifestValidator.validate(valid(), catalog)).isEmpty();
    }

    @Test
    void acceptsAManifestWithOnlyTheRequiredFields() {
        ManifestDocument manifest = new ManifestDocument(
                1,
                "en",
                Map.of("en", "Acme Bank"),
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                List.of("employee"),
                "https://ata.acmebank.example/me",
                "https://ata.acmebank.example/api/v1/attest");

        assertThat(ManifestValidator.validate(manifest, catalog)).isEmpty();
    }

    @Test
    void rejectsARelationshipTypeOutsideTheGovernedEnum() {
        ManifestDocument manifest = new ManifestDocument(
                1,
                "en",
                Map.of("en", "Acme"),
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                List.of("employee", "patient"),
                "https://a.example/me",
                "https://a.example/api/v1/attest");

        assertThat(ManifestValidator.validate(manifest, catalog)).anyMatch(error -> error.contains("patient"));
    }

    @Test
    void rejectsAManifestThatAttestsToNothing() {
        ManifestDocument manifest = new ManifestDocument(
                1,
                "en",
                Map.of("en", "Acme"),
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                List.of(),
                "https://a.example/me",
                "https://a.example/api/v1/attest");

        assertThat(ManifestValidator.validate(manifest, catalog))
                .anyMatch(error -> error.contains("relationship_types"));
    }

    @Test
    void answersAPathologicalLocaleTagInsteadOfOverflowingTheStack() {
        // Java's regex engine recurses through a greedy repeated group, so a long enough tag once
        // overflowed the stack -- a StackOverflowError in the middle of startup validation rather than
        // the plain "not a valid locale tag" the validator exists to produce. The pattern is possessive
        // now, which matches iteratively: this tag ends in a subtag longer than the eight characters a
        // subtag may have, so it is refused cleanly however many valid subtags precede it. (A long tag
        // whose subtags are all valid is accepted, matching the contract schema and the other two
        // implementations, which never bounded the count.)
        String pathological = "en" + "-abcdefgh".repeat(5000) + "-toolongsubtag";

        ManifestDocument manifest = new ManifestDocument(
                1,
                pathological,
                Map.of(pathological, "Acme"),
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                List.of("employee"),
                "https://a.example/me",
                "https://a.example/api/v1/attest");

        // Refused for being an invalid locale tag -- the clean answer the validator exists to give -- not
        // merely refused for some reason (which .isNotEmpty() would pass on even if the overflow guard
        // had regressed and the manifest happened to fail another check).
        assertThat(ManifestValidator.validate(manifest, catalog)).anyMatch(error -> error.contains("not a locale tag"));
    }

    @Test
    void acceptsARealisticMultiSubtagLocaleTag() {
        // Bounding the repetition must not start rejecting tags that are genuinely valid.
        ManifestDocument manifest = new ManifestDocument(
                1,
                "zh-Hant-CN",
                Map.of("zh-Hant-CN", "Acme"),
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                List.of("employee"),
                "https://a.example/me",
                "https://a.example/api/v1/attest");

        assertThat(ManifestValidator.validate(manifest, catalog)).noneMatch(error -> error.contains("locale"));
    }

    @Test
    void rejectsAMissingDefaultLocaleEntry() {
        // If default_locale names a locale that is not present, the fallback rule has nowhere to land.
        ManifestDocument manifest = new ManifestDocument(
                1,
                "de",
                Map.of("en", "Acme"),
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                List.of("employee"),
                "https://a.example/me",
                "https://a.example/api/v1/attest");

        assertThat(ManifestValidator.validate(manifest, catalog)).anyMatch(error -> error.contains("default locale"));
    }

    @ParameterizedTest
    @ValueSource(strings = {"http://a.example/api/v1/attest", "/api/v1/attest", "", "HTTPS://a.example/api/v1/attest"})
    void rejectsAnAttestationUrlThatIsNotAbsoluteLowerCaseHttps(String url) {
        // The contract schema's pattern is case-sensitive, so HTTPS:// fails it on every consumer, and the
        // other two implementations refuse it too.
        ManifestDocument manifest = new ManifestDocument(
                1,
                "en",
                Map.of("en", "Acme"),
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                List.of("employee"),
                "https://a.example/me",
                url);

        assertThat(ManifestValidator.validate(manifest, catalog)).anyMatch(error -> error.contains("attestation_url"));
    }

    @ParameterizedTest
    @ValueSource(strings = {"http://a.example/me", "/me", ""})
    void rejectsAnEnrollmentUrlThatIsNotAbsoluteHttps(String url) {
        // The same rule and the same code as attestation_url -- and, until this test, the same code with
        // no test: deleting the enrollment_url line broke nothing. A prospective member follows this URL,
        // so a plain-http one would send them somewhere their credentials travel in the clear.
        ManifestDocument manifest = new ManifestDocument(
                1,
                "en",
                Map.of("en", "Acme"),
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                List.of("employee"),
                url,
                "https://a.example/api/v1/attest");

        assertThat(ManifestValidator.validate(manifest, catalog)).anyMatch(error -> error.contains("enrollment_url"));
    }

    @Test
    void rejectsAnUnexpectedManifestVersion() {
        ManifestDocument manifest = new ManifestDocument(
                2,
                "en",
                Map.of("en", "Acme"),
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                List.of("employee"),
                "https://a.example/me",
                "https://a.example/api/v1/attest");

        assertThat(ManifestValidator.validate(manifest, catalog)).anyMatch(error -> error.contains("manifest_version"));
    }

    @ParameterizedTest
    @ValueSource(strings = {"USA", "us", "US-NEWYORK"})
    void rejectsAJurisdictionThatIsNotAnIsoCode(String code) {
        ManifestDocument manifest = new ManifestDocument(
                1,
                "en",
                Map.of("en", "Acme"),
                null,
                null,
                null,
                null,
                null,
                null,
                List.of(code),
                List.of("employee"),
                "https://a.example/me",
                "https://a.example/api/v1/attest");

        assertThat(ManifestValidator.validate(manifest, catalog)).anyMatch(error -> error.contains("jurisdictions"));
    }

    @Test
    void acceptsASupportUrl() {
        // The empty-state message links a member with no relationship yet to this contact page, so a valid
        // one must pass. valid() already carries one; this pins the accept explicitly.
        assertThat(ManifestValidator.validate(valid(), catalog)).isEmpty();
    }

    @ParameterizedTest
    @ValueSource(strings = {"http://acmebank.example/contact", "Https://acmebank.example/contact"})
    void rejectsASupportUrlThatIsNotAbsoluteLowerCaseHttps(String url) {
        // Same rule as website: an org's contact page a member is sent to over plain http would carry their
        // request in the clear. Validated exactly like the other localized URLs.
        ManifestDocument manifest = new ManifestDocument(
                1,
                "en",
                Map.of("en", "Acme"),
                null,
                null,
                Map.of("en", url),
                null,
                null,
                null,
                null,
                List.of("employee"),
                "https://a.example/me",
                "https://a.example/api/v1/attest");

        assertThat(ManifestValidator.validate(manifest, catalog)).anyMatch(error -> error.contains("support_url"));
    }

    @Test
    void acceptsAManifestWithNoSupportUrl() {
        // Optional: an org that sets no support URL is fine (the empty state then falls back to the website,
        // or to plain text). The specification's example with only the support URL taken out, so that is the
        // one omission under test.
        ManifestDocument manifest = new ManifestDocument(
                1,
                "en",
                Map.of("en", "Acme Bank", "fr", "Banque Acme"),
                Map.of("en", "Retail and commercial banking"),
                Map.of("en", "https://acmebank.example"),
                null,
                LOGO,
                null,
                Map.of("en", "https://acmebank.example/privacy"),
                List.of("US", "US-NY"),
                List.of("employee", "client"),
                "https://ata.acmebank.example/me",
                "https://ata.acmebank.example/api/v1/attest");

        assertThat(ManifestValidator.validate(manifest, catalog)).isEmpty();
    }

    /** A valid manifest carrying exactly the given light and dark logos, so the logo rules can be exercised
     * without the noise of every other field. */
    private static ManifestDocument withLogos(String logoData, String logoDataDark) {
        return new ManifestDocument(
                1,
                "en",
                Map.of("en", "Acme"),
                null,
                null,
                null,
                logoData,
                logoDataDark,
                null,
                null,
                List.of("employee"),
                "https://a.example/me",
                "https://a.example/api/v1/attest");
    }

    @Test
    void acceptsAnEmbeddedLogo() {
        assertThat(ManifestValidator.validate(valid(), catalog)).isEmpty();
    }

    @Test
    void acceptsADarkLogoAlongsideALightOne() {
        assertThat(ManifestValidator.validate(withLogos(LOGO, LOGO), catalog)).isEmpty();
    }

    @Test
    void rejectsADarkLogoThatIsAUrlRatherThanEmbedded() {
        // Same reasoning as the light logo: a link would put the request back on the org's server.
        assertThat(ManifestValidator.validate(withLogos(LOGO, "https://acmebank.example/logo-dark.png"), catalog))
                .anyMatch(error -> error.contains("logo_data_dark"));
    }

    @Test
    void rejectsADarkLogoSetWithoutALightLogo() {
        // The light logo is the default and the fallback everything renders when no dark preference applies,
        // so a dark-only logo would silently never show. The validator catches that at boot.
        assertThat(ManifestValidator.validate(withLogos(null, LOGO), catalog))
                .anyMatch(error -> error.contains("logo_data_dark"));
    }

    @Test
    void acceptsAManifestWithNoLogoAtAll() {
        ManifestDocument manifest = new ManifestDocument(
                1,
                "en",
                Map.of("en", "Acme"),
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                List.of("employee"),
                "https://a.example/me",
                "https://a.example/api/v1/attest");

        assertThat(ManifestValidator.validate(manifest, catalog)).isEmpty();
    }

    @Test
    void rejectsALogoThatIsAUrlRatherThanEmbedded() {
        // A link would put the request back on the org's server, which is what embedding the image
        // exists to avoid.
        ManifestDocument manifest = new ManifestDocument(
                1,
                "en",
                Map.of("en", "Acme"),
                null,
                null,
                null,
                "https://acmebank.example/logo.png",
                null,
                null,
                null,
                List.of("employee"),
                "https://a.example/me",
                "https://a.example/api/v1/attest");

        assertThat(ManifestValidator.validate(manifest, catalog)).anyMatch(error -> error.contains("logo_data"));
    }

    @Test
    void rejectsALogoThatIsNotAnImage() {
        ManifestDocument manifest = new ManifestDocument(
                1,
                "en",
                Map.of("en", "Acme"),
                null,
                null,
                null,
                "data:text/html;base64,PGgxPmhpPC9oMT4=",
                null,
                null,
                null,
                List.of("employee"),
                "https://a.example/me",
                "https://a.example/api/v1/attest");

        assertThat(ManifestValidator.validate(manifest, catalog)).anyMatch(error -> error.contains("logo_data"));
    }

    /** A data URI of exactly the given length, well formed, so only its length is under test. */
    private static String logoOfLength(int length) {
        String prefix = "data:image/png;base64,";
        return prefix + "A".repeat(length - prefix.length());
    }

    @Test
    void acceptsALogoOfExactlyTheSchemasLimit() {
        String atTheLimit = logoOfLength(ManifestValidator.MAX_LOGO_LENGTH);

        assertThat(ManifestValidator.validate(withLogos(atTheLimit, atTheLimit), catalog))
                .isEmpty();
    }

    @Test
    void rejectsALightLogoOverTheSchemasLimitNamingTheSetting() {
        // The contract schema caps each logo at 65,536 characters, so a longer one fails every consumer
        // that validates the manifest, and is refused here at startup as on the other two implementations.
        String overTheLimit = logoOfLength(ManifestValidator.MAX_LOGO_LENGTH + 1);

        assertThat(ManifestValidator.validate(withLogos(overTheLimit, null), catalog))
                .containsExactly("logo_data is 65537 characters, over the limit of 65536: keep the logo icon-sized, "
                        + "because it inflates the manifest directly.");
    }

    @Test
    void rejectsADarkLogoOverTheSchemasLimitNamingTheSetting() {
        String overTheLimit = logoOfLength(ManifestValidator.MAX_LOGO_LENGTH + 1);

        assertThat(ManifestValidator.validate(withLogos(LOGO, overTheLimit), catalog))
                .singleElement()
                .asString()
                .startsWith("logo_data_dark is 65537 characters, over the limit of 65536");
    }

    @Test
    void rejectsRepeatedJurisdictionsNamingTheSetting() {
        // The contract schema requires the jurisdictions to be unique, as it does the relationship types.
        ManifestDocument manifest = new ManifestDocument(
                1,
                "en",
                Map.of("en", "Acme"),
                null,
                null,
                null,
                null,
                null,
                null,
                List.of("US", "US-NY", "US"),
                List.of("employee"),
                "https://a.example/me",
                "https://a.example/api/v1/attest");

        assertThat(ManifestValidator.validate(manifest, catalog)).containsExactly("jurisdictions contains duplicates.");
    }

    @Test
    void rejectsDuplicateRelationshipTypes() {
        ManifestDocument manifest = new ManifestDocument(
                1,
                "en",
                Map.of("en", "Acme"),
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                List.of("employee", "employee"),
                "https://a.example/me",
                "https://a.example/api/v1/attest");

        assertThat(ManifestValidator.validate(manifest, catalog)).anyMatch(error -> error.contains("duplicates"));
    }
}
