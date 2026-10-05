package org.astrana.trustedattestation.config;

import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import java.util.LinkedHashMap;
import java.util.Map;
import java.util.Optional;
import org.springframework.security.saml2.provider.service.authentication.AbstractSaml2AuthenticationRequest;
import org.springframework.security.saml2.provider.service.authentication.Saml2PostAuthenticationRequest;
import org.springframework.security.saml2.provider.service.authentication.Saml2RedirectAuthenticationRequest;
import org.springframework.security.saml2.provider.service.registration.RelyingPartyRegistration;
import org.springframework.security.saml2.provider.service.registration.RelyingPartyRegistrationRepository;
import org.springframework.security.saml2.provider.service.registration.Saml2MessageBinding;
import org.springframework.security.saml2.provider.service.web.Saml2AuthenticationRequestRepository;

/**
 * Stores the outgoing SAML AuthnRequest in a dedicated cookie instead of the HTTP session, so the
 * assertion that answers it can be correlated when it comes back.
 *
 * <p>The default {@code HttpSessionSaml2AuthenticationRequestRepository} keeps the request in the session,
 * which is reached through the session cookie -- and that cookie is {@code SameSite=Lax}, as the contract
 * requires. A SAML response arrives over the HTTP-POST binding as a top-level, cross-site POST from the
 * IdP's origin, and a browser does not send a {@code Lax} cookie on such a request. The session is
 * therefore absent at the assertion consumer endpoint, the saved request cannot be found, and this
 * service -- which refuses an assertion answering no request it made -- rejects a perfectly good login.
 * Form-based test clients hide this because they carry every cookie regardless of {@code SameSite}, and a
 * real browser does not.
 *
 * <p>This is the same shape the .NET implementation gets from Sustainsys, which keeps its correlation state
 * in its own {@code SameSite=None} cookie while the session cookie stays {@code Lax}. Here the session
 * cookie is untouched -- it stays {@code Lax} for CSRF -- and only this short-lived, request-scoped
 * correlation cookie is {@code SameSite=None} so it survives the cross-site POST.
 *
 * <p>The request's fields are stored as JSON and the object is rebuilt from them; the cookie is never
 * Java-deserialised, so a forged cookie cannot be a deserialisation gadget. Forging the cookie buys
 * nothing on its own either: the assertion it would correlate to still has to carry a valid IdP signature.
 */
final class CookieSaml2AuthenticationRequestRepository
        implements Saml2AuthenticationRequestRepository<AbstractSaml2AuthenticationRequest> {

    private static final SamlCorrelationCookie COOKIE = new SamlCorrelationCookie("ata_saml_authn");
    // The rebuilt request needs a non-blank samlRequest (the constructor asserts it), but the response side
    // never reads it -- only the id it is correlated by. A placeholder keeps the object valid without
    // carrying the real, oversized outgoing request in the cookie.
    private static final String SAML_REQUEST_PLACEHOLDER = "saved";
    private static final String RELAY_STATE = "relayState";

    private final RelyingPartyRegistrationRepository registrations;

    CookieSaml2AuthenticationRequestRepository(RelyingPartyRegistrationRepository registrations) {
        this.registrations = registrations;
    }

    @Override
    public void saveAuthenticationRequest(
            AbstractSaml2AuthenticationRequest request,
            HttpServletRequest httpRequest,
            HttpServletResponse httpResponse) {
        if (request == null) {
            removeAuthenticationRequest(httpRequest, httpResponse);
            return;
        }
        // Only what correlating the response needs: the request id (matched against the response's
        // InResponseTo), the relay state, and enough to rebuild the object. The outgoing samlRequest itself
        // -- the deflated AuthnRequest XML, by far the largest field -- is deliberately not stored: it was
        // already sent, the response side never reads it, and keeping it pushed the cookie past the ~4KB
        // limit, at which point the browser drops it silently and the correlation is lost anyway.
        Map<String, String> fields = new LinkedHashMap<>();
        fields.put("binding", request.getBinding().name());
        fields.put("id", request.getId());
        fields.put(RELAY_STATE, request.getRelayState());
        fields.put("uri", request.getAuthenticationRequestUri());
        fields.put("registrationId", request.getRelyingPartyRegistrationId());
        COOKIE.write(httpResponse, fields);
    }

    @Override
    public AbstractSaml2AuthenticationRequest loadAuthenticationRequest(HttpServletRequest httpRequest) {
        Optional<Map<String, String>> stored = COOKIE.read(httpRequest);
        if (stored.isEmpty()) {
            return null; // absent, malformed or tampered: simply no saved request
        }
        Map<String, String> fields = stored.get();
        RelyingPartyRegistration registration = registrations.findByRegistrationId(fields.get("registrationId"));
        if (registration == null) {
            return null;
        }
        // samlRequest is rebuilt as empty: it is the outgoing request, which the response side does not
        // read -- only the id it is correlated by matters here.
        boolean post = Saml2MessageBinding.POST.name().equals(fields.get("binding"));
        if (post) {
            return Saml2PostAuthenticationRequest.withRelyingPartyRegistration(registration)
                    .samlRequest(SAML_REQUEST_PLACEHOLDER)
                    .relayState(fields.get(RELAY_STATE))
                    .id(fields.get("id"))
                    .authenticationRequestUri(fields.get("uri"))
                    .build();
        }
        return Saml2RedirectAuthenticationRequest.withRelyingPartyRegistration(registration)
                .samlRequest(SAML_REQUEST_PLACEHOLDER)
                .relayState(fields.get(RELAY_STATE))
                .id(fields.get("id"))
                .authenticationRequestUri(fields.get("uri"))
                .build();
    }

    @Override
    public AbstractSaml2AuthenticationRequest removeAuthenticationRequest(
            HttpServletRequest httpRequest, HttpServletResponse httpResponse) {
        AbstractSaml2AuthenticationRequest request = loadAuthenticationRequest(httpRequest);
        if (request != null) {
            COOKIE.clear(httpResponse);
        }
        return request;
    }
}
