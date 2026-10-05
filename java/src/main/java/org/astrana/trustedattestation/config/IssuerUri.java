package org.astrana.trustedattestation.config;

import java.util.Map;
import java.util.function.Function;
import org.springframework.boot.json.JsonParserFactory;

/**
 * Tolerates an OpenID Connect issuer configured with or without a trailing slash.
 *
 * <p>An issuer identifier means the same thing either way, but providers differ in how they spell their
 * own: Keycloak states {@code https://idp/realms/x}, Authentik states {@code https://idp/application/o/x/}.
 * Spring builds the registration from the configured value and refuses to start unless the discovery
 * document's {@code issuer} matches it character for character, and its ID-token validator later compares
 * the token's {@code iss} the same way. So an operator who writes the issuer the other way round from the
 * provider gets a stopped application, where the .NET and PHP implementations simply sign in.
 *
 * <p>The least invasive answer is to let the provider spell it. Before the registration is built, the
 * discovery document is read once from the one URL both spellings share, and when its stated issuer differs
 * from the configured value only by that slash, the stated form replaces the configured one. The provider's
 * own spelling is what it puts in every token, so both of Spring's exact comparisons then hold. Any other
 * difference, or a document that cannot be read, leaves the configured value alone, and Spring's own
 * startup fetch reports it exactly as it always has.
 */
final class IssuerUri {

    static final String DISCOVERY_PATH = "/.well-known/openid-configuration";

    private IssuerUri() {}

    /**
     * The configured issuer, respelt the way its provider states it when the two differ only by a trailing
     * slash. {@code fetch} reads a URL and returns the body, and may throw for an unreachable provider.
     */
    static String asStatedByProvider(String configured, Function<String, String> fetch) {
        try {
            return reconcile(configured, statedIssuer(fetch.apply(discoveryUrl(configured))));
        } catch (RuntimeException unreadable) {
            // Not this code's failure to report: Spring fetches the same document moments later and
            // stops the application with its own, established message.
            return configured;
        }
    }

    /** The discovery document's location, the same whether the issuer was written with the slash or not. */
    static String discoveryUrl(String issuer) {
        return withoutTrailingSlash(issuer) + DISCOVERY_PATH;
    }

    /**
     * The provider's spelling when it differs from the configured one only by a trailing slash, otherwise
     * the configured value unchanged.
     */
    static String reconcile(String configured, String stated) {
        if (stated == null || stated.isBlank() || stated.equals(configured)) {
            return configured;
        }

        return withoutTrailingSlash(stated).equals(withoutTrailingSlash(configured)) ? stated : configured;
    }

    private static String statedIssuer(String discoveryDocument) {
        Map<String, Object> metadata = JsonParserFactory.getJsonParser().parseMap(discoveryDocument);
        Object issuer = metadata.get("issuer");
        return issuer == null ? null : String.valueOf(issuer);
    }

    private static String withoutTrailingSlash(String value) {
        String trimmed = value.trim();
        while (trimmed.endsWith("/")) {
            trimmed = trimmed.substring(0, trimmed.length() - 1);
        }

        return trimmed;
    }
}
