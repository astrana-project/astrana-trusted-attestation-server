package org.astrana.trustedattestation.web;

import static org.assertj.core.api.Assertions.assertThat;

import java.util.List;
import java.util.Map;
import org.astrana.trustedattestation.service.LocalizationSettings;
import org.junit.jupiter.api.Test;

/**
 * The running version in the footer. The self-service page shows it, linked to the releases page, and the public
 * pages do not, even when they are handed it (decision record 47 in docs/adr).
 */
class ReleaseFooterRenderingTest {

    private static final LocalizationSettings ENGLISH = new LocalizationSettings(
            List.of("en"), TemplateRendering.STRINGS.supportedLocales(), "en", Map.of("en", "English"));

    private static final String RELEASE_LINK =
            "<a href=\"https://www.astrana.org/releases\" rel=\"noopener\" target=\"_blank\">v1.2.3</a>";

    private static String render(String template, String path) {
        return TemplateRendering.render(template, path, ENGLISH, "en", Map.of());
    }

    private static String footer(String page) {
        return page.substring(page.indexOf("<footer"), page.indexOf("</footer>"));
    }

    @Test
    void theSelfServicePageFooterLinksTheRunningVersionToTheReleasesPage() {
        assertThat(footer(render("me", "/me"))).contains(RELEASE_LINK);
    }

    @Test
    void theLandingAndLicencePagesDoNotShowTheRunningVersion() {
        for (String[] page : new String[][] {{"landing", "/"}, {"license", "/license"}}) {
            assertThat(render(page[0], page[1]))
                    .as(page[0])
                    .doesNotContain("https://www.astrana.org/releases")
                    .doesNotContain("v1.2.3");
        }
    }
}
