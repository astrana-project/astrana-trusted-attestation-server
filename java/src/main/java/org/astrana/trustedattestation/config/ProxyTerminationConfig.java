package org.astrana.trustedattestation.config;

import jakarta.servlet.FilterChain;
import jakarta.servlet.ServletException;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletRequestWrapper;
import jakarta.servlet.http.HttpServletResponse;
import java.io.IOException;
import java.util.ArrayList;
import java.util.Collections;
import java.util.Enumeration;
import java.util.List;
import org.springframework.boot.autoconfigure.condition.ConditionalOnProperty;
import org.springframework.boot.web.servlet.FilterRegistrationBean;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.core.Ordered;
import org.springframework.http.HttpStatus;
import org.springframework.security.web.header.HeaderWriter;
import org.springframework.util.StringUtils;
import org.springframework.web.filter.ForwardedHeaderFilter;
import org.springframework.web.filter.OncePerRequestFilter;

/**
 * The two halves of running behind a TLS-terminating proxy, present only when the operator has declared
 * one with {@code trusted-attestation.tls.terminated-by-proxy: true}.
 *
 * <p>The honouring half is Spring's {@link ForwardedHeaderFilter}, which rewrites the request's scheme and
 * host from the forwarded headers so every redirect and redirect_uri the application builds carries what
 * the member's browser used. Without it, behind a proxy such as the demonstration's, the application
 * generates http:// redirects to an https port and the first sign-in fails. Trusting forwarded headers
 * unconditionally would let any direct client rewrite the scheme and host the application believes in,
 * which is why the setting exists. The filter is handed the request without the RFC 7239 {@code
 * Forwarded} header and {@code X-Forwarded-Ssl} (see {@link XForwardedHeaderFilter}), so the scheme comes
 * from {@code X-Forwarded-Proto} alone, the one scheme header all three implementations judge and apply.
 *
 * <p>The refusing half is {@link #forwardedSchemeGuard}. With TLS at the proxy the application can no
 * longer see the scheme itself, so it trusts {@code X-Forwarded-Proto}, and must then refuse anything the
 * proxy says arrived over plain HTTP. Without it, "terminated by proxy" would be a way to turn the TLS
 * requirement off.
 *
 * <p>Both are registration beans at the front of the filter chain, not bare Filter beans. Boot registers
 * those at lowest precedence, after the security filter chain, so by the time the forwarded filter rewrote
 * the scheme every security redirect would already have been built against the raw connection. The guard
 * runs one place ahead of the forwarded filter, because that filter wraps the request so the forwarded
 * headers are no longer visible to anything after it, and a guard behind it would read nothing and refuse
 * nothing.
 *
 * <p>{@code havingValue = "true"} on both, because the annotation's default matches any value other than
 * the literal {@code false}, so a setting of {@code no} or {@code off} would have switched proxy mode on.
 */
@Configuration
public class ProxyTerminationConfig {

    /** The guard runs first of all, see the class comment. */
    static final int FORWARDED_SCHEME_GUARD_ORDER = Ordered.HIGHEST_PRECEDENCE;

    /** The forwarded filter runs right behind the guard, so nothing else sees the raw connection scheme. */
    static final int FORWARDED_HEADER_FILTER_ORDER = Ordered.HIGHEST_PRECEDENCE + 1;

    /**
     * The forwarded headers Spring's filter would otherwise apply and this server does not honour. The RFC
     * 7239 {@code Forwarded} header can carry a scheme, host and port of its own, and {@code X-Forwarded-Ssl:
     * on} stands in for an https scheme. Neither is a header the other two implementations read.
     */
    static final List<String> UNHONOURED_HEADERS = List.of("Forwarded", "X-Forwarded-Ssl");

    @Bean
    @ConditionalOnProperty(name = "trusted-attestation.tls.terminated-by-proxy", havingValue = "true")
    public FilterRegistrationBean<OncePerRequestFilter> forwardedSchemeGuardFilter(
            TrustedAttestationProperties properties) {
        FilterRegistrationBean<OncePerRequestFilter> registration = new FilterRegistrationBean<>(forwardedSchemeGuard(
                SecurityConfig.responseHeaderWriters(properties.getTls().isHstsEnabled())));
        registration.setOrder(FORWARDED_SCHEME_GUARD_ORDER);

        return registration;
    }

    @Bean
    @ConditionalOnProperty(name = "trusted-attestation.tls.terminated-by-proxy", havingValue = "true")
    public FilterRegistrationBean<ForwardedHeaderFilter> forwardedHeaderFilter() {
        FilterRegistrationBean<ForwardedHeaderFilter> registration =
                new FilterRegistrationBean<>(new XForwardedHeaderFilter());
        registration.setOrder(FORWARDED_HEADER_FILTER_ORDER);

        return registration;
    }

    /**
     * Refuses, with HTTP 421 and no body, a request whose forwarded scheme is anything but exactly {@code
     * https}. The 421 carries the same security headers as every other response, because it is answered
     * ahead of the security chain whose writers would otherwise add them.
     *
     * <p>A request with no forwarded scheme at all passes. The forwarded filter leaves such a request's
     * scheme alone, so there is nothing the proxy said to refuse.
     */
    static OncePerRequestFilter forwardedSchemeGuard(List<HeaderWriter> headerWriters) {
        return new OncePerRequestFilter() {
            @Override
            protected void doFilterInternal(HttpServletRequest request, HttpServletResponse response, FilterChain chain)
                    throws ServletException, IOException {
                String scheme = forwardedScheme(request);

                if (scheme != null && !"https".equals(scheme)) {
                    response.setStatus(HttpStatus.MISDIRECTED_REQUEST.value());
                    headerWriters.forEach(writer -> writer.writeHeaders(request, response));
                    return;
                }

                chain.doFilter(request, response);
            }
        };
    }

    /**
     * The forwarded scheme this request carries, or null when it carries none. Only {@code
     * X-Forwarded-Proto} is read, as on the other two implementations. The RFC 7239 {@code Forwarded}
     * header and {@code X-Forwarded-Ssl} are neither judged here nor applied by the forwarded filter, so a
     * request carrying only one of them is served on the scheme of its own connection.
     *
     * <p>A forwarded scheme with more than one value is answered as an empty token, which the guard
     * refuses: more than one comma-separated value, or more than one header line. The single declared
     * proxy in front of this server sends one value, so several mean something else added to the chain.
     * Spring would apply the first value and ASP.NET Core the last, so the two would disagree over the same
     * request, and refusing it is the strictest posture, the one decision record 5 makes the shared one. A header
     * that is present but empty, or holds only spaces or separators, is answered the same way, because a
     * proxy that sets the header always gives it a value. A single value is compared exactly afterwards, so
     * {@code HTTPS} is refused: the proxy sets the header from its own knowledge, and only its spelling is
     * expected.
     */
    static String forwardedScheme(HttpServletRequest request) {
        List<String> forwardedProto = values(request, "X-Forwarded-Proto");
        if (!forwardedProto.isEmpty()) {
            return forwardedProto.size() == 1 ? forwardedProto.getFirst() : "";
        }

        return hasHeaderLine(request, "X-Forwarded-Proto") ? "" : null;
    }

    /**
     * Every comma-separated value of a header across all of its lines, trimmed. An empty value between or
     * after commas is kept, so {@code https,} counts as two values, as it does in the other two
     * implementations. One line holding two values and two lines holding one each both give two.
     */
    private static List<String> values(HttpServletRequest request, String name) {
        List<String> values = new ArrayList<>();
        Enumeration<String> lines = request.getHeaders(name);
        while (lines != null && lines.hasMoreElements()) {
            for (String value : StringUtils.delimitedListToStringArray(lines.nextElement(), ",")) {
                values.add(value.trim());
            }
        }

        return values;
    }

    /** Whether the header is present at all, even empty. */
    private static boolean hasHeaderLine(HttpServletRequest request, String name) {
        Enumeration<String> lines = request.getHeaders(name);
        return lines != null && lines.hasMoreElements();
    }

    /**
     * Spring's forwarded filter, handed the request with the {@link #UNHONOURED_HEADERS} hidden, so neither
     * can change the scheme, host or port the application sees. Spring offers no setting that leaves both
     * out: its filter can be told to ignore {@code Forwarded}, but still reads {@code X-Forwarded-Ssl}
     * whenever {@code X-Forwarded-Proto} is absent. Everything else is the filter's own behaviour, including
     * hiding every forwarded header from the rest of the chain once applied.
     */
    static final class XForwardedHeaderFilter extends ForwardedHeaderFilter {

        @Override
        protected void doFilterInternal(HttpServletRequest request, HttpServletResponse response, FilterChain chain)
                throws ServletException, IOException {
            super.doFilterInternal(new UnhonouredHeadersHidden(request), response, chain);
        }
    }

    /** The request as it arrived, less the {@link #UNHONOURED_HEADERS}, whatever their case. */
    private static final class UnhonouredHeadersHidden extends HttpServletRequestWrapper {

        UnhonouredHeadersHidden(HttpServletRequest request) {
            super(request);
        }

        @Override
        public String getHeader(String name) {
            return isUnhonoured(name) ? null : super.getHeader(name);
        }

        @Override
        public Enumeration<String> getHeaders(String name) {
            return isUnhonoured(name) ? Collections.emptyEnumeration() : super.getHeaders(name);
        }

        @Override
        public Enumeration<String> getHeaderNames() {
            return Collections.enumeration(Collections.list(super.getHeaderNames()).stream()
                    .filter(name -> !isUnhonoured(name))
                    .toList());
        }

        private static boolean isUnhonoured(String name) {
            return UNHONOURED_HEADERS.stream().anyMatch(name::equalsIgnoreCase);
        }
    }
}
