package org.astrana.trustedattestation.config;

import jakarta.servlet.FilterChain;
import jakarta.servlet.ServletException;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletRequestWrapper;
import jakarta.servlet.http.HttpServletResponse;
import java.io.IOException;
import org.springframework.web.filter.OncePerRequestFilter;

/**
 * Keeps Spring's SAML single logout filter from reading a request parameter on every request. It sits
 * ahead of that filter in the SAML chain.
 *
 * <p>Spring's single logout filter asks every request for its {@code SAMLRequest} parameter before it
 * checks the address. Under Tomcat, asking a form post for any parameter parses its body, which can then
 * no longer be read. The language switcher reads its form from the body, so behind that filter it found
 * an empty form, set no cookie and sent the member back to the landing page. Away from the single logout
 * address there is no {@code SAMLRequest} to find, so the rest of the chain is handed a request that
 * answers null for it without asking the container. At the single logout address the request passes on as
 * it is.
 */
class SamlRequestParameterScope extends OncePerRequestFilter {

    /** Where an identity provider delivers a LogoutRequest, Spring's default for the single logout filter. */
    static final String SINGLE_LOGOUT_PATH = "/logout/saml2/slo";

    private static final String SAML_REQUEST = "SAMLRequest";

    @Override
    protected void doFilterInternal(HttpServletRequest request, HttpServletResponse response, FilterChain chain)
            throws ServletException, IOException {
        chain.doFilter(
                SINGLE_LOGOUT_PATH.equals(request.getRequestURI()) ? request : new WithoutSamlRequest(request),
                response);
    }

    private static final class WithoutSamlRequest extends HttpServletRequestWrapper {

        WithoutSamlRequest(HttpServletRequest request) {
            super(request);
        }

        @Override
        public String getParameter(String name) {
            return SAML_REQUEST.equals(name) ? null : super.getParameter(name);
        }
    }
}
