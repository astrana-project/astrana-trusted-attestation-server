package org.astrana.trustedattestation.config;

import static org.assertj.core.api.Assertions.assertThat;

import java.util.LinkedHashSet;
import java.util.List;
import java.util.Set;
import org.junit.jupiter.api.Test;
import org.springframework.boot.security.oauth2.client.autoconfigure.OAuth2ClientProperties;
import org.springframework.security.oauth2.client.registration.ClientRegistration;

/**
 * Sign-in always asks for {@code openid}, {@code profile} and {@code email}, with any configured scope added
 * after them, as it does on the other two implementations.
 */
class OidcScopesTest {

    @Test
    void noConfiguredScopeAsksForTheThreeDefaults() {
        assertThat(OidcScopes.withDefaults(null)).containsExactly("openid", "profile", "email");
        assertThat(OidcScopes.withDefaults(Set.of())).containsExactly("openid", "profile", "email");
    }

    @Test
    void configuredScopesComeAfterTheDefaultsWithoutRepeats() {
        assertThat(OidcScopes.withDefaults(List.of("ata", "email", "offline_access", "openid")))
                .containsExactly("openid", "profile", "email", "ata", "offline_access");
    }

    @Test
    void blankAndPaddedScopesAreTidied() {
        assertThat(OidcScopes.withDefaults(List.of(" ata ", "", "  ", "ata")))
                .containsExactly("openid", "profile", "email", "ata");
    }

    @Test
    void aRegistrationWithNoScopeAsksForTheThreeDefaults() {
        ClientRegistration registration = registrationWith(null);

        assertThat(registration.getScopes()).containsExactly("openid", "profile", "email");
    }

    @Test
    void aRegistrationWithExtraScopesAsksForTheDefaultsAndTheExtras() {
        ClientRegistration registration = registrationWith(new LinkedHashSet<>(List.of("ata", "openid")));

        assertThat(registration.getScopes()).containsExactly("openid", "profile", "email", "ata");
    }

    /** The registration the configuration's own repository builds from these properties. */
    private static ClientRegistration registrationWith(Set<String> scope) {
        OAuth2ClientProperties properties = new OAuth2ClientProperties();

        OAuth2ClientProperties.Provider provider = new OAuth2ClientProperties.Provider();
        provider.setAuthorizationUri("https://idp.example/auth");
        provider.setTokenUri("https://idp.example/token");
        provider.setJwkSetUri("https://idp.example/certs");
        properties.getProvider().put("keycloak", provider);

        OAuth2ClientProperties.Registration registration = new OAuth2ClientProperties.Registration();
        registration.setClientId("trusted-attestation");
        registration.setClientSecret("secret");
        registration.setAuthorizationGrantType("authorization_code");
        registration.setRedirectUri("{baseUrl}/login/oauth2/code/{registrationId}");
        registration.setScope(scope);
        properties.getRegistration().put("keycloak", registration);

        return new OidcClientConfig().clientRegistrationRepository(properties).findByRegistrationId("keycloak");
    }
}
