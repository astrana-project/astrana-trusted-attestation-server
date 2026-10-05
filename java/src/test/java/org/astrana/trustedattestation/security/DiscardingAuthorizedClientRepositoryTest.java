package org.astrana.trustedattestation.security;

import static org.assertj.core.api.Assertions.assertThat;

import java.time.Instant;
import org.junit.jupiter.api.Test;
import org.springframework.mock.web.MockHttpServletRequest;
import org.springframework.mock.web.MockHttpServletResponse;
import org.springframework.security.authentication.TestingAuthenticationToken;
import org.springframework.security.core.Authentication;
import org.springframework.security.oauth2.client.OAuth2AuthorizedClient;
import org.springframework.security.oauth2.client.registration.ClientRegistration;
import org.springframework.security.oauth2.core.AuthorizationGrantType;
import org.springframework.security.oauth2.core.OAuth2AccessToken;
import org.springframework.security.oauth2.core.OAuth2RefreshToken;

/**
 * No access or refresh token survives sign-in.
 *
 * <p>Spring's login filter saves the tokens the IdP returned through this repository; the default keeps
 * them in memory for the life of the process. Record 21 says no token is kept beyond what signing out
 * needs, and signing out needs only the ID token, which lives on the principal. So saving here must be a
 * no-op, and nothing saved must ever be loadable again.
 */
class DiscardingAuthorizedClientRepositoryTest {

    private final DiscardingAuthorizedClientRepository repository = new DiscardingAuthorizedClientRepository();

    private static OAuth2AuthorizedClient authorizedClient() {
        ClientRegistration registration = ClientRegistration.withRegistrationId("keycloak")
                .clientId("trusted-attestation")
                .clientSecret("secret")
                .authorizationGrantType(AuthorizationGrantType.AUTHORIZATION_CODE)
                .redirectUri("https://app.example/login/oauth2/code/keycloak")
                .authorizationUri("https://idp.example/auth")
                .tokenUri("https://idp.example/token")
                .build();
        OAuth2AccessToken access = new OAuth2AccessToken(
                OAuth2AccessToken.TokenType.BEARER,
                "access-token",
                Instant.now(),
                Instant.now().plusSeconds(300));
        OAuth2RefreshToken refresh = new OAuth2RefreshToken("refresh-token", Instant.now());

        return new OAuth2AuthorizedClient(registration, "alice", access, refresh);
    }

    @Test
    void savedTokensCannotBeLoadedBackBecauseTheyWereNeverKept() {
        Authentication alice = new TestingAuthenticationToken("alice", null);
        MockHttpServletRequest request = new MockHttpServletRequest();
        MockHttpServletResponse response = new MockHttpServletResponse();

        repository.saveAuthorizedClient(authorizedClient(), alice, request, response);

        assertThat((OAuth2AuthorizedClient) repository.loadAuthorizedClient("keycloak", alice, request))
                .isNull();
        // Nothing is written to the response either: the tokens do not go into a cookie instead.
        assertThat(response.getHeaderNames()).isEmpty();
        assertThat(request.getSession(false)).isNull();
    }

    @Test
    void removingWhatWasNeverKeptIsHarmless() {
        Authentication alice = new TestingAuthenticationToken("alice", null);

        repository.removeAuthorizedClient(
                "keycloak", alice, new MockHttpServletRequest(), new MockHttpServletResponse());

        assertThat((OAuth2AuthorizedClient)
                        repository.loadAuthorizedClient("keycloak", alice, new MockHttpServletRequest()))
                .isNull();
    }
}
