package org.astrana.trustedattestation.config;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import com.sun.net.httpserver.HttpServer;
import java.net.InetSocketAddress;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.atomic.AtomicReference;
import java.util.function.UnaryOperator;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;
import org.springframework.security.oauth2.client.registration.ClientRegistration;
import org.springframework.security.oauth2.client.registration.ClientRegistrations;
import org.springframework.web.client.RestClient;

/**
 * An issuer configured with or without a trailing slash works against a provider that states it the other
 * way, as it does on the other two implementations.
 *
 * <p>Spring compares the configured issuer with the discovery document's exactly, and the ID token's
 * {@code iss} with the registration's exactly, so the reconciliation has to leave Spring holding the provider's own
 * spelling. The first half of these tests pins the reconciliation rule on its own. The second half runs it
 * against a throwaway provider serving a real discovery document and then hands the result to the very call
 * Spring Boot builds registrations with, so what is asserted is that Spring accepts it -- in both directions.
 */
class IssuerUriTest {

    private static HttpServer provider;
    private static String base;

    /** What the throwaway provider states as its issuer, set per test. */
    private static final AtomicReference<String> STATED = new AtomicReference<>();

    private static final UnaryOperator<String> FETCH =
            url -> RestClient.create().get().uri(url).retrieve().body(String.class);

    @BeforeAll
    static void startProvider() throws Exception {
        provider = HttpServer.create(new InetSocketAddress("127.0.0.1", 0), 0);
        base = "http://127.0.0.1:" + provider.getAddress().getPort() + "/realms/ata";

        // Answers the discovery document wherever under the realm it is asked for, including the
        // double-slash form Spring builds from an issuer that ends in a slash, as real providers do.
        provider.createContext("/realms/ata", exchange -> {
            if (!exchange.getRequestURI().getPath().endsWith(IssuerUri.DISCOVERY_PATH)) {
                exchange.sendResponseHeaders(404, -1);
                exchange.close();
                return;
            }

            byte[] body = discoveryDocument(STATED.get()).getBytes(StandardCharsets.UTF_8);
            exchange.getResponseHeaders().add("Content-Type", "application/json");
            exchange.sendResponseHeaders(200, body.length);
            exchange.getResponseBody().write(body);
            exchange.close();
        });
        provider.start();
    }

    @AfterAll
    static void stopProvider() {
        provider.stop(0);
    }

    private static String discoveryDocument(String issuer) {
        return """
                {
                  "issuer": "%s",
                  "authorization_endpoint": "%s/protocol/openid-connect/auth",
                  "token_endpoint": "%s/protocol/openid-connect/token",
                  "userinfo_endpoint": "%s/protocol/openid-connect/userinfo",
                  "end_session_endpoint": "%s/protocol/openid-connect/logout",
                  "jwks_uri": "%s/protocol/openid-connect/certs",
                  "response_types_supported": ["code"],
                  "subject_types_supported": ["public"],
                  "id_token_signing_alg_values_supported": ["RS256"],
                  "grant_types_supported": ["authorization_code"],
                  "scopes_supported": ["openid", "profile", "email"],
                  "token_endpoint_auth_methods_supported": ["client_secret_basic"]
                }
                """.formatted(issuer, base, base, base, base, base);
    }

    // ---------------------------------------------------------------------------------------------
    // The rule on its own
    // ---------------------------------------------------------------------------------------------

    @Test
    void theDiscoveryUrlIsTheSameWhicheverWayTheIssuerIsWritten() {
        assertThat(IssuerUri.discoveryUrl("https://idp.example/realms/x"))
                .isEqualTo("https://idp.example/realms/x/.well-known/openid-configuration");
        assertThat(IssuerUri.discoveryUrl("https://idp.example/realms/x/"))
                .isEqualTo("https://idp.example/realms/x/.well-known/openid-configuration");
    }

    @Test
    void theProvidersSpellingWinsWhenTheTwoDifferOnlyByATrailingSlash() {
        assertThat(IssuerUri.reconcile("https://idp.example/o/x", "https://idp.example/o/x/"))
                .isEqualTo("https://idp.example/o/x/");
        assertThat(IssuerUri.reconcile("https://idp.example/realms/x/", "https://idp.example/realms/x"))
                .isEqualTo("https://idp.example/realms/x");
    }

    @Test
    void anyOtherDifferenceLeavesTheConfiguredValueForSpringToRefuse() {
        // A genuinely different issuer is a misconfiguration this must not paper over: the configured value
        // stands, and Spring's own exact check then stops the application with its established message.
        assertThat(IssuerUri.reconcile("https://idp.example/realms/x", "https://other.example/realms/x"))
                .isEqualTo("https://idp.example/realms/x");
        assertThat(IssuerUri.reconcile("https://idp.example/realms/x", null)).isEqualTo("https://idp.example/realms/x");
        assertThat(IssuerUri.reconcile("https://idp.example/realms/x", "https://idp.example/realms/x"))
                .isEqualTo("https://idp.example/realms/x");
    }

    @Test
    void anUnreadableDiscoveryDocumentLeavesTheConfiguredValueAlone() {
        // Fail-fast on an unreachable IdP is Spring's job and stays Spring's: this only ever respells.
        UnaryOperator<String> dead = url -> {
            throw new IllegalStateException("connection refused");
        };

        assertThat(IssuerUri.asStatedByProvider("https://idp.example/realms/x", dead))
                .isEqualTo("https://idp.example/realms/x");
        assertThat(IssuerUri.asStatedByProvider("https://idp.example/realms/x", url -> "not json"))
                .isEqualTo("https://idp.example/realms/x");
    }

    // ---------------------------------------------------------------------------------------------
    // Against a provider, through the call Spring Boot builds registrations with
    // ---------------------------------------------------------------------------------------------

    @Test
    void anIssuerConfiguredWithoutTheSlashWorksAgainstAProviderThatStatesOne() {
        // Authentik's shape: the provider's issuer ends in a slash, the operator left it off.
        STATED.set(base + "/");

        String reconciled = IssuerUri.asStatedByProvider(base, FETCH);

        assertThat(reconciled).isEqualTo(base + "/");
        ClientRegistration registration = registrationFrom(reconciled);
        assertThat(registration.getProviderDetails().getIssuerUri()).isEqualTo(base + "/");
    }

    @Test
    void anIssuerConfiguredWithTheSlashWorksAgainstAProviderThatStatesNone() {
        // Keycloak's shape: no slash from the provider, but the operator wrote one.
        STATED.set(base);

        String reconciled = IssuerUri.asStatedByProvider(base + "/", FETCH);

        assertThat(reconciled).isEqualTo(base);
        assertThat(registrationFrom(reconciled).getProviderDetails().getIssuerUri())
                .isEqualTo(base);
    }

    @Test
    void withoutReconciliationSpringRefusesTheMismatchWhichIsWhyThisExists() {
        // Without reconciliation, Spring compares the two exactly and refuses the mismatch.
        STATED.set(base + "/");

        assertThatThrownBy(() -> registrationFrom(base))
                .isInstanceOf(IllegalStateException.class)
                .hasMessageContaining("did not match the requested issuer");
    }

    @Test
    void aMatchingIssuerPassesThroughUnchanged() {
        STATED.set(base);

        assertThat(IssuerUri.asStatedByProvider(base, FETCH)).isEqualTo(base);
    }

    /** Exactly what Boot's property mapper calls for an {@code issuer-uri}, with the registration's own details added. */
    private static ClientRegistration registrationFrom(String issuer) {
        return ClientRegistrations.fromIssuerLocation(issuer)
                .registrationId("keycloak")
                .clientId("trusted-attestation")
                .clientSecret("secret")
                .build();
    }
}
