package org.astrana.trustedattestation.config;

import static org.assertj.core.api.Assertions.assertThat;

import jakarta.servlet.ServletInputStream;
import jakarta.servlet.http.HttpServletRequest;
import java.io.InputStream;
import java.io.PrintWriter;
import java.io.StringWriter;
import java.nio.charset.StandardCharsets;
import java.util.Enumeration;
import java.util.List;
import java.util.Map;
import java.util.concurrent.atomic.AtomicReference;
import org.astrana.trustedattestation.config.TrustedAttestationProperties.Protocol;
import org.astrana.trustedattestation.security.MemberIdentityResolver;
import org.astrana.trustedattestation.service.LocalizationSettings;
import org.astrana.trustedattestation.web.LanguageController;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.EnumSource;
import org.springframework.boot.test.context.runner.WebApplicationContextRunner;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.http.HttpHeaders;
import org.springframework.http.ResponseEntity;
import org.springframework.mock.web.DelegatingServletInputStream;
import org.springframework.mock.web.MockHttpServletRequest;
import org.springframework.mock.web.MockHttpServletResponse;
import org.springframework.security.oauth2.client.registration.ClientRegistration;
import org.springframework.security.oauth2.client.registration.ClientRegistrationRepository;
import org.springframework.security.oauth2.client.registration.InMemoryClientRegistrationRepository;
import org.springframework.security.oauth2.core.AuthorizationGrantType;
import org.springframework.security.saml2.provider.service.registration.InMemoryRelyingPartyRegistrationRepository;
import org.springframework.security.saml2.provider.service.registration.RelyingPartyRegistration;
import org.springframework.security.saml2.provider.service.registration.RelyingPartyRegistrationRepository;
import org.springframework.security.web.FilterChainProxy;
import org.springframework.web.servlet.config.annotation.EnableWebMvc;

/**
 * The language switcher's form reaches its controller unread, behind the real security filter chain of
 * either protocol.
 *
 * <p>The controller reads the raw form body, because the servlet parameter API folds the query string in
 * with the form. Under Tomcat, the first call to any parameter method on a form post parses the body, after
 * which the body can no longer be read. So a filter that so much as asks for one parameter leaves the
 * controller an empty form: no cookie, and a return to the landing page whatever the form named. The
 * request here behaves as Tomcat's does in that respect, which a plain mock request does not.
 */
class LanguageSwitcherBehindTheChainTest {

    @ParameterizedTest
    @EnumSource(Protocol.class)
    void theSwitchersFormIsReadAndTheCookieSetBehindTheSecurityChain(Protocol protocol) {
        new WebApplicationContextRunner()
                .withUserConfiguration(Context.class, SecurityConfig.class)
                .withPropertyValues("trusted-attestation.iam.protocol=" + protocol)
                .withBean(TrustedAttestationProperties.class, () -> properties(protocol))
                .run(context -> {
                    LanguageController controller = context.getBean(LanguageController.class);
                    TomcatLikeFormPost request = new TomcatLikeFormPost("locale=fr&next=%2Fme");
                    AtomicReference<ResponseEntity<Void>> answered = new AtomicReference<>();

                    context.getBean(FilterChainProxy.class)
                            .doFilter(
                                    request,
                                    new MockHttpServletResponse(),
                                    (passed, response) ->
                                            answered.set(controller.setLanguage((HttpServletRequest) passed)));

                    assertThat(request.firstParameterRead)
                            .as("a filter read a parameter, which consumes the form body under Tomcat")
                            .isNull();
                    assertThat(answered.get().getHeaders().getFirst(HttpHeaders.SET_COOKIE))
                            .startsWith("ata_locale=fr;");
                    assertThat(answered.get().getHeaders().getFirst(HttpHeaders.LOCATION))
                            .isEqualTo("/me");
                });
    }

    private static TrustedAttestationProperties properties(Protocol protocol) {
        TrustedAttestationProperties properties = new TrustedAttestationProperties();
        properties.getIam().setProtocol(protocol);
        return properties;
    }

    @Configuration
    @EnableWebMvc
    static class Context {

        @Bean
        MemberIdentityResolver identities(TrustedAttestationProperties properties) {
            return new MemberIdentityResolver(properties);
        }

        @Bean
        ClientRegistrationRepository clientRegistrations() {
            return new InMemoryClientRegistrationRepository(ClientRegistration.withRegistrationId("keycloak")
                    .clientId("trusted-attestation")
                    .authorizationGrantType(AuthorizationGrantType.AUTHORIZATION_CODE)
                    .redirectUri("{baseUrl}/login/oauth2/code/{registrationId}")
                    .authorizationUri("https://idp.example/auth")
                    .tokenUri("https://idp.example/token")
                    .build());
        }

        @Bean
        RelyingPartyRegistrationRepository relyingParties() {
            return new InMemoryRelyingPartyRegistrationRepository(
                    RelyingPartyRegistration.withRegistrationId("keycloak")
                            .entityId("https://sp.example/saml2/service-provider-metadata/keycloak")
                            .singleLogoutServiceLocation("{baseUrl}/logout/saml2/slo")
                            .singleLogoutServiceResponseLocation("{baseUrl}/logout/saml2/slo")
                            .assertingPartyMetadata(party -> party.entityId("https://idp.example")
                                    .singleSignOnServiceLocation("https://idp.example/sso")
                                    .singleLogoutServiceLocation("https://idp.example/slo"))
                            .build());
        }

        @Bean
        LanguageController languageController() {
            return new LanguageController(new LocalizationSettings(
                    List.of("en", "fr"), List.of("en", "fr"), "en", Map.of("en", "English", "fr", "Français")));
        }
    }

    /**
     * A form post whose body, as under Tomcat, can no longer be read once any parameter has been asked
     * for. It remembers where the first parameter was read, so a failure names the filter.
     */
    private static final class TomcatLikeFormPost extends MockHttpServletRequest {

        private String firstParameterRead;

        TomcatLikeFormPost(String body) {
            super("POST", "/set-language");
            setContentType("application/x-www-form-urlencoded");
            setContent(body.getBytes(StandardCharsets.UTF_8));
            setSecure(true);
        }

        private void parse() {
            if (firstParameterRead == null) {
                StringWriter trace = new StringWriter();
                new Throwable("first parameter read").printStackTrace(new PrintWriter(trace));
                firstParameterRead = trace.toString();
            }
        }

        @Override
        public String getParameter(String name) {
            parse();
            return super.getParameter(name);
        }

        @Override
        public Map<String, String[]> getParameterMap() {
            parse();
            return super.getParameterMap();
        }

        @Override
        public Enumeration<String> getParameterNames() {
            parse();
            return super.getParameterNames();
        }

        @Override
        public String[] getParameterValues(String name) {
            parse();
            return super.getParameterValues(name);
        }

        @Override
        public ServletInputStream getInputStream() {
            return firstParameterRead == null
                    ? super.getInputStream()
                    : new DelegatingServletInputStream(InputStream.nullInputStream());
        }
    }
}
