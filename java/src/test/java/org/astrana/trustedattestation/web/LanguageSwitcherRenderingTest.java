package org.astrana.trustedattestation.web;

import static org.assertj.core.api.Assertions.assertThat;

import java.util.List;
import java.util.Map;
import org.astrana.trustedattestation.service.LocalizationSettings;
import org.junit.jupiter.api.Test;

/**
 * The language switcher as the two pages actually render it.
 *
 * <p>The controller tests pin what the model carries; this renders the real templates with the real strings
 * file through the same engine the application uses, because the markup has to come out the same as the
 * .NET partial's -- a native disclosure, one submit button per offered locale posting to /set-language, the
 * current one marked -- and because a slip in the fragment's syntax would otherwise only be found by the
 * first member to load the page. It also pins what must be absent: no anti-forgery field in the form, since
 * one would plant a session on the anonymous landing page, and no switcher at all when there is no choice.
 */
class LanguageSwitcherRenderingTest {

    private static final LocalizationSettings ENGLISH_AND_FRENCH = new LocalizationSettings(
            List.of("en", "fr"),
            TemplateRendering.STRINGS.supportedLocales(),
            "en",
            Map.of("en", "English", "fr", "Français"));

    private static final LocalizationSettings ENGLISH_ONLY = new LocalizationSettings(
            List.of("en"), TemplateRendering.STRINGS.supportedLocales(), "en", Map.of("en", "English"));

    /** Renders a page with the model the controllers build, for a request to {@code path}. */
    private static String render(String template, String path, LocalizationSettings localization, String rendered) {
        return TemplateRendering.render(template, path, localization, rendered, Map.of());
    }

    @Test
    void theLandingPageOffersEachLocaleAsASubmitButtonAndMarksTheCurrentOne() {
        String html = render("landing", "/", ENGLISH_AND_FRENCH, "fr");

        assertThat(html).contains("<div class=\"ata-topbar mb-3\">");
        assertThat(html).contains("<details class=\"ata-language\">");
        assertThat(html).contains("<form method=\"post\" action=\"/set-language\" class=\"ata-language-menu\">");
        assertThat(html).contains("name=\"next\" value=\"/\"");

        // One button per offered locale, each carrying the locale as the submitted value and its own name
        // as the label. The page is French, so French is the one marked current, in bold and for assistive
        // technology alike.
        assertThat(html).contains("name=\"locale\" value=\"en\"").contains(">English</button>");
        assertThat(html).contains("name=\"locale\" value=\"fr\"").contains(">Français</button>");
        assertThat(html).containsOnlyOnce("aria-current=\"true\"");
        assertThat(html).containsOnlyOnce("fw-bold");
        assertThat(html.indexOf("fw-bold")).isGreaterThan(html.indexOf("value=\"en\""));

        // The summary names the current language, with a hidden label in the page's language for a screen
        // reader to announce first.
        assertThat(html).contains("<span class=\"visually-hidden\">Langue: </span>");
        assertThat(html).contains("<span>Français</span>");
    }

    @Test
    void theSwitcherFormCarriesNoAntiForgeryField() {
        // A token would need a session to live in, and the public landing page must set no cookie.
        String html = render("landing", "/", ENGLISH_AND_FRENCH, "en");

        assertThat(html).doesNotContain("_csrf");
    }

    @Test
    void theSwitcherIsAbsentWhenOnlyOneLocaleIsOffered() {
        String html = render("landing", "/", ENGLISH_ONLY, "en");

        assertThat(html).contains("<div class=\"ata-topbar mb-3\">");
        assertThat(html).doesNotContain("ata-language");
        assertThat(html).doesNotContain("/set-language");
    }

    @Test
    void theSignedOutPageReturnsToItself() {
        String html = render("landing", "/signed-out", ENGLISH_AND_FRENCH, "en");

        assertThat(html).contains("name=\"next\" value=\"/signed-out\"");
    }

    @Test
    void theMemberPageCarriesTheSameSwitcherReturningToItself() {
        String html = render("me", "/me", ENGLISH_AND_FRENCH, "en");

        assertThat(html).contains("<details class=\"ata-language\">");
        assertThat(html).contains("name=\"next\" value=\"/me\"");
        assertThat(html).contains("<span class=\"visually-hidden\">Language: </span>");
        assertThat(html).containsOnlyOnce("aria-current=\"true\"");
    }

    @Test
    void theFooterLicenceLabelIsTheTranslatedString() {
        // The footer reads in the member's language like the rest of the page, from the strings file's
        // licence_link, on both pages. The licence page itself keeps the attribution's English label.
        String licenceLink = TemplateRendering.STRINGS.all("fr").get("licence_link");

        assertThat(render("landing", "/", ENGLISH_AND_FRENCH, "fr")).contains(">" + licenceLink + "</a>");
        assertThat(render("me", "/me", ENGLISH_AND_FRENCH, "fr")).contains(">" + licenceLink + "</a>");
    }
}
