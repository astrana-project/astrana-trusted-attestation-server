package org.astrana.trustedattestation.config;

import jakarta.servlet.FilterChain;
import jakarta.servlet.ServletException;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import java.io.IOException;
import java.util.function.Predicate;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.http.HttpStatus;
import org.springframework.security.saml2.provider.service.registration.RelyingPartyRegistration;
import org.springframework.security.saml2.provider.service.registration.RelyingPartyRegistrationRepository;
import org.springframework.web.filter.OncePerRequestFilter;

/**
 * Answers a logout request the identity provider sends as if the single logout address were absent, when this
 * service provider has no signing key.
 *
 * <p>The answer to a logout request has to be a signed LogoutResponse, so without a key there is no single
 * logout and the metadata advertises none. A provider that sends one anyway gets HTTP 404 with no body and no
 * content type, the member's session is left as it was, and a warning names the cause. Left to Spring's single
 * logout filter, the request would end the session and then fail with HTTP 500 for want of a key to sign the
 * answer. The other two implementations answer it the same way.
 *
 * <p>The decision is made for the deployment as a whole. A deployment has one relying party registration
 * (see SecurityConfig's sign-in entry point), and when none of its registrations has a signing key, none can
 * answer. It sits ahead of Spring's single logout filter in the SAML chain, and with a key it lets every request
 * through untouched.
 */
class UnofferedSingleLogout extends OncePerRequestFilter {

    private static final Logger log = LoggerFactory.getLogger(UnofferedSingleLogout.class);

    private final boolean offered;

    UnofferedSingleLogout(RelyingPartyRegistrationRepository registrations, Predicate<RelyingPartyRegistration> signs) {
        this.offered = anyRegistrationSigns(registrations, signs);
    }

    @Override
    protected void doFilterInternal(HttpServletRequest request, HttpServletResponse response, FilterChain chain)
            throws ServletException, IOException {
        if (offered
                || !SamlRequestParameterScope.SINGLE_LOGOUT_PATH.equals(request.getRequestURI())
                || request.getParameter("SAMLRequest") == null) {
            chain.doFilter(request, response);
            return;
        }

        log.warn("A SAML LogoutRequest arrived, but no signing key is configured to answer it, so single logout is "
                + "not offered and the request was refused.");
        response.setStatus(HttpStatus.NOT_FOUND.value());
    }

    /**
     * Whether any registration can sign. A repository that cannot be listed is taken to offer single logout,
     * so Spring's own filter decides.
     */
    private static boolean anyRegistrationSigns(
            RelyingPartyRegistrationRepository registrations, Predicate<RelyingPartyRegistration> signs) {
        if (!(registrations instanceof Iterable<?> iterable)) {
            return true;
        }

        for (Object registration : iterable) {
            if (registration instanceof RelyingPartyRegistration relyingParty && signs.test(relyingParty)) {
                return true;
            }
        }
        return false;
    }
}
