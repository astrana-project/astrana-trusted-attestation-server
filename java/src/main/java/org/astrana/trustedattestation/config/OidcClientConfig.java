package org.astrana.trustedattestation.config;

import java.time.Duration;
import java.util.ArrayList;
import org.astrana.trustedattestation.security.DiscardingAuthorizedClientRepository;
import org.springframework.boot.context.properties.EnableConfigurationProperties;
import org.springframework.boot.security.oauth2.client.autoconfigure.ConditionalOnOAuth2ClientRegistrationProperties;
import org.springframework.boot.security.oauth2.client.autoconfigure.OAuth2ClientProperties;
import org.springframework.boot.security.oauth2.client.autoconfigure.OAuth2ClientPropertiesMapper;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.http.client.JdkClientHttpRequestFactory;
import org.springframework.security.oauth2.client.registration.InMemoryClientRegistrationRepository;
import org.springframework.security.oauth2.client.web.OAuth2AuthorizedClientRepository;
import org.springframework.util.StringUtils;
import org.springframework.web.client.RestClient;

/**
 * The three places this implementation departs from Spring Boot's OpenID Connect client defaults, all to
 * behave as the other two implementations do.
 */
@Configuration
// Boot binds spring.security.oauth2.client.* only inside the registration configuration this bean
// replaces, so the binding has to be switched on here or the properties bean does not exist at start.
@EnableConfigurationProperties(OAuth2ClientProperties.class)
public class OidcClientConfig {

    /** How long the one extra read of the discovery document may take before the configured value stands. */
    private static final Duration DISCOVERY_TIMEOUT = Duration.ofSeconds(10);

    /**
     * Boot's own registration repository, built from the same {@code spring.security.oauth2.client.*}
     * properties by Boot's own mapper, after each provider's {@code issuer-uri} has been respelt the way
     * the provider states it -- see {@link IssuerUri} -- and each registration's {@code scope} list has been
     * given the three default scopes -- see {@link OidcScopes}. Present only when a registration is configured, which
     * is the same condition Boot applies, so a SAML deployment still gets no OIDC repository at all.
     */
    @Bean
    @ConditionalOnOAuth2ClientRegistrationProperties
    public InMemoryClientRegistrationRepository clientRegistrationRepository(OAuth2ClientProperties properties) {
        JdkClientHttpRequestFactory requests = new JdkClientHttpRequestFactory();
        requests.setReadTimeout(DISCOVERY_TIMEOUT);
        RestClient http = RestClient.builder().requestFactory(requests).build();

        properties.getProvider().values().forEach(provider -> {
            String issuer = provider.getIssuerUri();
            if (StringUtils.hasText(issuer)) {
                provider.setIssuerUri(IssuerUri.asStatedByProvider(
                        issuer, url -> http.get().uri(url).retrieve().body(String.class)));
            }
        });
        properties
                .getRegistration()
                .values()
                .forEach(registration -> registration.setScope(OidcScopes.withDefaults(registration.getScope())));

        return new InMemoryClientRegistrationRepository(new ArrayList<>(new OAuth2ClientPropertiesMapper(properties)
                .asClientRegistrations()
                .values()));
    }

    /**
     * No access or refresh token is kept once a member has signed in. Boot would otherwise register an
     * in-memory store that keeps them for the life of the process -- see {@link
     * DiscardingAuthorizedClientRepository}.
     */
    @Bean
    public OAuth2AuthorizedClientRepository authorizedClientRepository() {
        return new DiscardingAuthorizedClientRepository();
    }
}
