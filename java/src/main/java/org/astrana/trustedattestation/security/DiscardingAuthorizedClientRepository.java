package org.astrana.trustedattestation.security;

import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import org.springframework.security.core.Authentication;
import org.springframework.security.oauth2.client.OAuth2AuthorizedClient;
import org.springframework.security.oauth2.client.web.OAuth2AuthorizedClientRepository;

/**
 * Keeps no access or refresh token after sign-in.
 *
 * <p>Spring Security's login filter hands the tokens the IdP returned to an authorized-client repository
 * once the ID token has been validated, and its default repository parks them in memory, keyed by the
 * member, for as long as the process lives. Nothing here ever calls anything on the member's behalf, so the
 * tokens would sit there with no purpose other than being worth stealing. This repository drops them.
 *
 * <p>The ID token is unaffected: it lives on the OpenID Connect principal in the session, which is where
 * RP-initiated logout reads it from to tell the provider which session is ending. That is the one token
 * signing out needs, and it is the only one kept -- the same line the .NET implementation draws.
 */
public final class DiscardingAuthorizedClientRepository implements OAuth2AuthorizedClientRepository {

    @Override
    public <T extends OAuth2AuthorizedClient> T loadAuthorizedClient(
            String clientRegistrationId, Authentication principal, HttpServletRequest request) {
        return null;
    }

    @Override
    public void saveAuthorizedClient(
            OAuth2AuthorizedClient authorizedClient,
            Authentication principal,
            HttpServletRequest request,
            HttpServletResponse response) {
        // Deliberately nothing: the tokens are not retained.
    }

    @Override
    public void removeAuthorizedClient(
            String clientRegistrationId,
            Authentication principal,
            HttpServletRequest request,
            HttpServletResponse response) {
        // Nothing was kept, so there is nothing to remove.
    }
}
