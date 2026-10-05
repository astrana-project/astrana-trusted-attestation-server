package org.astrana.trustedattestation.config;

import com.fasterxml.jackson.databind.ObjectMapper;
import jakarta.servlet.http.Cookie;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import java.util.Base64;
import java.util.Map;
import org.springframework.http.HttpHeaders;
import org.springframework.http.ResponseCookie;

/**
 * A short-lived cookie that carries the fields needed to correlate a SAML message coming back from the
 * identity provider with the one this service sent.
 *
 * <p>The session cookie is {@code SameSite=Lax}, so a browser leaves it off the cross-site POST an identity
 * provider uses to deliver a SAML response. Anything kept in the session is therefore missing exactly when
 * it is needed. This cookie is {@code SameSite=None} so it survives that POST, while the session cookie stays
 * {@code Lax}. The .NET implementation gets the same shape from Sustainsys.
 *
 * <p>The fields are stored as JSON and read back as a plain map, never Java-deserialised, so a forged cookie
 * cannot be a deserialisation gadget. Forging one buys nothing on its own either: the message it would
 * correlate to still has to carry a valid identity provider signature.
 */
final class SamlCorrelationCookie {

    private static final int MAX_AGE_SECONDS = 300; // one round-trip to the provider, not indefinite
    private static final ObjectMapper JSON = new ObjectMapper();

    private final String name;

    SamlCorrelationCookie(String name) {
        this.name = name;
    }

    void write(HttpServletResponse response, Map<String, String> fields) {
        String value;
        try {
            value = Base64.getUrlEncoder().withoutPadding().encodeToString(JSON.writeValueAsBytes(fields));
        } catch (Exception e) {
            throw new IllegalStateException("could not serialise the SAML correlation fields", e);
        }
        response.addHeader(
                HttpHeaders.SET_COOKIE, cookie(value, MAX_AGE_SECONDS).toString());
    }

    void clear(HttpServletResponse response) {
        response.addHeader(HttpHeaders.SET_COOKIE, cookie("", 0).toString());
    }

    /** The stored fields, or null when the cookie is absent, malformed or tampered with. */
    @SuppressWarnings("unchecked")
    Map<String, String> read(HttpServletRequest request) {
        String value = rawValue(request);
        if (value == null) {
            return null;
        }
        try {
            return JSON.readValue(Base64.getUrlDecoder().decode(value), Map.class);
        } catch (Exception e) {
            return null;
        }
    }

    private ResponseCookie cookie(String value, int maxAgeSeconds) {
        return ResponseCookie.from(name, value)
                .httpOnly(true)
                .secure(true)
                .sameSite("None") // the SAML message is a cross-site POST; Lax would not be sent
                .path("/")
                .maxAge(maxAgeSeconds)
                .build();
    }

    private String rawValue(HttpServletRequest request) {
        Cookie[] cookies = request.getCookies();
        if (cookies == null) {
            return null;
        }
        for (Cookie cookie : cookies) {
            if (name.equals(cookie.getName()) && !cookie.getValue().isEmpty()) {
                return cookie.getValue();
            }
        }
        return null;
    }
}
