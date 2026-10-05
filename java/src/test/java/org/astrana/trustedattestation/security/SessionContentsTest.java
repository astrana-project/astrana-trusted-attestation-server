package org.astrana.trustedattestation.security;

import static org.assertj.core.api.Assertions.assertThat;

import java.time.Instant;
import java.util.List;
import java.util.Map;
import java.util.Set;
import org.astrana.trustedattestation.config.TrustedAttestationProperties;
import org.junit.jupiter.api.Test;
import org.springframework.mock.web.MockHttpServletRequest;
import org.springframework.mock.web.MockHttpServletResponse;
import org.springframework.security.core.GrantedAuthority;
import org.springframework.security.core.authority.SimpleGrantedAuthority;
import org.springframework.security.oauth2.client.authentication.OAuth2AuthenticationToken;
import org.springframework.security.oauth2.client.oidc.userinfo.OidcUserRequest;
import org.springframework.security.oauth2.client.oidc.web.logout.OidcClientInitiatedLogoutSuccessHandler;
import org.springframework.security.oauth2.client.registration.ClientRegistration;
import org.springframework.security.oauth2.client.registration.InMemoryClientRegistrationRepository;
import org.springframework.security.oauth2.core.AuthorizationGrantType;
import org.springframework.security.oauth2.core.OAuth2AccessToken;
import org.springframework.security.oauth2.core.oidc.OidcIdToken;
import org.springframework.security.oauth2.core.oidc.OidcUserInfo;
import org.springframework.security.oauth2.core.oidc.user.DefaultOidcUser;
import org.springframework.security.oauth2.core.oidc.user.OidcUser;
import org.springframework.security.oauth2.core.oidc.user.OidcUserAuthority;
import org.springframework.security.saml2.provider.service.authentication.DefaultSaml2AuthenticatedPrincipal;
import org.springframework.security.saml2.provider.service.authentication.Saml2AssertionAuthentication;
import org.springframework.security.saml2.provider.service.authentication.Saml2Authentication;
import org.springframework.security.saml2.provider.service.authentication.Saml2ResponseAssertion;
import org.springframework.security.saml2.provider.service.authentication.Saml2ResponseAssertionAccessor;

/**
 * The session keeps the subject, the display name, the locale and what sign-out needs, and nothing else,
 * under both protocols, and what it keeps still identifies the member and still signs them out.
 */
class SessionContentsTest {

    private static final String RAW_ID_TOKEN = "header.payload.signature";
    private static final Set<String> KEPT = SessionContents.keptClaims(iam("sub", "name"));

    // -- Which claims are kept ------------------------------------------------------------------------

    @Test
    void theKeptClaimsAreTheConfiguredSubjectAndNameAndTheLocale() {
        assertThat(SessionContents.keptClaims(iam("employee_id", "display_name")))
                .containsExactlyInAnyOrder("employee_id", "display_name", "locale");
    }

    @Test
    void aSubjectAndNameNamingTheSameClaimAreKeptOnce() {
        assertThat(SessionContents.keptClaims(iam("sub", "sub"))).containsExactlyInAnyOrder("sub", "locale");
    }

    // -- OpenID Connect -------------------------------------------------------------------------------

    @Test
    void anOidcUserKeepsOnlyTheKeptClaimsAndNoUserInfo() {
        OidcUser trimmed = SessionContents.oidcUser(fullOidcUser(), KEPT);

        assertThat(trimmed.getClaims()).containsOnlyKeys("sub", "name", "locale");
        assertThat(trimmed.getIdToken().getClaims()).containsOnlyKeys("sub", "name", "locale");
        assertThat(trimmed.getUserInfo()).isNull();
    }

    @Test
    void thePrincipalNameIsTheSubjectWhateverUserNameAttributeTheRegistrationNames() {
        // The development registration names preferred_username, which the session does not keep.
        OidcUser trimmed = SessionContents.oidcUser(fullOidcUser(), KEPT);

        assertThat(trimmed.getName()).isEqualTo("alice");
        assertThat(trimmed.getClaims()).doesNotContainKey("preferred_username");
    }

    @Test
    void theIdTokenKeepsItsRawValueForSignOut() {
        OidcUser trimmed = SessionContents.oidcUser(fullOidcUser(), KEPT);

        assertThat(trimmed.getIdToken().getTokenValue()).isEqualTo(RAW_ID_TOKEN);
    }

    @Test
    void theAuthoritiesCarryNoCopyOfTheDroppedClaims() {
        OidcUser trimmed = SessionContents.oidcUser(fullOidcUser(), KEPT);

        assertThat(trimmed.getAuthorities())
                .filteredOn(authority -> authority instanceof OidcUserAuthority)
                .singleElement()
                .satisfies(authority -> assertThat(((OidcUserAuthority) authority).getAttributes())
                        .containsOnlyKeys("sub", "name", "locale"));
        assertThat(trimmed.getAuthorities())
                .extracting(GrantedAuthority::getAuthority)
                .contains("SCOPE_openid");
    }

    @Test
    void claimsThatArriveOnlyThroughUserInfoAreKeptAfterTheMerge() throws Exception {
        // Spring's own service fetches UserInfo and merges it into the user. The cut happens after that,
        // so a locale or a name the provider sends only from UserInfo still reaches the pages.
        OidcIdToken idToken = idToken(Map.of("sub", "alice", "iss", "https://idp.example"));
        OidcUserInfo userInfo = new OidcUserInfo(
                Map.of("sub", "alice", "name", "Alice Anderson", "locale", "fr", "email", "alice@example.org"));
        OidcUser full = new DefaultOidcUser(List.of(new OidcUserAuthority(idToken, userInfo)), idToken, userInfo);

        OidcUser trimmed = SessionContents.oidcUserService(request -> full, KEPT)
                .loadUser(new OidcUserRequest(registration(), accessToken(), idToken));

        assertThat(trimmed.getClaims())
                .containsExactlyInAnyOrderEntriesOf(Map.of("sub", "alice", "name", "Alice Anderson", "locale", "fr"));
    }

    @Test
    void whatIsKeptStillIdentifiesTheMember() {
        TrustedAttestationProperties properties = new TrustedAttestationProperties();
        OidcUser trimmed = SessionContents.oidcUser(fullOidcUser(), KEPT);

        assertThat(new MemberIdentityResolver(properties).resolve(signedIn(trimmed)))
                .contains(new MemberIdentity("alice", "Alice Anderson"));
    }

    @Test
    void signOutStillSendsTheIdTokenAsAHint() throws Exception {
        OidcUser trimmed = SessionContents.oidcUser(fullOidcUser(), KEPT);
        ClientRegistration registration = ClientRegistration.withClientRegistration(registration())
                .providerConfigurationMetadata(Map.of("end_session_endpoint", "https://idp.example/logout"))
                .build();
        OidcClientInitiatedLogoutSuccessHandler handler =
                new OidcClientInitiatedLogoutSuccessHandler(new InMemoryClientRegistrationRepository(registration));
        MockHttpServletResponse response = new MockHttpServletResponse();

        handler.onLogoutSuccess(new MockHttpServletRequest("POST", "/signout"), response, signedIn(trimmed));

        assertThat(response.getRedirectedUrl())
                .startsWith("https://idp.example/logout")
                .contains("id_token_hint=" + RAW_ID_TOKEN);
    }

    // -- SAML -----------------------------------------------------------------------------------------

    @Test
    void aSamlSessionKeepsOnlyTheKeptAttributesAndNotTheResponse() {
        Saml2Authentication trimmed = SessionContents.samlAuthentication(fullSamlAuthentication(), KEPT);

        Saml2ResponseAssertionAccessor assertion = (Saml2ResponseAssertionAccessor) trimmed.getCredentials();
        assertThat(assertion.getAttributes()).containsOnlyKeys("name", "locale");
        assertThat(((DefaultSaml2AuthenticatedPrincipal) trimmed.getPrincipal()).getAttributes())
                .containsOnlyKeys("name", "locale");
        assertThat(trimmed.getSaml2Response()).isEmpty();
        assertThat(assertion.getResponseValue()).isEmpty();
    }

    @Test
    void aSamlSessionKeepsWhatSingleLogoutNeeds() {
        Saml2AssertionAuthentication trimmed =
                (Saml2AssertionAuthentication) SessionContents.samlAuthentication(fullSamlAuthentication(), KEPT);

        assertThat(trimmed.getName()).isEqualTo("alice");
        assertThat(trimmed.getCredentials().getNameId()).isEqualTo("alice");
        assertThat(trimmed.getCredentials().getSessionIndexes()).containsExactly("session-1");
        assertThat(trimmed.getRelyingPartyRegistrationId()).isEqualTo("keycloak");
        assertThat(((DefaultSaml2AuthenticatedPrincipal) trimmed.getPrincipal()).getRelyingPartyRegistrationId())
                .isEqualTo("keycloak");
        assertThat(((DefaultSaml2AuthenticatedPrincipal) trimmed.getPrincipal()).getSessionIndexes())
                .containsExactly("session-1");
        assertThat(trimmed.isAuthenticated()).isTrue();
    }

    @Test
    void whatASamlSessionKeepsStillIdentifiesTheMember() {
        Saml2Authentication trimmed = SessionContents.samlAuthentication(fullSamlAuthentication(), KEPT);

        assertThat(new MemberIdentityResolver(new TrustedAttestationProperties()).resolve(trimmed))
                .contains(new MemberIdentity("alice", "Alice Anderson"));
    }

    // -- Helpers ----------------------------------------------------------------------------------------

    private static TrustedAttestationProperties.Iam iam(String subjectClaim, String nameClaim) {
        TrustedAttestationProperties.Iam iam = new TrustedAttestationProperties.Iam();
        iam.setSubjectClaim(subjectClaim);
        iam.setNameClaim(nameClaim);
        return iam;
    }

    private static OidcIdToken idToken(Map<String, Object> claims) {
        return new OidcIdToken(RAW_ID_TOKEN, Instant.now(), Instant.now().plusSeconds(300), claims);
    }

    private static OidcUser fullOidcUser() {
        OidcIdToken idToken = idToken(Map.of(
                "sub", "alice",
                "iss", "https://idp.example",
                "name", "Alice Anderson",
                "preferred_username", "alice.anderson",
                "email", "alice@example.org",
                "groups", List.of("staff"),
                "locale", "fr"));
        OidcUserInfo userInfo = new OidcUserInfo(Map.of("sub", "alice", "phone_number", "+44 20 7946 0000"));
        return new DefaultOidcUser(
                List.of(new OidcUserAuthority(idToken, userInfo), new SimpleGrantedAuthority("SCOPE_openid")),
                idToken,
                userInfo);
    }

    private static OAuth2AuthenticationToken signedIn(OidcUser user) {
        return new OAuth2AuthenticationToken(user, user.getAuthorities(), "keycloak");
    }

    private static ClientRegistration registration() {
        return ClientRegistration.withRegistrationId("keycloak")
                .clientId("trusted-attestation")
                .authorizationGrantType(AuthorizationGrantType.AUTHORIZATION_CODE)
                .redirectUri("{baseUrl}/login/oauth2/code/{registrationId}")
                .authorizationUri("https://idp.example/auth")
                .tokenUri("https://idp.example/token")
                .build();
    }

    private static OAuth2AccessToken accessToken() {
        return new OAuth2AccessToken(
                OAuth2AccessToken.TokenType.BEARER,
                "access",
                Instant.now(),
                Instant.now().plusSeconds(300));
    }

    private static Saml2Authentication fullSamlAuthentication() {
        Saml2ResponseAssertionAccessor assertion = Saml2ResponseAssertion.withResponseValue("<samlp:Response/>")
                .nameId("alice")
                .sessionIndexes(List.of("session-1"))
                .attributes(Map.of(
                        "name", List.of("Alice Anderson"),
                        "email", List.of("alice@example.org"),
                        "groups", List.of("staff", "finance"),
                        "locale", List.of("fr")))
                .build();
        DefaultSaml2AuthenticatedPrincipal principal = new DefaultSaml2AuthenticatedPrincipal("alice", assertion);
        principal.setRelyingPartyRegistrationId("keycloak");
        return new Saml2AssertionAuthentication(
                principal, assertion, List.of(new SimpleGrantedAuthority("ROLE_USER")), "keycloak");
    }
}
