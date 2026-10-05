package org.astrana.trustedattestation.web;

import jakarta.servlet.http.HttpServletRequest;
import java.io.IOException;
import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;
import java.time.Duration;
import java.util.LinkedHashMap;
import java.util.Map;
import org.astrana.trustedattestation.service.LocalizationSettings;
import org.springframework.http.HttpHeaders;
import org.springframework.http.HttpStatus;
import org.springframework.http.MediaType;
import org.springframework.http.ResponseEntity;
import org.springframework.stereotype.Controller;
import org.springframework.web.bind.annotation.PostMapping;

/**
 * Where the language switcher posts. It stores the chosen locale in a plain cookie, validated against the
 * offered set so a forged value cannot render an unsupported language, and returns to the page the member
 * was on.
 *
 * <p>No cross-site request forgery (CSRF) token, because changing one's own display language is harmless,
 * and the switcher is on the public landing page too, which carries no session to hold a token. A
 * cross-site form can still submit the switcher, and SameSite=Lax on the cookie does not stop that, but the
 * most such a request changes is the display language. The endpoint is exempted from the CSRF filter and
 * open to anonymous callers in {@code SecurityConfig} for the same reason. Kept identical across the three
 * implementations.
 */
@Controller
public class LanguageController {

    /** A year, in seconds: the switcher's choice should outlast any session. */
    static final long MAX_AGE_SECONDS = Duration.ofDays(365).toSeconds();

    /** Where the member lands when the form named nowhere safe to return to: the landing page, on all three. */
    static final String FALLBACK_PATH = "/";

    /**
     * The most a form posted here may be, in bytes. The switcher posts two short fields, so anything near
     * this is not the switcher, and a body over it is read no further.
     */
    static final int MAX_FORM_BYTES = 16 * 1024;

    private final LocalizationSettings localization;

    public LanguageController(LocalizationSettings localization) {
        this.localization = localization;
    }

    /**
     * Reads the form body only. Not the query string and not JSON: the three implementations read the
     * switcher's post the same way, and a value a link could carry in a query string must not set a cookie.
     * A request with no body, or a body that is not a form, sets no cookie and redirects to the fallback,
     * never a 500.
     */
    @PostMapping("/set-language")
    public ResponseEntity<Void> setLanguage(HttpServletRequest request) {
        Map<String, String> form = formBody(request);
        String locale = form.get("locale");
        ResponseEntity.BodyBuilder response = ResponseEntity.status(HttpStatus.FOUND);

        // Only a locale this instance offers is stored, in its shipped spelling. The value is matched as
        // posted, case aside, with no trimming, so a padded value such as " fr" is refused here as it is on
        // the other two implementations. Anything else leaves the cookie as it was and the member returns
        // to the page unchanged.
        localization
                .offered(locale)
                .filter(offered -> offered.equalsIgnoreCase(locale))
                .ifPresent(offered -> response.header(HttpHeaders.SET_COOKIE, cookie(offered)));

        return response.header(HttpHeaders.LOCATION, safeReturnPath(form.get("next")))
                .build();
    }

    /**
     * The cookie with the attributes the other two implementations set: HttpOnly, Secure, SameSite=Lax, a
     * year's Max-Age, on the root path, and no Domain. Written by hand rather than through a cookie builder
     * so the attributes are exactly these. Symfony, under PHP, also adds an Expires date alongside Max-Age,
     * which the differential suite ignores because it depends on the clock.
     */
    private static String cookie(String locale) {
        return MemberLocaleResolver.COOKIE_NAME + "=" + locale + "; Max-Age=" + MAX_AGE_SECONDS
                + "; Path=/; Secure; HttpOnly; SameSite=Lax";
    }

    /**
     * Only a path on this site is followed. A redirect target is attacker-reachable through the form's
     * hidden field, so anything that is not a plain local path lands on {@link #FALLBACK_PATH} instead.
     * Accepted is a value that starts with {@code /}, does not start with {@code //} or {@code /\}, and
     * holds no backslash and no control character (below 0x20, or 0x7F). That refuses an absent, relative
     * or scheme-relative ({@code //host}) value, a backslash anywhere, which a browser may read as a slash,
     * and a line break or tab, which has no place in a Location header. The same rule on all three
     * implementations.
     */
    static String safeReturnPath(String next) {
        if (next == null || !next.startsWith("/") || next.startsWith("//") || next.startsWith("/\\")) {
            return FALLBACK_PATH;
        }

        for (int i = 0; i < next.length(); i++) {
            char c = next.charAt(i);
            if (c == '\\' || c < 0x20 || c == 0x7F) {
                return FALLBACK_PATH;
            }
        }

        return next;
    }

    /**
     * The fields of an {@code application/x-www-form-urlencoded} body, first value of each name, or an
     * empty map for any other body or none. Parsed from the raw body rather than through the servlet
     * parameter API, which folds the query string in with the form and cannot tell the two apart. A field
     * that will not decode is left out, so a malformed escape sets no cookie and still redirects.
     */
    static Map<String, String> formBody(HttpServletRequest request) {
        if (!isForm(request.getContentType())) {
            return Map.of();
        }

        String body;
        try {
            byte[] bytes = request.getInputStream().readNBytes(MAX_FORM_BYTES + 1);
            if (bytes.length > MAX_FORM_BYTES) {
                return Map.of();
            }
            body = new String(bytes, StandardCharsets.UTF_8);
        } catch (IOException _) {
            return Map.of();
        }

        Map<String, String> fields = new LinkedHashMap<>();
        for (String pair : body.split("&")) {
            if (pair.isEmpty()) {
                continue;
            }

            int separator = pair.indexOf('=');
            String name = separator < 0 ? pair : pair.substring(0, separator);
            String value = separator < 0 ? "" : pair.substring(separator + 1);
            try {
                fields.putIfAbsent(
                        URLDecoder.decode(name, StandardCharsets.UTF_8),
                        URLDecoder.decode(value, StandardCharsets.UTF_8));
            } catch (IllegalArgumentException _) {
                // Not a field this endpoint can read.
            }
        }

        return fields;
    }

    private static boolean isForm(String contentType) {
        if (contentType == null || contentType.isBlank()) {
            return false;
        }

        try {
            return MediaType.APPLICATION_FORM_URLENCODED.equalsTypeAndSubtype(MediaType.parseMediaType(contentType));
        } catch (IllegalArgumentException _) {
            return false;
        }
    }
}
