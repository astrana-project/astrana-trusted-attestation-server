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
 * <p>A browser preflights a cross-origin PUT or DELETE with a JSON body, but not a plain POST, so a member's
 * revoke and sign-out need the token and the key operations do not. A refusal is HTTP 403 with no body and
 * no Content-Type, as on the other two implementations. The endpoints behind the chain are stand-ins that
 * answer 204, because what is under test is the chain in front of them.
 */
@SpringJUnitWebConfig(AntiForgeryTest.Context.class)
class AntiForgeryTest {

    private static final String REVOKE = "/api/v1/me/relationships/employee/revoke";
    private static final String KEY = "/api/v1/me/relationships/employee/key";

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
    void settingAndRemovingAKeyNeedNoToken() throws Exception {
        mvc.perform(put(KEY).with(oidcLogin())).andExpect(status().isNoContent());
        mvc.perform(delete(KEY).with(oidcLogin())).andExpect(status().isNoContent());
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

        @PutMapping(KEY)
        ResponseEntity<Void> setKey() {
            return ResponseEntity.noContent().build();
        }

        @DeleteMapping(KEY)
        ResponseEntity<Void> removeKey() {
            return ResponseEntity.noContent().build();
        }
    }
}
