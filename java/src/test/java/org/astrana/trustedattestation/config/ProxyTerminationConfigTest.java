package org.astrana.trustedattestation.config;

import static org.assertj.core.api.Assertions.assertThat;

import jakarta.servlet.ServletException;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletRequestWrapper;
import java.io.IOException;
import java.util.Collections;
import java.util.Locale;
import java.util.Map;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.ValueSource;
import org.springframework.boot.test.context.runner.ApplicationContextRunner;
import org.springframework.boot.web.servlet.FilterRegistrationBean;
import org.springframework.mock.web.MockFilterChain;
import org.springframework.mock.web.MockHttpServletRequest;
import org.springframework.mock.web.MockHttpServletResponse;
import org.springframework.web.filter.ForwardedHeaderFilter;

/**
 * Proxy mode: when its two filters exist, in what order they run, what the scheme guard refuses, and what
 * the forwarded filter applies.
 *
 * <p>The guard has to see the forwarded headers before Spring's forwarded filter hides them, which is an
 * ordering fact a running server would only reveal by letting plain-HTTP requests through, so the orders are
 * pinned here. And the setting switches both filters on only for the literal {@code true}, because the
 * annotation's default would have read {@code no} as on. Both filters act on {@code X-Forwarded-Proto}
 * alone for the scheme, so the RFC 7239 {@code Forwarded} header and {@code X-Forwarded-Ssl} are shown to
 * be neither judged nor applied.
 */
class ProxyTerminationConfigTest {

    private final ApplicationContextRunner contexts = new ApplicationContextRunner()
            .withUserConfiguration(ProxyTerminationConfig.class)
            .withBean(TrustedAttestationProperties.class);

    // -- Registration -----------------------------------------------------------------------------------

    @Test
    void behindADeclaredProxyBothFiltersAreRegisteredWithTheGuardAhead() {
        contexts.withPropertyValues("trusted-attestation.tls.terminated-by-proxy=true")
                .run(context -> {
                    Map<String, FilterRegistrationBean> registrations =
                            context.getBeansOfType(FilterRegistrationBean.class);

                    assertThat(registrations).containsOnlyKeys("forwardedSchemeGuardFilter", "forwardedHeaderFilter");
                    FilterRegistrationBean<?> guard = registrations.get("forwardedSchemeGuardFilter");
                    FilterRegistrationBean<?> forwarded = registrations.get("forwardedHeaderFilter");
                    assertThat(forwarded.getFilter()).isInstanceOf(ForwardedHeaderFilter.class);
                    assertThat(guard.getOrder()).isLessThan(forwarded.getOrder());
                    assertThat(guard.getOrder()).isEqualTo(ProxyTerminationConfig.FORWARDED_SCHEME_GUARD_ORDER);
                });
    }

    @Test
    void aValueOfNoRegistersNeitherFilter() {
        // The annotation's default matches anything but the literal "false", so without havingValue a
        // setting of "no" would have switched proxy mode on.
        for (String value : new String[] {"no", "off", "false", "0", "yes"}) {
            contexts.withPropertyValues("trusted-attestation.tls.terminated-by-proxy=" + value)
                    .run(context -> assertThat(context.getBeansOfType(FilterRegistrationBean.class))
                            .as("filters for '%s'", value)
                            .isEmpty());
        }
    }

    @Test
    void anUnsetValueRegistersNeitherFilter() {
        contexts.run(context ->
                assertThat(context.getBeansOfType(FilterRegistrationBean.class)).isEmpty());
    }

    // -- The guard ---------------------------------------------------------------------------------------

    @Test
    void aRequestTheProxySaysArrivedOverPlainHttpIs421WithTheSecurityHeadersAndNoBody() throws Exception {
        MockHttpServletResponse response = guarded("X-Forwarded-Proto", "http");

        assertThat(response.getStatus()).isEqualTo(421);
        assertThat(response.getContentAsByteArray()).isEmpty();
        assertThat(response.getContentType()).isNull();
        assertThat(response.getHeader("X-Content-Type-Options")).isEqualTo("nosniff");
        assertThat(response.getHeader("X-Frame-Options")).isEqualTo("DENY");
        assertThat(response.getHeader("Referrer-Policy")).isEqualTo("no-referrer");
        assertThat(response.getHeader("X-XSS-Protection")).isEqualTo("0");
        assertThat(response.getHeader("Cache-Control")).isEqualTo(SecurityConfig.NO_STORE);
    }

    @Test
    void aRequestTheProxySaysArrivedOverHttpsPasses() throws Exception {
        assertThat(guarded("X-Forwarded-Proto", "https").getStatus()).isEqualTo(200);
    }

    @Test
    void aSingleForwardedSchemeIsAcceptedOnlyWhenItIsExactlyHttps() throws Exception {
        // A spelling other than lower-case https is not what the proxy sends, and is refused. Surrounding
        // spaces are not part of the value, and a header of separators alone carries no value at all.
        assertThat(guarded("X-Forwarded-Proto", "https").getStatus()).isEqualTo(200);
        assertThat(guarded("X-Forwarded-Proto", " https ").getStatus()).isEqualTo(200);
        assertThat(guarded("X-Forwarded-Proto", "HTTPS").getStatus()).isEqualTo(421);
        assertThat(guarded("X-Forwarded-Proto", ",").getStatus()).isEqualTo(421);
    }

    @Test
    void aForwardedSchemeHeaderThatIsPresentButEmptyIsRefused() throws Exception {
        // A proxy that sets the header always gives it a value, and the frameworks read an empty one
        // differently, so all three implementations refuse it.
        assertThat(guarded("X-Forwarded-Proto", "").getStatus()).isEqualTo(421);
        assertThat(guarded("X-Forwarded-Proto", " ").getStatus()).isEqualTo(421);
    }

    @Test
    void aForwardedSchemeWithMoreThanOneValueIsRefusedWhicheverValueComesFirst() throws Exception {
        // Spring applies the first value and ASP.NET Core the last, so "https, http" would be accepted by one
        // and refused by the other. The single declared proxy sends one value, so the strictest reading is
        // the shared one: more than one value is refused, in one line or across several.
        assertThat(guarded("X-Forwarded-Proto", "https, http").getStatus()).isEqualTo(421);
        assertThat(guarded("X-Forwarded-Proto", "http, https").getStatus()).isEqualTo(421);
        assertThat(guarded("X-Forwarded-Proto", "https, https").getStatus()).isEqualTo(421);
        assertThat(guarded("X-Forwarded-Proto", "https,").getStatus()).isEqualTo(421);
        assertThat(guarded("X-Forwarded-Proto", ",https").getStatus()).isEqualTo(421);

        MockHttpServletRequest twoLines = new MockHttpServletRequest("GET", "/me");
        twoLines.addHeader("X-Forwarded-Proto", "https");
        twoLines.addHeader("X-Forwarded-Proto", "https");
        assertThat(guard(twoLines).getStatus()).isEqualTo(421);
    }

    @Test
    void anRfc7239ForwardedHeaderAloneIsNotJudgedAndTheRequestIsServed() throws Exception {
        // Only X-Forwarded-Proto is judged, as on the other two implementations, so a Forwarded header
        // that says http is not a forwarded scheme at all, and neither is one with several elements.
        assertThat(guarded("Forwarded", "proto=http").getStatus()).isEqualTo(200);
        assertThat(guarded("Forwarded", "proto=https, proto=http").getStatus()).isEqualTo(200);
    }

    @Test
    void anRfc7239ForwardedHeaderBesideXForwardedProtoHttpIsRefusedOnXForwardedProtoAlone() throws Exception {
        MockHttpServletRequest refused = new MockHttpServletRequest("GET", "/me");
        refused.addHeader("Forwarded", "for=1.2.3.4");
        refused.addHeader("X-Forwarded-Proto", "http");
        assertThat(guard(refused).getStatus()).isEqualTo(421);

        // A Forwarded header that says http does not outweigh an X-Forwarded-Proto of https either.
        MockHttpServletRequest served = new MockHttpServletRequest("GET", "/me");
        served.addHeader("Forwarded", "proto=http");
        served.addHeader("X-Forwarded-Proto", "https");
        assertThat(guard(served).getStatus()).isEqualTo(200);
    }

    @Test
    void xForwardedSslOnAloneIsServedWithoutBeingJudged() throws Exception {
        assertThat(guarded("X-Forwarded-Ssl", "on").getStatus()).isEqualTo(200);
    }

    @Test
    void aRequestWithNoForwardedSchemePasses() throws Exception {
        // Spring's filter leaves such a request's scheme alone, so there is nothing the proxy said to refuse.
        MockHttpServletRequest request = new MockHttpServletRequest("GET", "/me");

        assertThat(guard(request).getStatus()).isEqualTo(200);
    }

    // -- What the forwarded filter applies ---------------------------------------------------------------

    @Test
    void anRfc7239ForwardedHeaderChangesNeitherTheSchemeNorTheHostNorThePortTheApplicationSees() throws Exception {
        MockHttpServletRequest request = new MockHttpServletRequest("GET", "/me");
        request.addHeader("Forwarded", "proto=https;host=elsewhere.example:8443");

        HttpServletRequest seen = forwardedThroughTheFilter(request);

        assertThat(seen.getScheme()).isEqualTo("http");
        assertThat(seen.isSecure()).isFalse();
        assertThat(seen.getServerName()).isEqualTo("localhost");
        assertThat(seen.getServerPort()).isEqualTo(80);
        assertThat(seen.getRequestURL()).hasToString("http://localhost/me");
    }

    @Test
    void xForwardedSslOnDoesNotMakeTheApplicationSeeHttps() throws Exception {
        MockHttpServletRequest request = new MockHttpServletRequest("GET", "/me");
        request.addHeader("X-Forwarded-Ssl", "on");

        HttpServletRequest seen = forwardedThroughTheFilter(request);

        assertThat(seen.getScheme()).isEqualTo("http");
        assertThat(seen.isSecure()).isFalse();
    }

    @Test
    void xForwardedProtoAndHostAreStillAppliedAndAForwardedHeaderBesideThemIsIgnored() throws Exception {
        // The proxy's own headers are what every redirect is built from. A Forwarded header beside them
        // that names another scheme and host changes nothing.
        MockHttpServletRequest request = new MockHttpServletRequest("GET", "/me");
        request.addHeader("X-Forwarded-Proto", "https");
        request.addHeader("X-Forwarded-Host", "ata.example");
        request.addHeader("Forwarded", "proto=http;host=elsewhere.example");

        HttpServletRequest seen = forwardedThroughTheFilter(request);

        assertThat(seen.getScheme()).isEqualTo("https");
        assertThat(seen.isSecure()).isTrue();
        assertThat(seen.getServerName()).isEqualTo("ata.example");
        assertThat(seen.getServerPort()).isEqualTo(443);
    }

    @Test
    void noForwardedHeaderReachesTheRestOfTheChain() throws Exception {
        MockHttpServletRequest request = new MockHttpServletRequest("GET", "/me");
        request.addHeader("X-Forwarded-Proto", "https");
        request.addHeader("forwarded", "proto=http");
        request.addHeader("X-Forwarded-Ssl", "on");

        HttpServletRequest seen = forwardedThroughTheFilter(request);

        assertThat(seen.getHeader("Forwarded")).isNull();
        assertThat(seen.getHeader("X-Forwarded-Ssl")).isNull();
        assertThat(seen.getHeader("X-Forwarded-Proto")).isNull();
        assertThat(Collections.list(seen.getHeaderNames()))
                .noneMatch(name ->
                        name.toLowerCase(Locale.ROOT).startsWith("x-forwarded") || name.equalsIgnoreCase("Forwarded"));
    }

    @ParameterizedTest
    @ValueSource(strings = {"*/*", "*", "text/*", "application/*+json", "*/*; q=0.5"})
    void aRequestWhoseContentTypeIsAWildcardIsServedAsOneWithNoContentType(String contentType) throws Exception {
        // Spring cannot hold a wildcard Content-Type once a character encoding is set, which Spring Boot
        // does to every request ahead of this filter, and its filter refused such a request with HTTP 400.
        // The other two implementations read the body whatever the Content-Type says.
        MockHttpServletRequest request = new MockHttpServletRequest("POST", "/api/v1/attest");
        request.addHeader("X-Forwarded-Proto", "https");
        request.addHeader("Content-Type", contentType);
        MockHttpServletResponse response = new MockHttpServletResponse();
        MockFilterChain chain = new MockFilterChain();

        new ProxyTerminationConfig.XForwardedHeaderFilter().doFilter(utf8(request), response, chain);

        assertThat(response.getStatus()).isEqualTo(200);
        HttpServletRequest seen = (HttpServletRequest) chain.getRequest();
        assertThat(seen.getContentType()).isNull();
        assertThat(seen.getHeader("Content-Type")).isNull();
        assertThat(Collections.list(seen.getHeaders("Content-Type"))).isEmpty();
        assertThat(Collections.list(seen.getHeaderNames())).noneMatch("Content-Type"::equalsIgnoreCase);
    }

    @ParameterizedTest
    @ValueSource(strings = {"application/json", "text/plain; charset=utf-8", "application/x-www-form-urlencoded"})
    void aRequestWhoseContentTypeNamesOneMediaTypeKeepsIt(String contentType) throws Exception {
        MockHttpServletRequest request = new MockHttpServletRequest("POST", "/api/v1/attest");
        request.addHeader("Content-Type", contentType);

        HttpServletRequest seen = forwardedThroughTheFilter(request);

        assertThat(seen.getHeader("Content-Type")).isEqualTo(contentType);
        assertThat(seen.getContentType()).isEqualTo(contentType);
    }

    // -- Helpers ----------------------------------------------------------------------------------------

    /**
     * The request with its character encoding set to UTF-8 as Spring Boot's encoding filter sets it on
     * Tomcat's request, which leaves the Content-Type header as it arrived. The mock request would write
     * the charset into the header instead.
     */
    private static HttpServletRequest utf8(MockHttpServletRequest request) {
        return new HttpServletRequestWrapper(request) {
            @Override
            public String getCharacterEncoding() {
                return "UTF-8";
            }
        };
    }

    /** The request the rest of the chain sees once the forwarded filter has run. */
    private static HttpServletRequest forwardedThroughTheFilter(MockHttpServletRequest request)
            throws ServletException, IOException {
        MockFilterChain chain = new MockFilterChain();
        new ProxyTerminationConfig.XForwardedHeaderFilter().doFilter(request, new MockHttpServletResponse(), chain);
        return (HttpServletRequest) chain.getRequest();
    }

    private static MockHttpServletResponse guarded(String header, String value) throws ServletException, IOException {
        MockHttpServletRequest request = new MockHttpServletRequest("GET", "/me");
        request.addHeader(header, value);
        return guard(request);
    }

    private static MockHttpServletResponse guard(MockHttpServletRequest request) throws ServletException, IOException {
        MockHttpServletResponse response = new MockHttpServletResponse();
        ProxyTerminationConfig.forwardedSchemeGuard(SecurityConfig.responseHeaderWriters(false))
                .doFilter(request, response, new MockFilterChain());
        return response;
    }
}
