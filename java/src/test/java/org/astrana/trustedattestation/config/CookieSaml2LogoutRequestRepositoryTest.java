package org.astrana.trustedattestation.config;

import static org.assertj.core.api.Assertions.assertThat;

import jakarta.servlet.http.Cookie;
import org.junit.jupiter.api.Test;
import org.springframework.mock.web.MockHttpServletRequest;
import org.springframework.mock.web.MockHttpServletResponse;
import org.springframework.security.saml2.provider.service.authentication.logout.Saml2LogoutRequest;
import org.springframework.security.saml2.provider.service.registration.InMemoryRelyingPartyRegistrationRepository;
import org.springframework.security.saml2.provider.service.registration.RelyingPartyRegistration;
import org.springframework.security.saml2.provider.service.registration.Saml2MessageBinding;

/**
 * The outgoing LogoutRequest survives in its own {@code SameSite=None} cookie, so the identity provider's
 * cross-site LogoutResponse can be matched to it, and only a response carrying the same RelayState is.
 */
class CookieSaml2LogoutRequestRepositoryTest {

    private static final String COOKIE = "ata_saml_logout";

    private final RelyingPartyRegistration registration = RelyingPartyRegistration.withRegistrationId("keycloak")
            .entityId("https://sp.example/saml2/service-provider-metadata/keycloak")
            .assertingPartyMetadata(party -> party.entityId("https://idp.example")
                    .singleSignOnServiceLocation("https://idp.example/sso")
                    .singleLogoutServiceLocation("https://idp.example/slo"))
            .build();

    private final CookieSaml2LogoutRequestRepository repository =
            new CookieSaml2LogoutRequestRepository(new InMemoryRelyingPartyRegistrationRepository(registration));

    @Test
    void theSavedRequestIsInASameSiteNoneCookieAndComesBackWithItsIdAndRelayState() {
        String cookie = saved();

        assertThat(cookie).contains("SameSite=None").contains("Secure").contains("HttpOnly");

        Saml2LogoutRequest loaded = repository.loadLogoutRequest(responseCarrying(cookie, "state-1"));

        assertThat(loaded).isNotNull();
        assertThat(loaded.getId()).isEqualTo("logout-1");
        assertThat(loaded.getRelayState()).isEqualTo("state-1");
        assertThat(loaded.getLocation()).isEqualTo("https://idp.example/slo");
        assertThat(loaded.getBinding()).isEqualTo(Saml2MessageBinding.REDIRECT);
        assertThat(loaded.getRelyingPartyRegistrationId()).isEqualTo("keycloak");
    }

    @Test
    void aResponseWithADifferentRelayStateMatchesNothing() {
        assertThat(repository.loadLogoutRequest(responseCarrying(saved(), "someone-else")))
                .isNull();
    }

    @Test
    void aMissingOrTamperedCookieMatchesNothing() {
        MockHttpServletRequest noCookie = new MockHttpServletRequest("POST", "/logout/saml2/slo");
        noCookie.setParameter("RelayState", "state-1");

        assertThat(repository.loadLogoutRequest(noCookie)).isNull();
        assertThat(repository.loadLogoutRequest(responseCarrying("not-base64-json", "state-1")))
                .isNull();
    }

    @Test
    void removingTheRequestClearsTheCookie() {
        MockHttpServletResponse response = new MockHttpServletResponse();

        Saml2LogoutRequest removed = repository.removeLogoutRequest(responseCarrying(saved(), "state-1"), response);

        assertThat(removed).isNotNull();
        assertThat(response.getHeader("Set-Cookie")).startsWith(COOKIE + "=;").contains("Max-Age=0");
    }

    /** Saves a request and returns the cookie value the browser would send back. */
    private String saved() {
        Saml2LogoutRequest request = Saml2LogoutRequest.withRelyingPartyRegistration(registration)
                .samlRequest("request")
                .relayState("state-1")
                .id("logout-1")
                .location("https://idp.example/slo")
                .binding(Saml2MessageBinding.REDIRECT)
                .build();
        MockHttpServletResponse response = new MockHttpServletResponse();

        repository.saveLogoutRequest(request, new MockHttpServletRequest(), response);

        String header = response.getHeader("Set-Cookie");
        assertThat(header).startsWith(COOKIE + "=");
        return header;
    }

    private static MockHttpServletRequest responseCarrying(String setCookieHeaderOrValue, String relayState) {
        String value = setCookieHeaderOrValue.startsWith(COOKIE + "=")
                ? setCookieHeaderOrValue.substring(COOKIE.length() + 1, setCookieHeaderOrValue.indexOf(';'))
                : setCookieHeaderOrValue;
        MockHttpServletRequest request = new MockHttpServletRequest("POST", "/logout/saml2/slo");
        request.setCookies(new Cookie(COOKIE, value));
        request.setParameter("RelayState", relayState);
        return request;
    }
}
