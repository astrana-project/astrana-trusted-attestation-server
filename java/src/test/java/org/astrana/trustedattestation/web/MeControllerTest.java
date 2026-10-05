package org.astrana.trustedattestation.web;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.tuple;
import static org.mockito.Mockito.verifyNoInteractions;
import static org.mockito.Mockito.when;

import java.time.Clock;
import java.time.Instant;
import java.time.ZoneOffset;
import java.util.Base64;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Optional;
import org.astrana.trustedattestation.contract.Attribution;
import org.astrana.trustedattestation.contract.RelationshipTypeCatalog;
import org.astrana.trustedattestation.data.MemberRelationship;
import org.astrana.trustedattestation.manifest.ManifestDocument;
import org.astrana.trustedattestation.security.MemberIdentity;
import org.astrana.trustedattestation.security.MemberIdentityResolver;
import org.astrana.trustedattestation.service.LocalizationSettings;
import org.astrana.trustedattestation.web.MeController.RelationshipView;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.extension.ExtendWith;
import org.mockito.Mock;
import org.mockito.junit.jupiter.MockitoExtension;
import org.springframework.mock.web.MockHttpServletRequest;
import org.springframework.security.authentication.TestingAuthenticationToken;
import org.springframework.security.core.Authentication;
import org.springframework.test.util.ReflectionTestUtils;
import org.springframework.ui.ExtendedModelMap;
import org.springframework.ui.Model;

/**
 * How the self-service page assembles its view model, isolated from the database and the session.
 *
 * <p>The conformance suite drives the page end to end; this pins the controller's own decisions -- the
 * ones a template cannot make and a black-box test cannot see the seams of. Specifically: that an
 * anonymous request still renders (an empty member is an ordinary state, not an error, so nothing is
 * looked up for them); that each relationship becomes a fully-localized, already-statused row with its key
 * base64-encoded (or null when unkeyed); and that the whole page follows the locale it is handed -- which
 * {@code MemberLocaleResolver} has already decided from the switcher's cookie, the IAM claim and
 * Accept-Language -- with the org's own name localized to match.
 */
@ExtendWith(MockitoExtension.class)
class MeControllerTest {

    private static final Instant NOW = Instant.parse("2026-01-01T12:00:00Z");
    private static final byte[] RAW_KEY = rawKey();
    private static final String BASE64_KEY = Base64.getEncoder().encodeToString(RAW_KEY);

    /** name is locale-keyed so the org-name localization and its fallback can be exercised. */
    private static final ManifestDocument MANIFEST = new ManifestDocument(
            1,
            "en",
            Map.of("en", "Acme Bank", "fr", "Banque Acme"),
            null,
            null,
            null,
            "data:image/png;base64,AAAA",
            null,
            null,
            null,
            List.of("employee"),
            "https://a.example/me",
            "https://a.example/api/v1/attest");

    private static final LocalizationSettings LOCALIZATION = new LocalizationSettings(
            List.of("en", "fr"), List.of("en", "fr", "de"), "en", Map.of("en", "English", "fr", "Français"));

    @Mock
    private MemberIdentityResolver resolver;

    @Mock
    private org.astrana.trustedattestation.service.RelationshipService relationships;

    @Mock
    private RelationshipTypeCatalog catalog;

    @Mock
    private org.astrana.trustedattestation.service.UiStrings strings;

    private final Attribution attribution = new Attribution();

    private MeController controller;

    @BeforeEach
    void setUp() {
        controller = controllerWith(MANIFEST);
    }

    private MeController controllerWith(ManifestDocument manifest) {
        return new MeController(
                resolver,
                relationships,
                catalog,
                strings,
                LOCALIZATION,
                Clock.fixed(NOW, ZoneOffset.UTC),
                manifest,
                attribution);
    }

    private static MockHttpServletRequest request() {
        return new MockHttpServletRequest("GET", "/me");
    }

    private static byte[] rawKey() {
        byte[] key = new byte[32];
        key[0] = 7; // non-zero so PublicKeys.toBase64 has a real key to encode
        return key;
    }

    @Test
    void anAnonymousRequestRendersThePageWithNoMemberAndTouchesNoData() {
        // Reachable because sign-out lands here, and because the page has to render for a member the moment
        // before their session resolves. An empty member is an ordinary state: the name is blank, the list
        // is empty, and -- the point of this test -- the relationship store is never queried for someone
        // who is not there.
        when(resolver.resolve(null)).thenReturn(Optional.empty());
        when(strings.resolve("en")).thenReturn("en");
        when(strings.all("en")).thenReturn(Map.of("title", "Your relationships"));

        Model model = new ExtendedModelMap();
        String view = controller.me(null, null, request(), model);

        assertThat(view).isEqualTo("me");
        assertThat(model.getAttribute("memberName")).isEqualTo("");
        assertThat(model.getAttribute("relationships")).isEqualTo(List.of());
        // The language is the organisation's default when the resolver hands over nothing, and the org
        // identity is drawn from the manifest rather than any branding setting.
        assertThat(model.getAttribute("htmlLang")).isEqualTo("en");
        assertThat(model.getAttribute("htmlDir")).isEqualTo("ltr");
        assertThat(model.getAttribute("orgName")).isEqualTo("Acme Bank");
        assertThat(model.getAttribute("orgLogo")).isEqualTo("data:image/png;base64,AAAA");
        // This manifest sets neither a support URL nor a website, so the empty-state contact link has no
        // target and the org name renders as plain text.
        assertThat(model.getAttribute("contactUrl")).isNull();
        assertThat(model.getAttribute("attribution")).isSameAs(attribution);
        assertThat(model.getAttribute("t")).isEqualTo(Map.of("title", "Your relationships"));
        verifyNoInteractions(relationships);
    }

    @Test
    void eachHeldRelationshipBecomesALocalizedAlreadyStatusedRow() {
        Authentication authentication = new TestingAuthenticationToken("principal", null);
        when(resolver.resolve(authentication)).thenReturn(Optional.of(new MemberIdentity("alice", "Alice Anderson")));

        MemberRelationship keyed = new MemberRelationship("alice", "employee");
        keyed.setPublicKey(RAW_KEY); // keyed, unrevoked, unexpired -> active
        MemberRelationship unkeyed = new MemberRelationship("alice", "client"); // no key -> unkeyed
        ReflectionTestUtils.setField(unkeyed, "expiresAt", Instant.parse("2026-06-30T23:59:59.500Z"));

        when(relationships.findAllHeldBy("alice")).thenReturn(List.of(keyed, unkeyed));
        when(catalog.label("employee", "en")).thenReturn("Employee");
        when(catalog.label("client", "en")).thenReturn("Client");
        when(strings.resolve("en")).thenReturn("en");
        when(strings.all("en")).thenReturn(Map.of());

        Model model = new ExtendedModelMap();
        controller.me(authentication, Locale.ENGLISH, request(), model);

        assertThat(model.getAttribute("memberName")).isEqualTo("Alice Anderson");

        @SuppressWarnings("unchecked")
        List<RelationshipView> rows = (List<RelationshipView>) model.getAttribute("relationships");
        // Each row is fully prepared for the template: the governed type carries its localized label, the
        // status is computed against the fixed clock (active for the keyed row, unkeyed for the other), and
        // the key is base64-encoded when present and null when not -- the branch a keyless row exercises.
        assertThat(rows)
                .extracting(
                        RelationshipView::relationshipType,
                        RelationshipView::label,
                        RelationshipView::status,
                        RelationshipView::publicKey,
                        RelationshipView::expiresAt)
                .containsExactly(
                        tuple("employee", "Employee", "active", BASE64_KEY, null),
                        // The expiry is the same string the API serialises: UTC, Z, the fraction trimmed.
                        tuple("client", "Client", "unkeyed", null, "2026-06-30T23:59:59.5Z"));
    }

    @Test
    void theWholePageFollowsTheResolvedLocaleForLanguageAndOrgName() {
        // The locale arrives already decided -- cookie, then IAM claim, then Accept-Language, see
        // MemberLocaleResolverTest -- and the whole page follows it: the advertised html lang, the strings,
        // the relationship labels and the org's own localized name all come out French.
        Authentication authentication = new TestingAuthenticationToken("principal", null);
        when(resolver.resolve(authentication)).thenReturn(Optional.of(new MemberIdentity("alice", "Alice")));
        when(relationships.findAllHeldBy("alice")).thenReturn(List.of());
        when(strings.resolve("fr")).thenReturn("fr");
        when(strings.all("fr")).thenReturn(Map.of("sign_out", "Se déconnecter"));

        Model model = new ExtendedModelMap();
        controller.me(authentication, Locale.FRENCH, request(), model);

        assertThat(model.getAttribute("htmlLang")).isEqualTo("fr");
        assertThat(model.getAttribute("orgName")).isEqualTo("Banque Acme");
        assertThat(model.getAttribute("t")).isEqualTo(Map.of("sign_out", "Se déconnecter"));
    }

    @Test
    void theLanguageSwitcherIsHandedTheOfferedLocalesAndThePageToReturnTo() {
        when(resolver.resolve(null)).thenReturn(Optional.empty());
        when(strings.resolve("en")).thenReturn("en");
        when(strings.all("en")).thenReturn(Map.of());

        Model model = new ExtendedModelMap();
        controller.me(null, Locale.ENGLISH, request(), model);

        assertThat(model.getAttribute("localization")).isSameAs(LOCALIZATION);
        assertThat(model.getAttribute("returnTo")).isEqualTo("/me");
    }

    @Test
    void anOrgNameMissingTheRenderedLocaleFallsBackToTheManifestDefault() {
        // The consumer fallback decision record 34 sets out, the requested locale, then its language, then the
        // manifest's declared default. A member on a German browser sees the organisation's English name
        // because that is the only one the manifest carries, not a blank where the name should be.
        ManifestDocument englishOnly = new ManifestDocument(
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
                "https://a.example/me",
                "https://a.example/api/v1/attest");
        MeController local = controllerWith(englishOnly);

        when(resolver.resolve(null)).thenReturn(Optional.empty());
        when(strings.resolve("de")).thenReturn("en"); // German is untranslated, so the page is English
        when(strings.all("de")).thenReturn(Map.of());

        Model model = new ExtendedModelMap();
        local.me(null, Locale.GERMAN, request(), model);

        assertThat(model.getAttribute("orgName")).isEqualTo("Acme Bank");
    }

    @Test
    void theContactUrlPrefersTheSupportUrlThenFallsBackToTheWebsite() {
        // Where a member holding no relationship yet is pointed for help: the support URL when the org sets
        // one, otherwise its website. Resolved for the rendered locale, the same way the org name is.
        ManifestDocument withSupport = new ManifestDocument(
                1,
                "en",
                Map.of("en", "Acme"),
                null,
                Map.of("en", "https://acme.example"),
                Map.of("en", "https://acme.example/contact"),
                null,
                null,
                null,
                null,
                List.of("employee"),
                "https://a.example/me",
                "https://a.example/api/v1/attest");
        when(resolver.resolve(null)).thenReturn(Optional.empty());
        when(strings.resolve("en")).thenReturn("en");
        when(strings.all("en")).thenReturn(Map.of());

        Model model = new ExtendedModelMap();
        controllerWith(withSupport).me(null, Locale.ENGLISH, request(), model);
        assertThat(model.getAttribute("contactUrl")).isEqualTo("https://acme.example/contact");

        // With no support URL, the website is used instead.
        ManifestDocument websiteOnly = new ManifestDocument(
                1,
                "en",
                Map.of("en", "Acme"),
                null,
                Map.of("en", "https://acme.example"),
                null,
                null,
                null,
                null,
                null,
                List.of("employee"),
                "https://a.example/me",
                "https://a.example/api/v1/attest");
        Model websiteModel = new ExtendedModelMap();
        controllerWith(websiteOnly).me(null, Locale.ENGLISH, request(), websiteModel);
        assertThat(websiteModel.getAttribute("contactUrl")).isEqualTo("https://acme.example");
    }
}
