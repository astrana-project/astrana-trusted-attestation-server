package org.astrana.trustedattestation.web;

import static org.assertj.core.api.Assertions.assertThat;

import java.util.List;
import java.util.Map;
import org.astrana.trustedattestation.service.LocalizationSettings;
import org.astrana.trustedattestation.web.MeController.RelationshipView;
import org.junit.jupiter.api.Test;

/**
 * The self-service page as it renders one relationship: the subtype and the expiry as the template decides
 * to show them, which the controller test cannot see.
 */
class MeRenderingTest {

    private static final LocalizationSettings ENGLISH = new LocalizationSettings(
            List.of("en"), TemplateRendering.STRINGS.supportedLocales(), "en", Map.of("en", "English"));

    private static String renderWith(RelationshipView relationship) {
        return renderIn("en", relationship);
    }

    private static String renderIn(String locale, RelationshipView relationship) {
        return TemplateRendering.render("me", "/me", ENGLISH, locale, Map.of("relationships", List.of(relationship)));
    }

    /** The opening tag of the relationship's key field, from its start to its closing bracket. */
    private static String keyInput(String page) {
        int start = page.lastIndexOf("<input", page.indexOf("data-key-input"));
        return page.substring(start, page.indexOf('>', start) + 1);
    }

    private static RelationshipView employee(String subtype, String expiresAt) {
        return new RelationshipView("employee", "Employee", subtype, "active", "a-key", expiresAt);
    }

    @Test
    void aSubtypeIsShownWhateverItsText() {
        // Thymeleaf reads the strings "false", "off" and "no" as a false condition, so a bare th:if on the
        // subtype hid exactly those words. The rule is set and not empty, as on the other two
        // implementations.
        for (String subtype : List.of("Fellow", "no", "off", "false", "0")) {
            assertThat(renderWith(employee(subtype, null)))
                    .as("subtype '%s'", subtype)
                    .contains("<span class=\"fw-normal text-body-secondary\">" + subtype + "</span>");
        }
    }

    @Test
    void anAbsentOrEmptySubtypeShowsNothing() {
        assertThat(renderWith(employee(null, null))).doesNotContain("fw-normal text-body-secondary");
        assertThat(renderWith(employee("", null))).doesNotContain("fw-normal text-body-secondary");
    }

    @Test
    void thePageCarriesTheAntiForgeryTokenAndItsScriptSendsItWithARevoke() {
        // The same meta tag name and header name on all three implementations, so one conformance check
        // can read the token from any of them.
        String page = renderWith(employee(null, null));

        assertThat(page).contains("<meta name=\"csrf-token\" content=\"" + TemplateRendering.CSRF_TOKEN + "\"/>");
        assertThat(page).contains("document.querySelector(\"meta[name='csrf-token']\").content");
        assertThat(page).contains("headers: { \"X-CSRF-TOKEN\": token }");
    }

    @Test
    void theKeyFieldReadsLeftToRightAlsoOnARightToLeftPage() {
        // A key is base64 text, so it reads left to right whatever the page's direction. Arabic is the
        // case that matters, as the page itself is right to left there.
        String arabic = renderIn("ar", employee(null, null));
        assertThat(arabic).contains("lang=\"ar\" dir=\"rtl\"");

        assertThat(keyInput(renderIn("en", employee(null, null)))).contains("dir=\"ltr\"");
        assertThat(keyInput(arabic)).contains("dir=\"ltr\"");
    }

    @Test
    void theExpiryIsTheSameStringTheApiSerialises() {
        assertThat(renderWith(employee(null, "2026-06-30T23:59:59.5Z")))
                .contains("<span>2026-06-30T23:59:59.5Z</span>");
        // The strings map is inlined for the page's script, so the key's name is always present. The expiry
        // line itself is the only element with this class pair.
        assertThat(renderWith(employee(null, null))).doesNotContain("form-text mt-1 mb-0");
    }
}
