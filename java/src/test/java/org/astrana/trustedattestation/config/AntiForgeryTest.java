package org.astrana.trustedattestation.config;

import static org.assertj.core.api.Assertions.assertThat;
import static org.springframework.security.test.web.servlet.request.SecurityMockMvcRequestPostProcessors.csrf;
import static org.springframework.security.test.web.servlet.request.SecurityMockMvcRequestPostProcessors.oidcLogin;
import static org.springframework.security.test.web.servlet.setup.SecurityMockMvcConfigurers.springSecurity;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.delete;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.put;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.content;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.header;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.redirectedUrl;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

import jakarta.servlet.http.HttpServletRequest;
import org.astrana.trustedattestation.security.MemberIdentityResolver;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.context.annotation.Import;
import org.springframework.http.ResponseEntity;
import org.springframework.mock.web.MockHttpSession;
import org.springframework.security.oauth2.client.registration.ClientRegistration;
import org.springframework.security.oauth2.client.registration.ClientRegistrationRepository;
import org.springframework.security.oauth2.client.registration.InMemoryClientRegistrationRepository;
import org.springframework.security.oauth2.core.AuthorizationGrantType;
import org.springframework.security.web.csrf.CsrfToken;
import org.springframework.test.context.junit.jupiter.web.SpringJUnitWebConfig;
import org.springframework.test.web.servlet.MockMvc;
import org.springframework.test.web.servlet.MvcResult;
import org.springframework.test.web.servlet.ResultActions;
import org.springframework.test.web.servlet.setup.MockMvcBuilders;
import org.springframework.web.bind.annotation.DeleteMapping;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.PutMapping;
import org.springframework.web.bind.annotation.RestController;
import org.springframework.web.context.WebApplicationContext;
import org.springframework.web.servlet.config.annotation.EnableWebMvc;

/**
 * Which requests the real security filter chain asks an anti-forgery token of, and how it refuses one
 * without it.
 *
 * <p>Every request a signed-in member makes that changes something needs the token: setting or clearing a
 * key, removing a relationship, revoking one, and signing out. The API reads it from the {@code X-CSRF-TOKEN}
 * header only. A refusal is HTTP 403 with no body and no Content-Type, as on the other two implementations.
 * The endpoints behind the chain are stand-ins that answer 204, because what is under test is the chain in
 * front of them.
 */
@SpringJUnitWebConfig(AntiForgeryTest.Context.class)
class AntiForgeryTest {

    private static final String REVOKE = "/api/v1/me/relationships/employee/revoke";
    private static final String KEY = "/api/v1/me/relationships/employee/key";
    private static final String UNVERSIONED_KEY = "/api/me/relationships/employee/key";
    private static final String ATTEST = "/api/v1/attest";

    private MockMvc mvc;

    @BeforeEach
    void setUp(WebApplicationContext context) {
        mvc = MockMvcBuilders.webAppContextSetup(context)
                .apply(springSecurity())
                .build();
    }

    // -- Revoke -----------------------------------------------------------------------------------------

    @Test
    void aSignedInRevokeWithoutTheTokenIs403WithNoBodyAndNoContentType() throws Exception {
        refused(mvc.perform(post(REVOKE).with(oidcLogin())));
    }

    @Test
    void aSignedInRevokeWithAWrongTokenIs403WithNoBodyAndNoContentType() throws Exception {
        refused(mvc.perform(post(REVOKE).with(oidcLogin()).header("X-CSRF-TOKEN", "not-the-token")));
    }

    @Test
    void aSignedInRevokeCarryingThePagesTokenInTheXCsrfTokenHeaderIsServed() throws Exception {
        // The page reads the token its session was issued and the script sends it under this header name,
        // the one all three implementations read.
        MockHttpSession session = new MockHttpSession();
        MvcResult page = mvc.perform(get("/me").session(session).with(oidcLogin()))
                .andExpect(status().isOk())
                .andReturn();
        CsrfToken issued = (CsrfToken) page.getRequest().getAttribute(CsrfToken.class.getName());

        assertThat(issued.getHeaderName()).isEqualTo("X-CSRF-TOKEN");
        mvc.perform(post(REVOKE)
                        .session(session)
                        .with(oidcLogin())
                        .header("X-CSRF-TOKEN", page.getResponse().getContentAsString()))
                .andExpect(status().isNoContent());
    }

    @Test
    void anUnversionedRevokeNeedsTheTokenToo() throws Exception {
        refused(mvc.perform(post("/api/me/relationships/employee/revoke").with(oidcLogin())));
    }

    @Test
    void aRevokeWithNoSessionIs401LikeAnyOtherApiCallWithoutOne() throws Exception {
        // Not 403: a caller with no session could never have held a token, and the other two implementations
        // answer the missing session first.
        mvc.perform(post(REVOKE)).andExpect(status().isUnauthorized());
    }

    // -- The key operations -------------------------------------------------------------------------------

    @Test
    void aSignedInKeyWriteOrRemovalWithoutTheTokenIs403WithNoBodyAndNoContentType() throws Exception {
        refused(mvc.perform(put(KEY).with(oidcLogin())));
        refused(mvc.perform(delete(KEY).with(oidcLogin())));
    }

    @Test
    void aSignedInKeyWriteOrRemovalWithAWrongTokenIs403WithNoBodyAndNoContentType() throws Exception {
        refused(mvc.perform(put(KEY).with(oidcLogin()).header("X-CSRF-TOKEN", "not-the-token")));
        refused(mvc.perform(delete(KEY).with(oidcLogin()).header("X-CSRF-TOKEN", "not-the-token")));
    }

    @Test
    void aSignedInKeyWriteOrRemovalCarryingTheTokenInTheXCsrfTokenHeaderIsServed() throws Exception {
        mvc.perform(put(KEY).with(oidcLogin()).with(csrf().asHeader())).andExpect(status().isNoContent());
        mvc.perform(delete(KEY).with(oidcLogin()).with(csrf().asHeader())).andExpect(status().isNoContent());
    }

    @Test
    void theApiReadsTheTokenFromTheHeaderOnlyNeverFromARequestParameter() throws Exception {
        // The contract names the header, and the other two implementations look nowhere else on the API.
        refused(mvc.perform(put(KEY).with(oidcLogin()).with(csrf())));
        refused(mvc.perform(delete(KEY).with(oidcLogin()).with(csrf())));
        refused(mvc.perform(post(REVOKE).with(oidcLogin()).with(csrf())));
    }

    @Test
    void aMethodTheKeyDoesNotTakeIsStill405WithASessionAndNoToken() throws Exception {
        // Only the methods the contract defines need the token, so an undefined one gets the dispatcher's
        // answer, as it does on the other two implementations, rather than a refusal of a token it never needed.
        mvc.perform(post(KEY).with(oidcLogin())).andExpect(status().isMethodNotAllowed());
    }

    @Test
    void anUnversionedKeyWriteOrRemovalNeedsTheTokenToo() throws Exception {
        refused(mvc.perform(put(UNVERSIONED_KEY).with(oidcLogin())));
        refused(mvc.perform(delete(UNVERSIONED_KEY).with(oidcLogin())));
    }

    @Test
    void aKeyWriteOrRemovalWithNoSessionIs401WhateverTheToken() throws Exception {
        mvc.perform(put(KEY)).andExpect(status().isUnauthorized());
        mvc.perform(delete(KEY)).andExpect(status().isUnauthorized());
        mvc.perform(put(KEY).header("X-CSRF-TOKEN", "not-the-token")).andExpect(status().isUnauthorized());
        mvc.perform(delete(KEY).header("X-CSRF-TOKEN", "not-the-token")).andExpect(status().isUnauthorized());
    }

    // -- The attestation lookup -------------------------------------------------------------------------

    @Test
    void theAnonymousAttestationLookupNeedsNoTokenSignedInOrNot() throws Exception {
        // A verifying Astrana instance holds no session and so no token, and a member's session changes
        // nothing about the lookup.
        mvc.perform(post(ATTEST)).andExpect(status().isNoContent());
        mvc.perform(post(ATTEST).with(oidcLogin())).andExpect(status().isNoContent());
    }

    // -- Sign-out -----------------------------------------------------------------------------------------

    @Test
    void aSignOutWithALiveSessionAndNoTokenIs403WithNoBodyAndNoContentType() throws Exception {
        refused(mvc.perform(post(SecurityConfig.SIGN_OUT_URL).with(oidcLogin())));
    }

    @Test
    void aSignOutWithALiveSessionAndAWrongTokenIs403WithNoBodyAndNoContentType() throws Exception {
        refused(mvc.perform(post(SecurityConfig.SIGN_OUT_URL).with(oidcLogin()).param("_csrf", "not-the-token")));
        refused(mvc.perform(post(SecurityConfig.SIGN_OUT_URL).with(oidcLogin()).with(csrf().useInvalidToken())));
    }

    @Test
    void aSignOutWithNoSessionLandsOnSignedOutWhateverTheToken() throws Exception {
        mvc.perform(post(SecurityConfig.SIGN_OUT_URL))
                .andExpect(status().is3xxRedirection())
                .andExpect(redirectedUrl(SecurityConfig.SIGNED_OUT_URL));
    }

    @Test
    void aSignOutWithALiveSessionAndTheTokenLandsOnSignedOut() throws Exception {
        mvc.perform(post(SecurityConfig.SIGN_OUT_URL).with(oidcLogin()).with(csrf()))
                .andExpect(status().is3xxRedirection())
                .andExpect(redirectedUrl(SecurityConfig.SIGNED_OUT_URL));
    }

    // -- Helpers ----------------------------------------------------------------------------------------

    private static void refused(ResultActions result) throws Exception {
        result.andExpect(status().isForbidden())
                .andExpect(content().string(""))
                .andExpect(header().doesNotExist("Content-Type"));
    }

    @Configuration
    @EnableWebMvc
    @Import(SecurityConfig.class)
    static class Context {

        @Bean
        TrustedAttestationProperties properties() {
            return new TrustedAttestationProperties();
        }

        @Bean
        MemberIdentityResolver identities(TrustedAttestationProperties properties) {
            return new MemberIdentityResolver(properties);
        }

        @Bean
        ClientRegistrationRepository registrations() {
            return new InMemoryClientRegistrationRepository(ClientRegistration.withRegistrationId("keycloak")
                    .clientId("trusted-attestation")
                    .authorizationGrantType(AuthorizationGrantType.AUTHORIZATION_CODE)
                    .redirectUri("{baseUrl}/login/oauth2/code/{registrationId}")
                    .authorizationUri("https://idp.example/auth")
                    .tokenUri("https://idp.example/token")
                    .build());
        }

        @Bean
        StandIns standIns() {
            return new StandIns();
        }
    }

    /** Stand-ins for the endpoints behind the chain. The page answers with the token its session holds. */
    @RestController
    static class StandIns {

        @GetMapping("/me")
        String page(HttpServletRequest request) {
            return ((CsrfToken) request.getAttribute(CsrfToken.class.getName())).getToken();
        }

        @PostMapping({REVOKE, "/api/me/relationships/employee/revoke"})
        ResponseEntity<Void> revoke() {
            return ResponseEntity.noContent().build();
        }

        @PutMapping({KEY, UNVERSIONED_KEY})
        ResponseEntity<Void> setKey() {
            return ResponseEntity.noContent().build();
        }

        @DeleteMapping({KEY, UNVERSIONED_KEY})
        ResponseEntity<Void> removeKey() {
            return ResponseEntity.noContent().build();
        }

        @PostMapping(ATTEST)
        ResponseEntity<Void> attest() {
            return ResponseEntity.noContent().build();
        }
    }
}
