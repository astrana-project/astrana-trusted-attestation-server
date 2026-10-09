package org.astrana.trustedattestation.config;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;
import static org.mockito.Mockito.mock;

import jakarta.servlet.ServletException;
import java.io.IOException;
import java.security.PrivateKey;
import java.security.cert.X509Certificate;
import java.time.Instant;
import java.util.List;
import java.util.Map;
import org.astrana.trustedattestation.config.TrustedAttestationProperties.Protocol;
import org.astrana.trustedattestation.security.MemberIdentityResolver;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.Test;
import org.springframework.mock.web.MockFilterChain;
import org.springframework.mock.web.MockHttpServletRequest;
import org.springframework.mock.web.MockHttpServletResponse;
import org.springframework.security.authentication.AnonymousAuthenticationToken;
import org.springframework.security.authentication.BadCredentialsException;
import org.springframework.security.core.authority.AuthorityUtils;
import org.springframework.security.core.authority.SimpleGrantedAuthority;
import org.springframework.security.core.context.SecurityContextHolder;
import org.springframework.security.oauth2.client.oidc.userinfo.OidcUserRequest;
import org.springframework.security.oauth2.client.registration.ClientRegistration;
import org.springframework.security.oauth2.client.registration.InMemoryClientRegistrationRepository;
import org.springframework.security.oauth2.client.userinfo.OAuth2UserService;
import org.springframework.security.oauth2.core.AuthorizationGrantType;
import org.springframework.security.oauth2.core.OAuth2AuthenticationException;
import org.springframework.security.oauth2.core.oidc.OidcIdToken;
import org.springframework.security.oauth2.core.oidc.user.DefaultOidcUser;
import org.springframework.security.oauth2.core.oidc.user.OidcUser;
import org.springframework.security.saml2.core.Saml2X509Credential;
import org.springframework.security.saml2.provider.service.authentication.Saml2AssertionAuthentication;
import org.springframework.security.saml2.provider.service.authentication.Saml2ResponseAssertion;
import org.springframework.security.saml2.provider.service.registration.InMemoryRelyingPartyRegistrationRepository;
import org.springframework.security.saml2.provider.service.registration.RelyingPartyRegistration;
import org.springframework.security.web.authentication.logout.LogoutSuccessHandler;
import org.springframework.security.web.header.HeaderWriter;
import org.springframework.security.web.util.matcher.RequestMatcher;

/**
 * The parts of the security configuration a request can observe without a running server: the header set
 * every response carries, including the 404 answered ahead of the security chain, which paths count as
 * real, where every sign-out lands, which sign-out needs no anti-forgery token, and when a SAML sign-out
 * goes through the identity provider's single logout.
 */
class SecurityConfigTest {

    @AfterEach
    void clearSecurityContext() {
        SecurityContextHolder.clearContext();
    }

    // -- Response headers -----------------------------------------------------------------------------

    @Test
    void everyResponseCarriesTheRecord35SetAndNoPragmaOrExpires() {
        MockHttpServletResponse response = written(SecurityConfig.responseHeaderWriters(true), secureRequest());

        assertThat(response.getHeader("X-Content-Type-Options")).isEqualTo("nosniff");
        assertThat(response.getHeader("X-Frame-Options")).isEqualTo("DENY");
        assertThat(response.getHeader("Referrer-Policy")).isEqualTo("no-referrer");
        assertThat(response.getHeader("X-XSS-Protection")).isEqualTo("0");
        assertThat(response.getHeader("Cache-Control")).isEqualTo("no-cache, no-store, max-age=0, must-revalidate");
        assertThat(response.containsHeader("Pragma")).isFalse();
        assertThat(response.containsHeader("Expires")).isFalse();
    }

    @Test
    void hstsIsSentOnSecureRequestsWhenEnabledAndNeverWhenDisabled() {
        assertThat(written(SecurityConfig.responseHeaderWriters(true), secureRequest())
                        .getHeader("Strict-Transport-Security"))
                .isEqualTo("max-age=31536000");
        assertThat(written(SecurityConfig.responseHeaderWriters(true), new MockHttpServletRequest())
                        .containsHeader("Strict-Transport-Security"))
                .isFalse();
        assertThat(written(SecurityConfig.responseHeaderWriters(false), secureRequest())
                        .containsHeader("Strict-Transport-Security"))
                .isFalse();
    }

    @Test
    void aCacheControlTheResponseAlreadySetIsKept() {
        MockHttpServletResponse response = new MockHttpServletResponse();
        response.setHeader("Cache-Control", "public, max-age=60");

        SecurityConfig.responseHeaderWriters(false).forEach(writer -> writer.writeHeaders(secureRequest(), response));

        assertThat(response.getHeader("Cache-Control")).isEqualTo("public, max-age=60");
    }

    // -- Unknown paths --------------------------------------------------------------------------------

    @Test
    void anUnknownPathIs404WithTheSameHeadersAndGoesNoFurther() throws ServletException, IOException {
        MockHttpServletRequest request = new MockHttpServletRequest("GET", "/no-such-page");
        MockHttpServletResponse response = new MockHttpServletResponse();
        MockFilterChain chain = new MockFilterChain();

        SecurityConfig.unknownPathFilter(SecurityConfig.responseHeaderWriters(false))
                .doFilter(request, response, chain);

        assertThat(response.getStatus()).isEqualTo(404);
        assertThat(chain.getRequest()).isNull();
        assertThat(response.getHeader("X-Content-Type-Options")).isEqualTo("nosniff");
        assertThat(response.getHeader("X-Frame-Options")).isEqualTo("DENY");
        assertThat(response.getHeader("Referrer-Policy")).isEqualTo("no-referrer");
        assertThat(response.getHeader("X-XSS-Protection")).isEqualTo("0");
        assertThat(response.getHeader("Cache-Control")).isEqualTo(SecurityConfig.NO_STORE);
    }

    @Test
    void aKnownPathPassesThroughUntouched() throws ServletException, IOException {
        MockHttpServletRequest request = new MockHttpServletRequest("GET", "/signed-out");
        MockHttpServletResponse response = new MockHttpServletResponse();
        MockFilterChain chain = new MockFilterChain();

        SecurityConfig.unknownPathFilter(SecurityConfig.responseHeaderWriters(false))
                .doFilter(request, response, chain);

        assertThat(chain.getRequest()).isSameAs(request);
        assertThat(response.getHeaderNames()).isEmpty();
    }

    @Test
    void everySignInAndSignOutPathIsKnown() {
        assertThat(List.of(
                        "/login/oauth2/code/keycloak",
                        "/login/saml2/sso/keycloak",
                        "/oauth2/authorization/keycloak",
                        "/saml2/authenticate/keycloak",
                        "/saml2/service-provider-metadata/keycloak",
                        "/signout",
                        "/logout/saml2/slo",
                        "/signed-out"))
                .allMatch(SecurityConfig::isKnownPath);
        assertThat(SecurityConfig.isKnownPath("/logout")).isFalse();
    }

    @Test
    void theStaticFilesThePagesLinkToAreKnown() {
        assertThat(List.of(
                        "/trusted-attestation.css", "/theme-overrides.css", "/favicon.svg", "/THIRD-PARTY-NOTICES.txt"))
                .allMatch(SecurityConfig::isKnownPath);
    }

    @Test
    void onlyTheRealCallbackShapesAreKnownUnderTheSecurityPrefixes() {
        // Anything else under the prefixes Spring Security owns is not a route, and is 404 like any other
        // path that matches nothing, rather than a redirect to sign-in.
        assertThat(List.of(
                        "/oauth2/anything",
                        "/oauth2/authorization",
                        "/oauth2/authorization/keycloak/extra",
                        "/login/anything",
                        "/login/oauth2/code",
                        "/login/saml2/anything",
                        "/saml2/anything",
                        "/saml2/metadata",
                        "/logout/anything",
                        "/logout/saml2/slo/extra"))
                .noneMatch(SecurityConfig::isKnownPath);
    }

    @Test
    void theProviderSelectionPageIs404LikeTheOtherTwoImplementations() {
        // One protocol, one registration: the entry point never sends anyone to /login, so it does not exist.
        assertThat(SecurityConfig.isKnownPath("/login")).isFalse();
    }

    @Test
    void aDirectRequestToTheErrorDispatchTargetIs404() {
        // Boot reaches /error by an error dispatch, which the filter does not run on. A request for it is
        // a request for a page that does not exist.
        assertThat(SecurityConfig.isKnownPath("/error")).isFalse();
    }

    // -- Where a failed sign-in lands -----------------------------------------------------------------

    @Test
    void aFailedSignInLandsOnTheSelfServicePageWithAMarkerAndNoSession() throws Exception {
        MockHttpServletRequest request = new MockHttpServletRequest("GET", "/login/oauth2/code/keycloak");
        MockHttpServletResponse response = new MockHttpServletResponse();

        SecurityConfig.signInFailureHandler()
                .onAuthenticationFailure(request, response, new BadCredentialsException("provider said no"));

        assertThat(response.getRedirectedUrl()).isEqualTo("/me?error=login");
        assertThat(request.getSession(false)).isNull();
    }

    @Test
    void aTokenWithNoUsableSubjectIsRefusedAtTheCallback() {
        // Spring requires only sub. An organisation keyed on another claim would otherwise sign a member in
        // as nobody. Missing, empty and whitespace all count as absent, see MemberIdentityResolver.
        TrustedAttestationProperties properties = new TrustedAttestationProperties();
        properties.getIam().setSubjectClaim("employee_id");
        MemberIdentityResolver identities = new MemberIdentityResolver(properties);
        OidcUserRequest userRequest = mock(OidcUserRequest.class);

        for (Map<String, Object> claims : List.<Map<String, Object>>of(
                Map.of("sub", "alice"),
                Map.of("sub", "alice", "employee_id", ""),
                Map.of("sub", "alice", "employee_id", "   "),
                Map.of("sub", "alice", "employee_id", List.of("a", "b")))) {
            OidcUser user = oidcUser(claims);
            OAuth2UserService<OidcUserRequest, OidcUser> service =
                    SecurityConfig.requiringASubject(request -> user, identities);

            assertThatThrownBy(() -> service.loadUser(userRequest))
                    .as("claims %s", claims)
                    .isInstanceOf(OAuth2AuthenticationException.class);
        }

        OidcUser keyed = oidcUser(Map.of("sub", "alice", "employee_id", "E-1"));
        assertThat(SecurityConfig.requiringASubject(request -> keyed, identities)
                        .loadUser(userRequest))
                .isSameAs(keyed);
    }

    // -- Where sign-out lands -------------------------------------------------------------------------

    @Test
    void anOidcSignOutWithNoEndSessionEndpointOrExpiredSessionLandsOnSignedOut() throws Exception {
        LogoutSuccessHandler handler = SecurityConfig.logoutSuccessHandler(
                Protocol.OIDC, new InMemoryClientRegistrationRepository(oidcRegistration()));

        assertThat(redirectAfterSignOut(handler)).isEqualTo("/signed-out");
    }

    @Test
    void aLocalSamlSignOutLandsOnSignedOut() throws Exception {
        LogoutSuccessHandler handler = SecurityConfig.logoutSuccessHandler(Protocol.SAML, null);

        assertThat(redirectAfterSignOut(handler)).isEqualTo("/signed-out");
    }

    // -- A sign-out with no live session --------------------------------------------------------------

    @Test
    void aSignOutWithNoSessionIsLetPastTheCsrfFilter() {
        // Nothing to end, so no token is asked for, and the success handlers above land it on /signed-out.
        assertThat(SecurityConfig.SIGN_OUT_WITHOUT_SESSION.matches(new MockHttpServletRequest("POST", "/signout")))
                .isTrue();
    }

    @Test
    void aSignOutAfterTheSessionHasGoneIsLetPastTheCsrfFilter() {
        // An anonymous authentication is what a request carries once the session has expired.
        SecurityContextHolder.getContext()
                .setAuthentication(new AnonymousAuthenticationToken(
                        "key", "anonymousUser", AuthorityUtils.createAuthorityList("ROLE_ANONYMOUS")));

        assertThat(SecurityConfig.SIGN_OUT_WITHOUT_SESSION.matches(new MockHttpServletRequest("POST", "/signout")))
                .isTrue();
    }

    @Test
    void aSignOutWithALiveSessionStillNeedsTheToken() {
        signInOverSaml("keycloak");

        assertThat(SecurityConfig.SIGN_OUT_WITHOUT_SESSION.matches(new MockHttpServletRequest("POST", "/signout")))
                .isFalse();
    }

    @Test
    void onlyAPostToTheSignOutAddressIsExempt() {
        assertThat(SecurityConfig.SIGN_OUT_WITHOUT_SESSION.matches(new MockHttpServletRequest("GET", "/signout")))
                .isFalse();
        assertThat(SecurityConfig.SIGN_OUT_WITHOUT_SESSION.matches(new MockHttpServletRequest("POST", "/me")))
                .isFalse();
    }

    // -- When single logout is used -------------------------------------------------------------------

    @Test
    void singleLogoutIsUsedWhenBothSidesHaveAnEndpoint() {
        signInOverSaml("keycloak");

        assertThat(singleLogoutAvailable(relyingParty("https://idp.example/slo", "{baseUrl}/logout/saml2/slo")))
                .isTrue();
    }

    @Test
    void singleLogoutIsSkippedWhenTheProviderAdvertisesNone() {
        signInOverSaml("keycloak");

        assertThat(singleLogoutAvailable(relyingParty(null, "{baseUrl}/logout/saml2/slo")))
                .isFalse();
    }

    @Test
    void singleLogoutIsSkippedWhenNoAddressIsConfiguredForTheResponseToReturnTo() {
        signInOverSaml("keycloak");

        assertThat(singleLogoutAvailable(relyingParty("https://idp.example/slo", null)))
                .isFalse();
    }

    @Test
    void singleLogoutIsSkippedWithoutASigningKey() {
        // Spring signs every LogoutRequest, so with no key the request cannot be made and the sign-out stays
        // local, which works whatever the provider supports (decision record 42).
        signInOverSaml("keycloak");

        assertThat(singleLogoutAvailable(
                        relyingParty("https://idp.example/slo", "{baseUrl}/logout/saml2/slo", List.of())))
                .isFalse();
    }

    @Test
    void singleLogoutIsSkippedWithoutASamlSession() {
        assertThat(singleLogoutAvailable(relyingParty("https://idp.example/slo", "{baseUrl}/logout/saml2/slo")))
                .isFalse();
    }

    // -- Helpers ----------------------------------------------------------------------------------------

    private static MockHttpServletRequest secureRequest() {
        MockHttpServletRequest request = new MockHttpServletRequest();
        request.setSecure(true);
        return request;
    }

    private static MockHttpServletResponse written(List<HeaderWriter> writers, MockHttpServletRequest request) {
        MockHttpServletResponse response = new MockHttpServletResponse();
        writers.forEach(writer -> writer.writeHeaders(request, response));
        return response;
    }

    private static String redirectAfterSignOut(LogoutSuccessHandler handler) throws Exception {
        MockHttpServletResponse response = new MockHttpServletResponse();
        handler.onLogoutSuccess(new MockHttpServletRequest("POST", "/signout"), response, null);
        return response.getRedirectedUrl();
    }

    private static OidcUser oidcUser(Map<String, Object> claims) {
        OidcIdToken token =
                new OidcIdToken("id-token", Instant.now(), Instant.now().plusSeconds(300), claims);
        return new DefaultOidcUser(List.of(new SimpleGrantedAuthority("ROLE_USER")), token, "sub");
    }

    private static ClientRegistration oidcRegistration() {
        return ClientRegistration.withRegistrationId("keycloak")
                .clientId("trusted-attestation")
                .authorizationGrantType(AuthorizationGrantType.AUTHORIZATION_CODE)
                .redirectUri("{baseUrl}/login/oauth2/code/{registrationId}")
                .authorizationUri("https://idp.example/auth")
                .tokenUri("https://idp.example/token")
                .build();
    }

    private static void signInOverSaml(String registrationId) {
        SecurityContextHolder.getContext()
                .setAuthentication(new Saml2AssertionAuthentication(
                        Saml2ResponseAssertion.withResponseValue("response")
                                .nameId("alice")
                                .build(),
                        List.of(),
                        registrationId));
    }

    /** A registration with a signing key, the shape a deployment with single logout configured has. */
    private static RelyingPartyRegistration relyingParty(String providerSlo, String ownSlo) {
        return relyingParty(
                providerSlo,
                ownSlo,
                List.of(Saml2X509Credential.signing(mock(PrivateKey.class), mock(X509Certificate.class))));
    }

    private static RelyingPartyRegistration relyingParty(
            String providerSlo, String ownSlo, List<Saml2X509Credential> signing) {
        return RelyingPartyRegistration.withRegistrationId("keycloak")
                .entityId("https://sp.example/saml2/service-provider-metadata/keycloak")
                .signingX509Credentials(credentials -> credentials.addAll(signing))
                .singleLogoutServiceLocation(ownSlo)
                .singleLogoutServiceResponseLocation(ownSlo)
                .assertingPartyMetadata(party -> party.entityId("https://idp.example")
                        .singleSignOnServiceLocation("https://idp.example/sso")
                        .singleLogoutServiceLocation(providerSlo))
                .build();
    }

    private static boolean singleLogoutAvailable(RelyingPartyRegistration registration) {
        RequestMatcher matcher =
                SecurityConfig.singleLogoutAvailable(new InMemoryRelyingPartyRegistrationRepository(registration));
        return matcher.matches(new MockHttpServletRequest("POST", "/signout"));
    }
}
