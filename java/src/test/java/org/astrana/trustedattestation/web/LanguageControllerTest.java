package org.astrana.trustedattestation.web;

import static org.assertj.core.api.Assertions.assertThat;

import java.nio.charset.StandardCharsets;
import java.util.List;
import java.util.Map;
import org.astrana.trustedattestation.service.LocalizationSettings;
import org.junit.jupiter.api.Test;
import org.springframework.http.HttpHeaders;
import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.mock.web.MockHttpServletRequest;

/**
 * The language switcher's endpoint.
 *
 * <p>Two things can go wrong with it, and both are pinned here. The cookie it sets is the one thing
 * SameSite=Lax and HttpOnly protect, so its attributes are asserted literally rather than parsed. And its
 * redirect target comes from a hidden form field anyone can edit, so only a local path may be followed:
 * anything else lands on the landing page. A locale this instance does not offer sets no cookie at all
 * and still redirects, so a forged value can neither render an unsupported language nor break the page. And
 * only the form body is read, never the query string, so a link cannot set the cookie.
 */
class LanguageControllerTest {

    private final LanguageController controller = new LanguageController(new LocalizationSettings(
            List.of("en", "fr"), List.of("en", "fr", "de"), "en", Map.of("en", "English", "fr", "Français")));

    private static String setCookie(ResponseEntity<Void> response) {
        List<String> cookies = response.getHeaders().get(HttpHeaders.SET_COOKIE);
        return cookies == null ? null : String.join("\n", cookies);
    }

    private static String location(ResponseEntity<Void> response) {
        return response.getHeaders().getFirst(HttpHeaders.LOCATION);
    }

    /** A form post as the switcher sends it, with the two fields already percent-encoded by the caller. */
    private static MockHttpServletRequest form(String body) {
        MockHttpServletRequest request = new MockHttpServletRequest("POST", "/set-language");
        request.setContentType("application/x-www-form-urlencoded");
        request.setContent(body.getBytes(StandardCharsets.UTF_8));
        return request;
    }

    private static String encoded(String value) {
        return java.net.URLEncoder.encode(value, StandardCharsets.UTF_8);
    }

    private ResponseEntity<Void> post(String locale, String next) {
        StringBuilder body = new StringBuilder();
        if (locale != null) {
            body.append("locale=").append(encoded(locale));
        }
        if (next != null) {
            body.append(body.isEmpty() ? "" : "&").append("next=").append(encoded(next));
        }
        return controller.setLanguage(form(body.toString()));
    }

    @Test
    void anOfferedLocaleIsStoredInAHttpOnlySecureLaxCookieForAYear() {
        ResponseEntity<Void> response = post("fr", "/me");

        assertThat(response.getStatusCode()).isEqualTo(HttpStatus.FOUND);
        assertThat(setCookie(response))
                .isEqualTo("ata_locale=fr; Max-Age=31536000; Path=/; Secure; HttpOnly; SameSite=Lax");
        assertThat(response.getHeaders().get(HttpHeaders.SET_COOKIE)).hasSize(1);
    }

    @Test
    void aLocaleThatIsNotOfferedSetsNoCookieAndStillRedirects() {
        // "de" is shipped but not offered by this organisation; "xx" is nothing at all. Neither may be
        // stored, and the member still gets back to where they were.
        for (String locale : new String[] {"de", "xx", "", null}) {
            ResponseEntity<Void> response = post(locale, "/");

            assertThat(response.getStatusCode()).as("status for %s", locale).isEqualTo(HttpStatus.FOUND);
            assertThat(setCookie(response)).as("cookie for %s", locale).isNull();
            assertThat(location(response)).isEqualTo("/");
        }
    }

    @Test
    void aRegionalVariantOfAnOfferedLocaleIsNotStoredAsIs() {
        // The switcher only ever posts offered values, so fr-CA here is a crafted request. It is served by
        // French if it arrives in a cookie, but the endpoint stores only values from the offered list.
        assertThat(setCookie(post("fr-CA", "/"))).isNull();
    }

    @Test
    void theOfferedSpellingIsStoredWhateverCaseWasPosted() {
        assertThat(setCookie(post("FR", "/"))).startsWith("ata_locale=fr;");
    }

    @Test
    void aPaddedLocaleIsMatchedAsPostedAndSetsNoCookie() {
        // No trimming, the same as the other two implementations.
        for (String locale : new String[] {" fr", "fr ", "\tfr"}) {
            ResponseEntity<Void> response = post(locale, "/me");

            assertThat(setCookie(response)).as("cookie for '%s'", locale).isNull();
            assertThat(location(response)).isEqualTo("/me");
        }
    }

    @Test
    void theRedirectReturnsToTheLocalPathPostedIncludingItsQueryString() {
        assertThat(location(post("fr", "/?signedout=true"))).isEqualTo("/?signedout=true");
    }

    @Test
    void anythingOtherThanALocalPathLandsOnTheLandingPage() {
        // Absent, relative, absolute to another host, scheme-relative with slashes or a backslash, a
        // backslash anywhere, which a browser may read as a slash, or a control character, which has no
        // place in a Location header: none of these may be followed.
        for (String next : new String[] {
            null,
            "",
            "me",
            "https://evil.example/",
            "//evil.example/x",
            "/\\evil.example",
            "/me\\x",
            "/me\tx",
            "/me\r\nSet-Cookie: a=b",
            "/me\u007f",
            "/me\u0000"
        }) {
            assertThat(location(post("fr", next))).as("redirect for %s", next).isEqualTo("/");
        }
    }

    @Test
    void aNonAsciiPathIsFollowedAsItIs() {
        // Only characters below 0x20 and 0x7F are refused. A path with other characters is the member's own.
        assertThat(location(post("fr", "/me?q=café"))).isEqualTo("/me?q=café");
    }

    @Test
    void theQueryStringIsNeverRead() {
        // A link carrying ?locale=fr must not set the cookie: only the form body counts, as on the other two.
        MockHttpServletRequest request = form("next=%2F");
        request.setQueryString("locale=fr&next=%2Fme");
        request.addParameter("locale", "fr");
        request.addParameter("next", "/me");

        ResponseEntity<Void> response = controller.setLanguage(request);

        assertThat(setCookie(response)).isNull();
        assertThat(location(response)).isEqualTo("/");
    }

    @Test
    void aRequestWithNoBodyRedirectsToTheFallbackWithNoCookie() {
        MockHttpServletRequest request = new MockHttpServletRequest("POST", "/set-language");

        ResponseEntity<Void> response = controller.setLanguage(request);

        assertThat(response.getStatusCode()).isEqualTo(HttpStatus.FOUND);
        assertThat(setCookie(response)).isNull();
        assertThat(location(response)).isEqualTo("/");
    }

    @Test
    void aBodyThatIsNotAFormRedirectsToTheFallbackWithNoCookie() {
        MockHttpServletRequest request = new MockHttpServletRequest("POST", "/set-language");
        request.setContentType("application/json");
        request.setContent("{\"locale\":\"fr\",\"next\":\"/me\"}".getBytes(StandardCharsets.UTF_8));

        ResponseEntity<Void> response = controller.setLanguage(request);

        assertThat(response.getStatusCode()).isEqualTo(HttpStatus.FOUND);
        assertThat(setCookie(response)).isNull();
        assertThat(location(response)).isEqualTo("/");
    }

    @Test
    void aFormWithAMalformedEscapeSetsNoCookieAndStillRedirects() {
        ResponseEntity<Void> response = controller.setLanguage(form("locale=%zz&next=%2Fme"));

        assertThat(response.getStatusCode()).isEqualTo(HttpStatus.FOUND);
        assertThat(setCookie(response)).isNull();
        assertThat(location(response)).isEqualTo("/me");
    }

    @Test
    void theFirstValueOfARepeatedFieldIsTheOneRead() {
        assertThat(setCookie(controller.setLanguage(form("locale=fr&locale=de&next=%2F"))))
                .startsWith("ata_locale=fr;");
    }
}
