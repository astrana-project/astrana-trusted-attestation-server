package org.astrana.trustedattestation.config;

import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.util.LinkedHashMap;
import java.util.Map;
import java.util.Optional;
import org.springframework.security.saml2.provider.service.authentication.logout.Saml2LogoutRequest;
import org.springframework.security.saml2.provider.service.registration.RelyingPartyRegistration;
import org.springframework.security.saml2.provider.service.registration.RelyingPartyRegistrationRepository;
import org.springframework.security.saml2.provider.service.registration.Saml2MessageBinding;
import org.springframework.security.saml2.provider.service.web.authentication.logout.Saml2LogoutRequestRepository;

/**
 * Stores the outgoing SAML LogoutRequest in a dedicated cookie instead of the HTTP session, so the
 * LogoutResponse that answers it can be correlated when it comes back.
 *
 * <p>Spring's default keeps the request in the session. Sign-out has just invalidated that session, so the
 * request would sit in a fresh one reached through the {@code SameSite=Lax} session cookie, which a browser
 * leaves off the cross-site POST an identity provider uses to deliver its LogoutResponse. The response would
 * then match nothing and the member would see an error instead of the signed-out page. This is the logout
 * counterpart of {@link CookieSaml2AuthenticationRequestRepository}, and it creates no session at all.
 *
 * <p>The response is matched by its {@code RelayState}, as Spring's own session repository matches it, and
 * then validated against the stored request id and the identity provider's signature.
 */
final class CookieSaml2LogoutRequestRepository implements Saml2LogoutRequestRepository {

    private static final SamlCorrelationCookie COOKIE = new SamlCorrelationCookie("ata_saml_logout");
    // The rebuilt request needs a non-blank samlRequest, but the response side reads only the id and the
    // relay state. A placeholder keeps the object valid without carrying the outgoing request in the cookie.
    private static final String SAML_REQUEST_PLACEHOLDER = "saved";
    private static final String RELAY_STATE = "relayState";

    private final RelyingPartyRegistrationRepository registrations;

    CookieSaml2LogoutRequestRepository(RelyingPartyRegistrationRepository registrations) {
        this.registrations = registrations;
    }

    @Override
    public void saveLogoutRequest(
            Saml2LogoutRequest logoutRequest, HttpServletRequest request, HttpServletResponse response) {
        if (logoutRequest == null) {
            COOKIE.clear(response);
            return;
        }
        Map<String, String> fields = new LinkedHashMap<>();
        fields.put("binding", logoutRequest.getBinding().name());
        fields.put("id", logoutRequest.getId());
        fields.put(RELAY_STATE, logoutRequest.getRelayState());
        fields.put("location", logoutRequest.getLocation());
        fields.put("registrationId", logoutRequest.getRelyingPartyRegistrationId());
        COOKIE.write(response, fields);
    }

    @Override
    public Saml2LogoutRequest loadLogoutRequest(HttpServletRequest request) {
        Optional<Map<String, String>> stored = COOKIE.read(request)
                .filter(saved -> relayStateMatches(request.getParameter("RelayState"), saved.get(RELAY_STATE)));
        if (stored.isEmpty()) {
            return null;
        }
        Map<String, String> fields = stored.get();
        RelyingPartyRegistration registration = registrations.findByRegistrationId(fields.get("registrationId"));
        if (registration == null) {
            return null;
        }
        Saml2MessageBinding binding = Saml2MessageBinding.POST.name().equals(fields.get("binding"))
                ? Saml2MessageBinding.POST
                : Saml2MessageBinding.REDIRECT;
        return Saml2LogoutRequest.withRelyingPartyRegistration(registration)
                .samlRequest(SAML_REQUEST_PLACEHOLDER)
                .binding(binding)
                .location(fields.get("location"))
                .relayState(fields.get(RELAY_STATE))
                .id(fields.get("id"))
                .build();
    }

    @Override
    public Saml2LogoutRequest removeLogoutRequest(HttpServletRequest request, HttpServletResponse response) {
        Saml2LogoutRequest logoutRequest = loadLogoutRequest(request);
        if (logoutRequest != null) {
            COOKIE.clear(response);
        }
        return logoutRequest;
    }

    private static boolean relayStateMatches(String received, String stored) {
        if (received == null || stored == null) {
            return false;
        }
        return MessageDigest.isEqual(
                received.getBytes(StandardCharsets.UTF_8), stored.getBytes(StandardCharsets.UTF_8));
    }
}
