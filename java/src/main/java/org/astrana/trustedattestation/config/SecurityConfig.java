package org.astrana.trustedattestation.config;

import jakarta.servlet.FilterChain;
import jakarta.servlet.ServletException;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import java.io.IOException;
import java.util.ArrayList;
import java.util.List;
import java.util.Set;
import java.util.regex.Pattern;
import org.astrana.trustedattestation.config.TrustedAttestationProperties.Protocol;
import org.astrana.trustedattestation.security.MemberIdentityResolver;
import org.astrana.trustedattestation.security.SessionContents;
import org.opensaml.saml.common.xml.SAMLConstants;
import org.opensaml.saml.saml2.assertion.SAML2AssertionValidationParameters;
import org.opensaml.saml.saml2.metadata.SPSSODescriptor;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.beans.factory.ObjectProvider;
import org.springframework.boot.autoconfigure.condition.ConditionalOnProperty;
import org.springframework.boot.web.servlet.FilterRegistrationBean;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.core.convert.converter.Converter;
import org.springframework.http.HttpHeaders;
import org.springframework.http.HttpStatus;
import org.springframework.security.authentication.AuthenticationTrustResolverImpl;
import org.springframework.security.authentication.ProviderManager;
import org.springframework.security.config.ObjectPostProcessor;
import org.springframework.security.config.annotation.web.builders.HttpSecurity;
import org.springframework.security.config.annotation.web.configuration.EnableWebSecurity;
import org.springframework.security.core.Authentication;
import org.springframework.security.core.context.SecurityContextHolder;
import org.springframework.security.oauth2.client.oidc.userinfo.OidcUserRequest;
import org.springframework.security.oauth2.client.oidc.web.logout.OidcClientInitiatedLogoutSuccessHandler;
import org.springframework.security.oauth2.client.registration.ClientRegistration;
import org.springframework.security.oauth2.client.registration.ClientRegistrationRepository;
import org.springframework.security.oauth2.client.userinfo.OAuth2UserService;
import org.springframework.security.oauth2.client.web.DefaultOAuth2AuthorizationRequestResolver;
import org.springframework.security.oauth2.client.web.OAuth2AuthorizationRequestCustomizers;
import org.springframework.security.oauth2.client.web.OAuth2AuthorizationRequestResolver;
import org.springframework.security.oauth2.core.OAuth2AuthenticationException;
import org.springframework.security.oauth2.core.OAuth2Error;
import org.springframework.security.oauth2.core.oidc.user.OidcUser;
import org.springframework.security.saml2.core.Saml2Error;
import org.springframework.security.saml2.core.Saml2ResponseValidatorResult;
import org.springframework.security.saml2.provider.service.authentication.AbstractSaml2AuthenticationRequest;
import org.springframework.security.saml2.provider.service.authentication.OpenSaml5AuthenticationProvider;
import org.springframework.security.saml2.provider.service.authentication.Saml2AssertionAuthentication;
import org.springframework.security.saml2.provider.service.authentication.Saml2AuthenticatedPrincipal;
import org.springframework.security.saml2.provider.service.authentication.Saml2Authentication;
import org.springframework.security.saml2.provider.service.authentication.Saml2AuthenticationException;
import org.springframework.security.saml2.provider.service.authentication.logout.OpenSaml5LogoutRequestValidator;
import org.springframework.security.saml2.provider.service.metadata.OpenSaml5MetadataResolver;
import org.springframework.security.saml2.provider.service.registration.RelyingPartyRegistration;
import org.springframework.security.saml2.provider.service.registration.RelyingPartyRegistrationRepository;
import org.springframework.security.saml2.provider.service.web.Saml2AuthenticationRequestRepository;
import org.springframework.security.saml2.provider.service.web.metadata.RequestMatcherMetadataResponseResolver;
import org.springframework.security.web.AuthenticationEntryPoint;
import org.springframework.security.web.DefaultRedirectStrategy;
import org.springframework.security.web.RedirectStrategy;
import org.springframework.security.web.SecurityFilterChain;
import org.springframework.security.web.authentication.AuthenticationFailureHandler;
import org.springframework.security.web.authentication.HttpStatusEntryPoint;
import org.springframework.security.web.authentication.LoginUrlAuthenticationEntryPoint;
import org.springframework.security.web.authentication.logout.LogoutFilter;
import org.springframework.security.web.authentication.logout.LogoutSuccessHandler;
import org.springframework.security.web.authentication.logout.SimpleUrlLogoutSuccessHandler;
import org.springframework.security.web.header.HeaderWriter;
import org.springframework.security.web.header.HeaderWriterFilter;
import org.springframework.security.web.header.writers.HstsHeaderWriter;
import org.springframework.security.web.header.writers.ReferrerPolicyHeaderWriter;
import org.springframework.security.web.header.writers.XContentTypeOptionsHeaderWriter;
import org.springframework.security.web.header.writers.XXssProtectionHeaderWriter;
import org.springframework.security.web.header.writers.frameoptions.XFrameOptionsHeaderWriter;
import org.springframework.security.web.savedrequest.NullRequestCache;
import org.springframework.security.web.util.matcher.AndRequestMatcher;
import org.springframework.security.web.util.matcher.AnyRequestMatcher;
import org.springframework.security.web.util.matcher.RequestMatcher;
import org.springframework.util.StringUtils;
import org.springframework.web.filter.OncePerRequestFilter;

@Configuration
@EnableWebSecurity
public class SecurityConfig {

    private static final Logger log = LoggerFactory.getLogger(SecurityConfig.class);

    /**
     * Everything under {@code /api}. Matched with a plain predicate rather than a path-matcher class so
     * the rule does not move when Spring Security's matcher API does.
     */
    private static final RequestMatcher API = request -> request.getRequestURI().startsWith("/api/");

    /** A member's own revoke, under both the versioned and unversioned prefixes. */
    private static final Pattern SELF_REVOKE_PATH = Pattern.compile("^/api(/v1)?/me/relationships/[^/]+/revoke$");

    /**
     * The API requests the CSRF filter lets through without a token: all of them except a signed-in
     * member's revoke. A browser preflights a cross-origin PUT or DELETE with a JSON body, but not a plain
     * POST, so a form on a sibling subdomain, which counts as the same site for the session cookie, could
     * otherwise revoke. The self-service page sends the token in the {@code X-CSRF-TOKEN} header, the name
     * all three implementations read. A revoke with no session is let through to answer 401 like any other
     * API call without one, as on the other two implementations, because the CSRF filter runs ahead of the
     * sign-in check and would otherwise answer 403 for a token the caller could never have held.
     */
    static final RequestMatcher API_WITHOUT_ANTI_FORGERY = request -> API.matches(request)
            && !("POST".equals(request.getMethod())
                    && SELF_REVOKE_PATH.matcher(request.getRequestURI()).matches()
                    && memberSignedIn());

    /**
     * The language switcher's endpoint. Anonymous and outside the CSRF filter: the switcher sits on the
     * public landing page too, which has no session to hold a token. A cross-site form can still submit the
     * switcher, and SameSite=Lax does not stop that, but the most such a request changes is the display
     * language. The same arrangement as the other two implementations, see LanguageController.
     */
    private static final RequestMatcher SET_LANGUAGE =
            request -> "POST".equals(request.getMethod()) && "/set-language".equals(request.getRequestURI());

    /**
     * Every real API path, under both the versioned and unversioned prefixes ({@code /api/v1/...} and
     * {@code /api/...}, since the controller is mapped at both). A request under {@code /api} that
     * matches none of these does not exist, and {@link #unknownPathFilter} answers it 404 before
     * security can turn it into a 401. Method mismatches on a path that does match are left to the
     * dispatcher, which answers 405, the same as the other two.
     */
    private static final Pattern KNOWN_API_PATH =
            Pattern.compile("^/api(/v1)?/(me|me/relationships/[^/]+/(key|revoke)|attest)$");

    /** Where the sign-out form posts, for both protocols. */
    static final String SIGN_OUT_URL = "/signout";

    /** Where every sign-out ends, whether or not the identity provider took part. */
    static final String SIGNED_OUT_URL = "/signed-out";

    /**
     * Every path outside {@code /api} that this server answers: the pages, the manifest, the static files,
     * the language switcher, sign-out, and the sign-in and sign-out routes Spring Security owns, each at
     * its exact shape: the OpenID Connect callback {@code /login/oauth2/code/<registration>}, the SAML
     * assertion consumer {@code /login/saml2/sso/<registration>}, the authorisation start {@code
     * /oauth2/authorization/<registration>}, the SAML authentication start {@code
     * /saml2/authenticate/<registration>}, the service provider metadata {@code
     * /saml2/service-provider-metadata/<registration>}, and the SAML single logout endpoint {@code
     * /logout/saml2/slo}. Anything else, {@code /oauth2/anything} and {@code /login/anything} included,
     * does not exist and is answered 404 before the security chain can redirect it to sign-in, which is
     * what the other two implementations do for a path that matches no route.
     *
     * <p>Spring's provider-selection page at {@code /login} is left out. A deployment configures
     * one protocol with one registration, so the sign-in entry point always goes straight to the provider
     * (see loginEntryPoint) and never to that page. Letting it through would make this implementation
     * answer a page where the other two answer 404.
     *
     * <p>{@code /error} is left out as well. It is Boot's error dispatch target, which the container
     * reaches by an error dispatch this filter does not run on (a {@link OncePerRequestFilter} skips
     * those), so a framework-raised error still gets its status-only answer. A direct request to it is a
     * request for a page that does not exist.
     *
     * <p>The fixed paths are a set, and only the routes that end in a registration are a pattern.
     */
    private static final Set<String> KNOWN_PAGE_PATHS = Set.of(
            "/",
            SIGNED_OUT_URL,
            "/license",
            "/me",
            "/set-language",
            SIGN_OUT_URL,
            SamlRequestParameterScope.SINGLE_LOGOUT_PATH,
            "/.well-known/ata-manifest.json",
            "/trusted-attestation.css",
            "/theme-overrides.css",
            "/favicon.svg",
            "/THIRD-PARTY-NOTICES.txt");

    /** The sign-in and metadata routes Spring Security owns, each ending in one registration. */
    private static final Pattern KNOWN_REGISTRATION_PATH = Pattern.compile("^/(oauth2/authorization|login/oauth2/code"
            + "|login/saml2/sso|saml2/authenticate|saml2/service-provider-metadata)/[^/]+$");

    /**
     * The one Cache-Control value all three implementations send: nothing this server answers is to be
     * stored. No Pragma or Expires alongside it, which the other two do not send either (decision record 35).
     */
    static final String NO_STORE = "no-cache, no-store, max-age=0, must-revalidate";

    /**
     * One year, the Strict-Transport-Security maximum age all the implementations that send it use (decision
     * record 35).
     */
    static final long HSTS_MAX_AGE_SECONDS = 31_536_000L;

    /**
     * One less than the order Spring Boot registers the security filter chain at (its
     * {@code SecurityProperties.DEFAULT_FILTER_ORDER} is {@code -100}), so {@link #unknownPathFilter}
     * runs just ahead of it. Written as a literal rather than referencing that constant, whose package
     * moved between Boot majors.
     */
    private static final int BEFORE_SECURITY_FILTER_CHAIN = -101;

    /**
     * Correlates a SAML assertion to the request it answers through a dedicated {@code SameSite=None}
     * cookie rather than the ({@code SameSite=Lax}) session, so the correlation survives the identity
     * provider's cross-site POST back to the assertion consumer (see {@link
     * CookieSaml2AuthenticationRequestRepository}). Only present under SAML, guarded on the protocol, so it
     * needs no relying-party registrations under OIDC. Spring Security auto-detects this bean and uses it
     * both to save the request and to load it when the response arrives.
     */
    @Bean
    @ConditionalOnProperty(prefix = "trusted-attestation.iam", name = "protocol", havingValue = "SAML")
    Saml2AuthenticationRequestRepository<AbstractSaml2AuthenticationRequest> saml2AuthenticationRequestRepository(
            RelyingPartyRegistrationRepository registrations) {
        return new CookieSaml2AuthenticationRequestRepository(registrations);
    }

    @Bean
    public SecurityFilterChain filterChain(
            HttpSecurity http,
            TrustedAttestationProperties properties,
            MemberIdentityResolver identities,
            ObjectProvider<ClientRegistrationRepository> oidcRegistrations,
            ObjectProvider<RelyingPartyRegistrationRepository> samlRegistrations)
            throws Exception {

        Protocol protocol = properties.getIam().getProtocol();
        Set<String> keptClaims = SessionContents.keptClaims(properties.getIam());

        http.authorizeHttpRequests(authorize -> authorize
                        // Anonymous. A 32-byte random key cannot be guessed, so holding one is itself the
                        // access control, and requiring callers to identify themselves would only let the
                        // organisation learn who is asking.
                        .requestMatchers(request -> request.getRequestURI().endsWith("/attest"))
                        .permitAll()

                        // Public: it describes the organisation, not any member, and a prospective member
                        // has to be able to read it before they have an account.
                        .requestMatchers("/.well-known/**")
                        .permitAll()

                        // The stylesheet has to be readable before anyone has signed in. The pages a
                        // member sees on the way in are the login redirect and any error along the way,
                        // and serving those unstyled makes a working system look broken.
                        .requestMatchers("/trusted-attestation.css", "/theme-overrides.css", "/favicon.svg")
                        .permitAll()

                        // The third-party notices the licence page links to, public for the same reason the
                        // licence page is.
                        .requestMatchers("/THIRD-PARTY-NOTICES.txt")
                        .permitAll()

                        // The SP's own SAML metadata, which the IdP administrator needs to read in order
                        // to register this service provider at all.
                        .requestMatchers("/saml2/service-provider-metadata/**")
                        .permitAll()

                        // The software's own licence page, at the fixed path the footer links to. Public
                        // like the landing page and for the same reason: a licence is a public statement,
                        // readable before anyone has an account.
                        .requestMatchers("/", SIGNED_OUT_URL, "/error", "/license")
                        .permitAll()

                        // The language switcher posts here from the landing page as well as the self-service
                        // page.
                        .requestMatchers(SET_LANGUAGE)
                        .permitAll()
                        .anyRequest()
                        .authenticated())

                // No saved-request cache, which is what otherwise plants a session on an anonymous API
                // call: the ExceptionTranslationFilter saves the request before answering 401 so the user
                // could be returned to it after logging in, and saving it creates the session to hold it.
                // Sign-in never uses that saved request, because both protocols finish at /me
                // unconditionally, so discarding it costs nothing and stops the one empty cookie an
                // unauthenticated 401 would set, matching the other two implementations.
                .requestCache(cache -> cache.requestCache(new NullRequestCache()))
                .logout(logout -> logout.logoutUrl(SIGN_OUT_URL)
                        .invalidateHttpSession(true)
                        .deleteCookies("ata_session")

                        // Ends the session at the identity provider as well, not just here. A local-only
                        // sign-out leaves the member signed in there, so the next visit signs them straight
                        // back in without asking, which is not what anyone means by "sign out".
                        .logoutSuccessHandler(logoutSuccessHandler(protocol, oidcRegistrations.getIfAvailable())))
                .exceptionHandling(exceptions -> exceptions
                        // The API answers with status codes, and only the self-service page redirects to
                        // the identity provider. Without this, an unauthenticated API call would get a 302
                        // instead of the 401 the contract defines.
                        .defaultAuthenticationEntryPointFor(new HttpStatusEntryPoint(HttpStatus.UNAUTHORIZED), API)

                        // Both mappings are needed, not just the one above. Spring Security applies a
                        // single mapping unconditionally, ignoring its matcher, so with only the API rule
                        // registered, the self-service page would answer 401 instead of sending the member
                        // to sign in.
                        .defaultAuthenticationEntryPointFor(
                                loginEntryPoint(protocol, oidcRegistrations, samlRegistrations),
                                AnyRequestMatcher.INSTANCE))

                // Disabled for the API apart from a signed-in member's revoke, the language switcher's
                // endpoint and a sign-out with no live session only.
                //
                // The session cookie is SameSite=Lax, which stops a cross-site page from making the browser
                // attach it to a PUT or DELETE, and these are application/json requests, which are
                // preflighted and fail CORS without a matching policy. The page therefore calls exactly the
                // same operations any other caller would, with no privileged back door of its own, which is
                // also what lets the shared conformance suite drive all three implementations identically.
                // A revoke is a plain POST and needs the token, see API_WITHOUT_ANTI_FORGERY. The switcher's
                // POST has no session to hold a token, see SET_LANGUAGE, and neither does a sign-out after
                // the session has gone, see SIGN_OUT_WITHOUT_SESSION. A missing or wrong token is answered
                // 403 with no body, through the status-only error page.
                .csrf(csrf ->
                        csrf.ignoringRequestMatchers(API_WITHOUT_ANTI_FORGERY, SET_LANGUAGE, SIGN_OUT_WITHOUT_SESSION))

                // Exactly the header set decision record 35 fixes, from one list shared with the 404 answered
                // ahead of this chain, so no response goes out with a different set. See
                // responseHeaderWriters.
                .headers(headers -> {
                    headers.defaultsDisabled();
                    responseHeaderWriters(properties.getTls().isHstsEnabled()).forEach(headers::addHeaderWriter);
                });

        // Astrana Trusted Attestation keeps no user list of its own. It speaks the protocol, not the vendor,
        // so any standards-compliant provider works without custom integration, and which of the two
        // protocols an organisation runs is a deployment setting, not a different build.
        if (protocol == Protocol.SAML) {
            // A SAML assertion arrives as a POST from the IdP, cross-site by nature, so the CSRF filter
            // has to let the assertion consumer endpoint through as well.
            http.csrf(csrf -> csrf.ignoringRequestMatchers(
                    request -> request.getRequestURI().startsWith("/login/saml2/sso/")));

            http.saml2Login(saml -> {
                saml.defaultSuccessUrl("/me", true);
                saml.failureHandler(signInFailureHandler());
                // Refuse an assertion that answers no authentication request this service made. Spring's
                // default response validator checks InResponseTo only when it is present, and an absent one
                // passes, which is how it supports sign-in started at the identity provider. This service
                // does not do that (every sign-in begins at /saml2/authenticate), so an assertion with no
                // InResponseTo is unsolicited, either a forged sign-in or a replayed assertion aimed at the
                // consumer. Requiring InResponseTo to be present closes that, matching the .NET
                // implementation's AllowUnsolicitedAuthnResponse = false.
                saml.authenticationManager(new ProviderManager(solicitedResponsesOnlyProvider(keptClaims, identities)));
            });
            // Single logout from the same sign-out form as everything else. Spring's SAML logout keeps its
            // own address, /logout by default, which the form never posts to, so it is pointed at /signout.
            // It then takes the sign-out only when single logout can complete, see singleLogoutAvailable.
            // Otherwise the plain sign-out above runs and lands on /signed-out. Either way the member ends
            // there, because the LogoutResponse is answered with the same success handler.
            RelyingPartyRegistrationRepository relyingParties = samlRegistrations.getIfAvailable();
            http.saml2Logout(logout -> logout.logoutUrl(SIGN_OUT_URL)
                    .logoutRequest(request -> request.logoutRequestRepository(
                                    new CookieSaml2LogoutRequestRepository(relyingParties))
                            // A logout request the identity provider sends is refused once its NotOnOrAfter
                            // has passed, see UnexpiredLogoutRequestValidator.
                            .logoutRequestValidator(
                                    new UnexpiredLogoutRequestValidator(new OpenSaml5LogoutRequestValidator())))
                    .withObjectPostProcessor(new ObjectPostProcessor<LogoutFilter>() {
                        @Override
                        public <O extends LogoutFilter> O postProcess(O filter) {
                            filter.setLogoutRequestMatcher(
                                    new AndRequestMatcher(SIGN_OUT_POST, singleLogoutAvailable(relyingParties)));
                            return filter;
                        }
                    }));

            // Spring's single logout filter would otherwise read a parameter of every request, which uses
            // up the language switcher's form body. See SamlRequestParameterScope. Spring places that filter
            // just before the CSRF filter when the chain is built and gives it no order of its own, so this
            // goes straight after the header writer, which comes before both.
            http.addFilterAfter(new SamlRequestParameterScope(), HeaderWriterFilter.class);

            // Without a signing key, a logout request the identity provider sends is answered as if the single
            // logout address were absent, ahead of Spring's single logout filter for the same reason. See
            // UnofferedSingleLogout.
            http.addFilterAfter(
                    new UnofferedSingleLogout(relyingParties, SecurityConfig::signsLogoutMessages),
                    HeaderWriterFilter.class);

            // Publishes this SP's own metadata, which is what an IdP administrator registers. It advertises
            // single logout only with a signing key, see withoutSingleLogoutUnlessSigned.
            OpenSaml5MetadataResolver metadata = new OpenSaml5MetadataResolver();
            metadata.setEntityDescriptorCustomizer(SecurityConfig::withoutSingleLogoutUnlessSigned);
            http.saml2Metadata(saml -> saml.metadataResponseResolver(
                    new RequestMatcherMetadataResponseResolver(relyingParties, metadata)));
        } else {
            // Authorization Code + PKCE, the OAuth 2.1 profile decision record 22 in docs/adr requires: no implicit
            // grant, no password grant. PKCE has to be asked for explicitly here, because Spring Security
            // applies it automatically only to public clients, and this is a confidential client (it holds a
            // client secret), so without the resolver below no code_challenge would be sent at all.
            ClientRegistrationRepository registrations = oidcRegistrations.getIfAvailable();
            http.oauth2Login(oauth2 -> {
                oauth2.defaultSuccessUrl("/me", true);
                oauth2.failureHandler(signInFailureHandler());
                // The session keeps only the claims the pages and sign-out use, see SessionContents, and
                // a token with no usable subject is refused like any other bad token.
                oauth2.userInfoEndpoint(userInfo -> userInfo.oidcUserService(
                        requiringASubject(SessionContents.oidcUserService(keptClaims), identities)));
                if (registrations != null) {
                    oauth2.authorizationEndpoint(
                            endpoint -> endpoint.authorizationRequestResolver(pkceResolver(registrations)));
                }
            });
        }

        return http.build();
    }

    /**
     * An authorization request resolver that adds PKCE (a {@code code_challenge}) to every authorization
     * request. Spring Security's {@code DEFAULT_PKCE_APPLIER} runs only for public clients or when the
     * registration sets {@code requireProofKey}. A confidential client with a client secret would
     * otherwise send none, which the OAuth 2.1 profile decision record 22 mandates does not allow.
     */
    private static OAuth2AuthorizationRequestResolver pkceResolver(ClientRegistrationRepository registrations) {
        DefaultOAuth2AuthorizationRequestResolver resolver =
                new DefaultOAuth2AuthorizationRequestResolver(registrations, "/oauth2/authorization");
        resolver.setAuthorizationRequestCustomizer(OAuth2AuthorizationRequestCustomizers.withPkce());

        return resolver;
    }

    /**
     * Answers 404 for a request that matches no real endpoint or page, before the security chain can turn
     * it into a 401 or a redirect to sign-in.
     *
     * <p>On the other two implementations routing runs before authentication, so a request to a path that
     * does not exist is a 404 whether or not the caller is signed in. Here the security filter chain runs
     * first, and its {@code anyRequest().authenticated()} catch-all would answer 401, masking the
     * difference between "this needs a sign-in" and "this is not a thing". Registered ahead of the security
     * chain (see {@link #BEFORE_SECURITY_FILTER_CHAIN}), this restores the shared behaviour without
     * loosening the default-deny posture: known paths pass straight through to security untouched.
     *
     * <p>The 404 carries the same security headers as every other response. It is answered before the
     * security chain, so the chain's header writers never see it, and the same list is applied here.
     */
    @Bean
    public FilterRegistrationBean<OncePerRequestFilter> unknownApiPathFilter(TrustedAttestationProperties properties) {
        FilterRegistrationBean<OncePerRequestFilter> registration = new FilterRegistrationBean<>(
                unknownPathFilter(responseHeaderWriters(properties.getTls().isHstsEnabled())));
        registration.setOrder(BEFORE_SECURITY_FILTER_CHAIN);

        return registration;
    }

    static OncePerRequestFilter unknownPathFilter(List<HeaderWriter> headerWriters) {
        return new OncePerRequestFilter() {
            @Override
            protected void doFilterInternal(HttpServletRequest request, HttpServletResponse response, FilterChain chain)
                    throws ServletException, IOException {
                if (!isKnownPath(request.getRequestURI())) {
                    response.setStatus(HttpStatus.NOT_FOUND.value());
                    headerWriters.forEach(writer -> writer.writeHeaders(request, response));
                    return;
                }

                chain.doFilter(request, response);
            }
        };
    }

    static boolean isKnownPath(String path) {
        boolean api = path.equals("/api") || path.startsWith("/api/");
        return api
                ? KNOWN_API_PATH.matcher(path).matches()
                : KNOWN_PAGE_PATHS.contains(path)
                        || KNOWN_REGISTRATION_PATH.matcher(path).matches();
    }

    /**
     * The security headers decision record 35 fixes, the same set the .NET and PHP implementations send:
     * {@code X-Content-Type-Options: nosniff}, {@code X-Frame-Options: DENY}, {@code Referrer-Policy:
     * no-referrer} (a member's session pages are private to them, so a link they follow to another site
     * should not tell that site, in a Referer header, where they came from), {@code X-XSS-Protection: 0} and
     * {@code Cache-Control} as {@link #NO_STORE}.
     * Spring Security's own cache writer also sends {@code Pragma} and {@code Expires}, which the other two
     * do not, so it is replaced.
     *
     * <p>HSTS follows {@code trusted-attestation.tls.hsts-enabled} rather than being sent unconditionally.
     * Spring's writer sends it even on localhost, and a browser that accepts HSTS for localhost applies it to
     * every port, so a development run here would break unrelated http applications on the same machine.
     * ASP.NET Core skips loopback for exactly this reason. When it is on it is one year with no subdomains and
     * no preload, on secure requests only, the value .NET sends (decision record 35). Spring's default also covers
     * subdomains, which the application cannot see, so that choice is left to the organisation at the proxy.
     *
     * <p>No Content-Security-Policy. The self-service page carries an inline script, so a policy worth having
     * needs per-response nonces threaded through the template. A policy with unsafe-inline would look like
     * protection while permitting exactly what a Content-Security-Policy exists to stop.
     */
    static List<HeaderWriter> responseHeaderWriters(boolean hstsEnabled) {
        List<HeaderWriter> writers = new ArrayList<>(List.of(
                new XContentTypeOptionsHeaderWriter(),
                new XFrameOptionsHeaderWriter(XFrameOptionsHeaderWriter.XFrameOptionsMode.DENY),
                new ReferrerPolicyHeaderWriter(ReferrerPolicyHeaderWriter.ReferrerPolicy.NO_REFERRER),
                new XXssProtectionHeaderWriter(),
                SecurityConfig::writeNoStore));
        if (hstsEnabled) {
            HstsHeaderWriter hsts = new HstsHeaderWriter();
            hsts.setMaxAgeInSeconds(HSTS_MAX_AGE_SECONDS);
            hsts.setIncludeSubDomains(false);
            hsts.setPreload(false);
            writers.add(hsts);
        }

        return List.copyOf(writers);
    }

    /**
     * Cache-Control alone, and only where nothing has set it already, the same rule Spring's own cache writer
     * follows. A 304 is left alone, since it describes a cached response rather than replacing it.
     */
    private static void writeNoStore(HttpServletRequest request, HttpServletResponse response) {
        if (!response.containsHeader(HttpHeaders.CACHE_CONTROL)
                && response.getStatus() != HttpStatus.NOT_MODIFIED.value()) {
            response.setHeader(HttpHeaders.CACHE_CONTROL, NO_STORE);
        }
    }

    /** A POST to the sign-out address, the only way the sign-out form submits. */
    private static final RequestMatcher SIGN_OUT_POST =
            request -> "POST".equals(request.getMethod()) && SIGN_OUT_URL.equals(request.getRequestURI());

    /**
     * A sign-out when no member is signed in, because the session expired or was never there. There is
     * nothing to end, so it is let past the CSRF filter and lands on /signed-out whatever the token says, as
     * on the other two implementations. With no session there is no token to compare against, so the
     * filter would otherwise refuse it. A sign-out with a live session still needs the token.
     */
    static final RequestMatcher SIGN_OUT_WITHOUT_SESSION =
            request -> SIGN_OUT_POST.matches(request) && !memberSignedIn();

    /**
     * Whether the request carries a member's live session. The CSRF filter runs before anonymous
     * authentication is filled in, so a request with no session has no authentication at all here, and one
     * whose session has expired may carry an anonymous one.
     */
    private static boolean memberSignedIn() {
        Authentication authentication = SecurityContextHolder.getContext().getAuthentication();
        return authentication != null && !new AuthenticationTrustResolverImpl().isAnonymous(authentication);
    }

    /**
     * Whether a sign-out can go through the identity provider's single logout and come back. It can when the
     * member signed in over SAML, the provider advertises a single logout endpoint in its metadata, this
     * service provider has a single logout address configured for the provider's LogoutResponse to return
     * to, and the registration has a signing key. Without the address, Spring refuses the response and the
     * member would see an error page. Without the key, Spring cannot make the LogoutRequest at all, because
     * it signs every one. In either case the sign-out stays local and lands on /signed-out, which works
     * whatever the provider supports (decision record 42).
     */
    static RequestMatcher singleLogoutAvailable(RelyingPartyRegistrationRepository registrations) {
        return request -> {
            String registrationId =
                    samlRegistrationId(SecurityContextHolder.getContext().getAuthentication());
            if (registrationId == null || registrations == null) {
                return false;
            }

            RelyingPartyRegistration registration = registrations.findByRegistrationId(registrationId);
            return registration != null
                    && StringUtils.hasText(
                            registration.getAssertingPartyMetadata().getSingleLogoutServiceLocation())
                    && StringUtils.hasText(registration.getSingleLogoutServiceLocation())
                    && StringUtils.hasText(registration.getSingleLogoutServiceResponseLocation())
                    && signsLogoutMessages(registration);
        };
    }

    /**
     * Whether this service provider can take part in single logout at all. Spring signs every LogoutRequest and
     * LogoutResponse it sends and cannot send one unsigned, so without a signing key there is no single logout
     * in either direction, as in the other two implementations.
     */
    static boolean signsLogoutMessages(RelyingPartyRegistration registration) {
        return !registration.getSigningX509Credentials().isEmpty();
    }

    /**
     * Leaves the single logout endpoint out of this service provider's metadata when it has no signing key,
     * so an identity provider that imports the metadata is not told to send logout requests this server
     * cannot answer. The .NET implementation leaves it out on the same condition.
     */
    static void withoutSingleLogoutUnlessSigned(OpenSaml5MetadataResolver.EntityDescriptorParameters parameters) {
        SPSSODescriptor descriptor = parameters.getEntityDescriptor().getSPSSODescriptor(SAMLConstants.SAML20P_NS);
        if (descriptor != null && !signsLogoutMessages(parameters.getRelyingPartyRegistration())) {
            descriptor.getSingleLogoutServices().clear();
        }
    }

    /**
     * The relying party registration a SAML sign-in came through, or null for any other session. Both
     * principal shapes are read, newest first, for the reason MemberIdentityResolver gives.
     */
    private static String samlRegistrationId(Authentication authentication) {
        if (authentication instanceof Saml2AssertionAuthentication assertion) {
            return assertion.getRelyingPartyRegistrationId();
        }
        if (authentication != null && authentication.getPrincipal() instanceof Saml2AuthenticatedPrincipal principal) {
            return principal.getRelyingPartyRegistrationId();
        }
        return null;
    }

    /**
     * Where to send the browser after the local session is gone.
     *
     * <p>Under OpenID Connect that means RP-initiated logout at the provider, carrying the ID token so the
     * provider knows which session is ending, and the provider sends the member back to /signed-out. When the
     * provider advertises no end-session endpoint, or the session had already expired so there is no ID
     * token to send, the member goes straight to /signed-out.
     *
     * <p>Under SAML this handler serves two paths. A sign-out the single logout filter does not take (see
     * {@link #singleLogoutAvailable}) lands here directly. A single logout comes back as the provider's
     * LogoutResponse, which Spring answers with this same handler. Either way the member ends on /signed-out.
     */
    static LogoutSuccessHandler logoutSuccessHandler(Protocol protocol, ClientRegistrationRepository registrations) {
        if (protocol == Protocol.SAML || registrations == null) {
            SimpleUrlLogoutSuccessHandler handler = new SimpleUrlLogoutSuccessHandler();
            handler.setDefaultTargetUrl(SIGNED_OUT_URL);

            return handler;
        }

        OidcClientInitiatedLogoutSuccessHandler handler = new OidcClientInitiatedLogoutSuccessHandler(registrations);
        // A path rather than a query string: the IdP matches post_logout_redirect_uri exactly against
        // its registered list, and "register the query-string variant too" is guidance no operator
        // would guess at. One clean URI to register instead.
        handler.setPostLogoutRedirectUri("{baseUrl}" + SIGNED_OUT_URL);
        // Without this the fallback is "/", the landing page with no confirmation, which is where a member
        // would end when the provider has no end-session endpoint or the session had expired. The other two
        // implementations land on /signed-out in both cases.
        handler.setDefaultTargetUrl(SIGNED_OUT_URL);

        return handler;
    }

    /**
     * Sends an unauthenticated browser straight to the organisation's identity provider when there is
     * exactly one registered provider, which is the normal case. An Astrana Trusted Attestation Server
     * belongs to one organisation, so there is one identity system to sign in against and no meaningful
     * choice to offer. A deployment with several registrations is not supported. Its entry point is
     * Spring's provider-selection page at /login, which this server answers 404 (see KNOWN_PAGE_PATHS).
     */
    private static AuthenticationEntryPoint loginEntryPoint(
            Protocol protocol,
            ObjectProvider<ClientRegistrationRepository> oidcRegistrations,
            ObjectProvider<RelyingPartyRegistrationRepository> samlRegistrations) {

        if (protocol == Protocol.SAML) {
            RelyingPartyRegistrationRepository repository = samlRegistrations.getIfAvailable();
            List<String> ids = registrationIds(
                    repository,
                    RelyingPartyRegistration.class,
                    registration -> ((RelyingPartyRegistration) registration).getRegistrationId());

            return entryPoint(ids, "/saml2/authenticate/");
        }

        ClientRegistrationRepository repository = oidcRegistrations.getIfAvailable();
        List<String> ids = registrationIds(
                repository,
                ClientRegistration.class,
                registration -> ((ClientRegistration) registration).getRegistrationId());

        return entryPoint(ids, "/oauth2/authorization/");
    }

    /** The one registration's own sign-in start, or Spring's /login when there is not exactly one. */
    private static AuthenticationEntryPoint entryPoint(List<String> ids, String signInStart) {
        return new LoginUrlAuthenticationEntryPoint(ids.size() == 1 ? signInStart + ids.getFirst() : "/login");
    }

    /**
     * Both registration repositories are iterable in their in-memory form, which is the only form that
     * can be enumerated at all. Anything else is sent to /login rather than guessing.
     */
    private static List<String> registrationIds(
            Object repository, Class<?> type, java.util.function.Function<Object, String> idOf) {
        List<String> ids = new ArrayList<>();

        if (repository instanceof Iterable<?> iterable) {
            iterable.forEach(registration -> {
                if (type.isInstance(registration)) {
                    ids.add(idOf.apply(registration));
                }
            });
        }

        return ids;
    }

    /**
     * Where a failed sign-in lands, the same place as on the other two implementations: the self-service
     * page, which has no session to show and so starts a fresh sign-in, carrying a marker that says why.
     */
    static final String SIGN_IN_FAILED_URL = "/me?error=login";

    /**
     * Sends a failed sign-in to {@link #SIGN_IN_FAILED_URL} and nothing more. A provider error at the
     * callback, an invalid state, a bad assertion, or a token with no usable subject all land there with
     * no session established. Spring's default would redirect to {@code /login?error}, which this server
     * answers 404, and its default handler stores the exception in the session, which there is no reason
     * to keep.
     */
    static AuthenticationFailureHandler signInFailureHandler() {
        RedirectStrategy redirect = new DefaultRedirectStrategy();

        return (request, response, exception) -> {
            log.warn("A sign-in failed: {}", exception.getMessage());
            redirect.sendRedirect(request, response, SIGN_IN_FAILED_URL);
        };
    }

    /**
     * Spring's user service with one more check: the user it returns must carry a usable subject under the
     * configured claim, otherwise the sign-in fails like any other bad token. Spring itself requires only
     * {@code sub}, and an organisation that keys its members by another claim would otherwise sign a member
     * in as nobody, with a page that shows nothing and an API that answers 401.
     */
    static OAuth2UserService<OidcUserRequest, OidcUser> requiringASubject(
            OAuth2UserService<OidcUserRequest, OidcUser> delegate, MemberIdentityResolver identities) {
        return request -> {
            OidcUser user = delegate.loadUser(request);
            if (identities.subjectOf(user).isEmpty()) {
                throw new OAuth2AuthenticationException(new OAuth2Error(
                        "invalid_token", "the ID token carries no usable subject under the configured claim", null));
            }

            return user;
        };
    }

    /**
     * A SAML authentication provider that additionally requires a response to carry an {@code
     * InResponseTo}, so an unsolicited assertion, one the identity provider sent unasked, is refused, and
     * an assertion to carry a usable subject, under the configured attribute or as its NameID. Everything
     * else is Spring's default: the signature, conditions, audience, and (when present) the InResponseTo
     * correlation are all still validated. This adds the requirement that InResponseTo be present at all,
     * and cuts the authentication the session keeps down to what SessionContents allows.
     */
    private static OpenSaml5AuthenticationProvider solicitedResponsesOnlyProvider(
            Set<String> keptClaims, MemberIdentityResolver identities) {
        OpenSaml5AuthenticationProvider provider = new OpenSaml5AuthenticationProvider();
        OpenSaml5AuthenticationProvider.ResponseAuthenticationConverter signedIn =
                new OpenSaml5AuthenticationProvider.ResponseAuthenticationConverter();
        provider.setResponseAuthenticationConverter(token -> {
            Saml2Authentication authentication =
                    SessionContents.samlAuthentication(signedIn.convert(token), keptClaims);
            if (identities.subject(authentication).isEmpty()) {
                throw new Saml2AuthenticationException(new Saml2Error(
                        "invalid_subject", "the assertion carries no usable subject under the configured attribute"));
            }

            return authentication;
        });
        Converter<OpenSaml5AuthenticationProvider.ResponseToken, Saml2ResponseValidatorResult> defaults =
                OpenSaml5AuthenticationProvider.createDefaultResponseValidator();

        provider.setResponseValidator(token -> {
            Saml2ResponseValidatorResult result = defaults.convert(token);
            if (result == null) {
                result = Saml2ResponseValidatorResult.success();
            }

            String inResponseTo = token.getResponse().getInResponseTo();
            if (inResponseTo == null || inResponseTo.isBlank()) {
                result = result.concat(new Saml2Error(
                        "invalid_in_response_to",
                        "an assertion that answers no authentication request this service made is not a login"));
            }

            // The signature verified, but a signature made with a broken algorithm is no assurance at all.
            // Refuse anything signed or digested with less than SHA-256, so this implementation rejects the
            // same assertion the .NET one does (Sustainsys refuses a sub-SHA-256 signature by default) rather
            // than silently trusting it. See SamlSignatureStrength.
            List<String> weakAlgorithms =
                    SamlSignatureStrength.weakAlgorithms(token.getToken().getSaml2Response());
            if (!weakAlgorithms.isEmpty()) {
                result = result.concat(new Saml2Error(
                        "weak_signature_algorithm",
                        "a SAML signature weaker than SHA-256 is refused: " + weakAlgorithms));
            }

            return result;
        });

        // Hold the assertion clock-skew tolerance at the value the other two implementations use, rather
        // than OpenSAML's five-minute default. See SAML_CLOCK_SKEW.
        provider.setAssertionValidator(OpenSaml5AuthenticationProvider.createDefaultAssertionValidatorWithParameters(
                parameters -> parameters.put(SAML2AssertionValidationParameters.CLOCK_SKEW, SAML_CLOCK_SKEW)));

        return provider;
    }

    /**
     * How far a SAML assertion's timestamps (NotBefore / NotOnOrAfter) may be out before it is refused.
     *
     * <p>180 seconds, the same value the .NET (Sustainsys) and PHP (OneLogin) implementations use, so the
     * same assertion is accepted or refused whichever implementation receives it. It is the low end of the
     * three-to-five-minute window the SAML ecosystem settled on (it is OneLogin's default), wide enough to
     * absorb the clock drift that builds up between time server resynchronisations, from hypervisor
     * scheduling and load, and narrow enough to keep the replay window of an intercepted bearer assertion
     * tight. Looser than the 60-second skew used for OIDC ID tokens, which are validated the instant a fast
     * redirect returns, because a SAML assertion's timing budget is the wider one its own ecosystem uses.
     */
    private static final java.time.Duration SAML_CLOCK_SKEW = java.time.Duration.ofSeconds(180);
}
