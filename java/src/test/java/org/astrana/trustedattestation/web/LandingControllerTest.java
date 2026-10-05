package org.astrana.trustedattestation.web;

import static org.assertj.core.api.Assertions.assertThat;
import static org.mockito.Mockito.when;

import java.util.List;
import java.util.Locale;
import java.util.Map;
import org.astrana.trustedattestation.contract.Attribution;
import org.astrana.trustedattestation.manifest.ManifestDocument;
import org.astrana.trustedattestation.service.LocalizationSettings;
import org.astrana.trustedattestation.service.UiStrings;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.extension.ExtendWith;
import org.mockito.Mock;
import org.mockito.junit.jupiter.MockitoExtension;
import org.springframework.mock.web.MockHttpServletRequest;
import org.springframework.ui.ExtendedModelMap;
import org.springframework.ui.Model;

/**
 * The public landing page, and the confirmation shown after sign-out.
 *
 * <p>Both entry points share one render, so what this pins is the two things they do differently and the
 * things a template cannot get right on its own: that {@code /} is not marked signed-out while
 * {@code /signed-out} is (the marker's presence is the whole confirmation), that the advertised html lang
 * follows the locale the page is actually rendered in -- English for an untranslated language -- rather
 * than whatever the browser asked for, with the org's name localized to match and falling back to the
 * manifest's default when it has no entry for the request, and that the language switcher is handed the
 * offered locales and the path to return to.
 */
@ExtendWith(MockitoExtension.class)
class LandingControllerTest {

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
    private UiStrings strings;

    private final Attribution attribution = new Attribution();

    private LandingController controller;

    @BeforeEach
    void setUp() {
        controller = controllerWith(MANIFEST);
    }

    private LandingController controllerWith(ManifestDocument manifest) {
        return new LandingController(strings, LOCALIZATION, manifest, attribution);
    }

    private static MockHttpServletRequest request(String path) {
        return new MockHttpServletRequest("GET", path);
    }

    @Test
    void theLandingPageRendersOrgIdentityAndIsNotMarkedSignedOut() {
        when(strings.resolve("en")).thenReturn("en");
        when(strings.all("en")).thenReturn(Map.of("welcome", "Sign in"));

        Model model = new ExtendedModelMap();
        String view = controller.landing(Locale.ENGLISH, request("/"), model);

        assertThat(view).isEqualTo("landing");
        assertThat(model.getAttribute("orgName")).isEqualTo("Acme Bank");
        assertThat(model.getAttribute("orgLogo")).isEqualTo("data:image/png;base64,AAAA");
        assertThat(model.getAttribute("htmlLang")).isEqualTo("en");
        assertThat(model.getAttribute("htmlDir")).isEqualTo("ltr");
        assertThat(model.getAttribute("attribution")).isSameAs(attribution);
        assertThat(model.getAttribute("t")).isEqualTo(Map.of("welcome", "Sign in"));
        // The distinguishing bit: the plain landing page must not claim a sign-out happened.
        assertThat(model.getAttribute("signedOut")).isEqualTo(false);
    }

    @Test
    void theSignedOutPageSetsTheConfirmationMarker() {
        // A dedicated path rather than a query marker on /, so "sign out" visibly worked instead of
        // bouncing the member back onto a login redirect that signs them straight back in. The marker's
        // presence is the entire confirmation, so it is the one thing that must differ from /.
        when(strings.resolve("en")).thenReturn("en");
        when(strings.all("en")).thenReturn(Map.of());

        Model model = new ExtendedModelMap();
        String view = controller.signedOut(Locale.ENGLISH, request("/signed-out"), model);

        assertThat(view).isEqualTo("landing");
        assertThat(model.getAttribute("signedOut")).isEqualTo(true);
    }

    @Test
    void theLanguageSwitcherIsHandedTheOfferedLocalesAndThePageToReturnTo() {
        // The switcher is on this page too, so the model carries what the fragment renders from: the
        // offered set (and whether there is a choice to show at all) and the path its POST comes back to.
        when(strings.resolve("en")).thenReturn("en");
        when(strings.all("en")).thenReturn(Map.of());

        Model model = new ExtendedModelMap();
        controller.signedOut(Locale.ENGLISH, request("/signed-out"), model);

        assertThat(model.getAttribute("localization")).isSameAs(LOCALIZATION);
        assertThat(model.getAttribute("returnTo")).isEqualTo("/signed-out");
    }

    @Test
    void anUntranslatedLanguageIsServedAndAdvertisedAsTheResolvedLocale() {
        // lang and dir describe the language the page is really in, which is the resolved locale, not the
        // request's: a request for an untranslated language is served in English and must say so, or a
        // screen reader announces the wrong language for the text it is reading.
        when(strings.resolve("de")).thenReturn("en");
        when(strings.all("de")).thenReturn(Map.of());

        Model model = new ExtendedModelMap();
        controller.landing(Locale.GERMAN, request("/"), model);

        assertThat(model.getAttribute("htmlLang")).isEqualTo("en");
    }

    @Test
    void anAbsentLocaleDefaultsToTheOrganisationsDefault() {
        when(strings.resolve("en")).thenReturn("en");
        when(strings.all("en")).thenReturn(Map.of());

        Model model = new ExtendedModelMap();
        controller.landing(null, request("/"), model);

        assertThat(model.getAttribute("htmlLang")).isEqualTo("en");
    }

    @Test
    void anOrgNameMissingTheRequestedLocaleFallsBackToTheManifestDefault() {
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
        LandingController local = controllerWith(englishOnly);
        when(strings.resolve("fr")).thenReturn("en");
        when(strings.all("fr")).thenReturn(Map.of());

        Model model = new ExtendedModelMap();
        local.landing(Locale.FRENCH, request("/"), model);

        assertThat(model.getAttribute("orgName")).isEqualTo("Acme Bank");
    }

    @Test
    void anOrgWithNoNameAtAllRendersAnEmptyOrgNameRatherThanFailing() {
        // The manifest's name map can be absent; the landing page still has to render for a prospective
        // member, so a missing name is an empty string, not a null that breaks the template.
        ManifestDocument nameless = new ManifestDocument(
                1,
                "en",
                null,
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
        LandingController local = controllerWith(nameless);
        when(strings.resolve("en")).thenReturn("en");
        when(strings.all("en")).thenReturn(Map.of());

        Model model = new ExtendedModelMap();
        local.landing(Locale.ENGLISH, request("/"), model);

        assertThat(model.getAttribute("orgName")).isEqualTo("");
    }
}
