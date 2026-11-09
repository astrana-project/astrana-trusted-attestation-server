package org.astrana.trustedattestation.config;

import static org.assertj.core.api.Assertions.assertThat;
import static org.springframework.security.test.web.servlet.setup.SecurityMockMvcConfigurers.springSecurity;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

import java.util.List;
import org.astrana.trustedattestation.security.MemberIdentityResolver;
import org.junit.jupiter.api.Test;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.context.annotation.Import;
import org.springframework.security.oauth2.client.registration.ClientRegistration;
import org.springframework.security.oauth2.client.registration.ClientRegistrationRepository;
import org.springframework.security.oauth2.client.registration.InMemoryClientRegistrationRepository;
import org.springframework.security.oauth2.core.AuthorizationGrantType;
import org.springframework.test.context.junit.jupiter.web.SpringJUnitWebConfig;
import org.springframework.test.web.servlet.setup.MockMvcBuilders;
import org.springframework.web.context.WebApplicationContext;
import org.springframework.web.servlet.config.annotation.EnableWebMvc;
import org.springframework.web.util.UriComponentsBuilder;

/**
 * Proof Key for Code Exchange (PKCE) on the OpenID Connect sign-in, through the real security filter chain.
 *
 * <p>Decision record 22 requires a proof key on every sign-in. Spring Security adds one on its own unless the
 * registration turns {@code requireProofKey} off, so the registration here turns it off, and the proof key
 * can come only from the server's own configuration.
 */
@SpringJUnitWebConfig(ProofKeyTest.Context.class)
class ProofKeyTest {

    @Test
    void theRedirectToTheIdentitySystemCarriesAProofKeyWhateverTheRegistrationSays(WebApplicationContext context)
            throws Exception {
        String location = MockMvcBuilders.webAppContextSetup(context)
                .apply(springSecurity())
                .build()
                .perform(get("/oauth2/authorization/idp"))
                .andExpect(status().is3xxRedirection())
                .andReturn()
                .getResponse()
                .getRedirectedUrl();

        assertThat(UriComponentsBuilder.fromUriString(location).build().getQueryParams())
                .containsKey("code_challenge")
                .containsEntry("code_challenge_method", List.of("S256"));
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
            return new InMemoryClientRegistrationRepository(ClientRegistration.withRegistrationId("idp")
                    .clientId("trusted-attestation")
                    .clientSecret("a-client-secret")
                    .authorizationGrantType(AuthorizationGrantType.AUTHORIZATION_CODE)
                    .redirectUri("{baseUrl}/login/oauth2/code/{registrationId}")
                    .authorizationUri("https://idp.example/auth")
                    .tokenUri("https://idp.example/token")
                    .clientSettings(ClientRegistration.ClientSettings.builder()
                            .requireProofKey(false)
                            .build())
                    .build());
        }
    }
}
