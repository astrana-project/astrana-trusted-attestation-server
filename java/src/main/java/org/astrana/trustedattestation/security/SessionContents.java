package org.astrana.trustedattestation.security;

import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.stream.Collectors;
import java.util.stream.Stream;
import org.astrana.trustedattestation.config.TrustedAttestationProperties;
import org.springframework.security.core.GrantedAuthority;
import org.springframework.security.oauth2.client.oidc.userinfo.OidcUserRequest;
import org.springframework.security.oauth2.client.oidc.userinfo.OidcUserService;
import org.springframework.security.oauth2.client.userinfo.OAuth2UserService;
import org.springframework.security.oauth2.core.oidc.IdTokenClaimNames;
import org.springframework.security.oauth2.core.oidc.OidcIdToken;
import org.springframework.security.oauth2.core.oidc.user.DefaultOidcUser;
import org.springframework.security.oauth2.core.oidc.user.OidcUser;
import org.springframework.security.oauth2.core.oidc.user.OidcUserAuthority;
import org.springframework.security.oauth2.core.user.OAuth2UserAuthority;
import org.springframework.security.saml2.provider.service.authentication.DefaultSaml2AuthenticatedPrincipal;
import org.springframework.security.saml2.provider.service.authentication.Saml2AssertionAuthentication;
import org.springframework.security.saml2.provider.service.authentication.Saml2Authentication;
import org.springframework.security.saml2.provider.service.authentication.Saml2ResponseAssertion;
import org.springframework.security.saml2.provider.service.authentication.Saml2ResponseAssertionAccessor;
import org.springframework.util.StringUtils;

/**
 * What a session keeps about the member once they have signed in, and nothing more: the subject, the
 * display name, the locale, and what sign-out needs. The other two implementations keep the same.
 *
 * <p>Spring Security would otherwise hold every claim of the ID token and the UserInfo response under
 * OpenID Connect, and the whole SAML response with every attribute under SAML, for as long as the session
 * lives. None of it is written anywhere (decision record 10 in docs/adr), but a session that holds only what the
 * pages use has nothing else to lose.
 *
 * <p>Sign-out needs the raw ID token under OpenID Connect, sent as {@code id_token_hint}, and under SAML
 * the NameID, the session indexes and the relying party registration, which single logout puts in its
 * LogoutRequest.
 */
public final class SessionContents {

    /** The claim, or SAML attribute, the locale resolver reads. */
    public static final String LOCALE = "locale";

    private SessionContents() {}

    /** The claims, or SAML attributes, the session keeps: the configured subject and name, and the locale. */
    public static Set<String> keptClaims(TrustedAttestationProperties.Iam iam) {
        return Stream.of(iam.getSubjectClaim(), iam.getNameClaim(), LOCALE)
                .filter(StringUtils::hasText)
                .collect(Collectors.toUnmodifiableSet());
    }

    /**
     * Spring's own OpenID Connect user service, which fetches UserInfo and checks its subject against the ID
     * token's, with its result cut down to the kept claims after the UserInfo claims are merged in.
     */
    public static OAuth2UserService<OidcUserRequest, OidcUser> oidcUserService(Set<String> kept) {
        return oidcUserService(new OidcUserService(), kept);
    }

    static OAuth2UserService<OidcUserRequest, OidcUser> oidcUserService(
            OAuth2UserService<OidcUserRequest, OidcUser> delegate, Set<String> kept) {
        return request -> oidcUser(delegate.loadUser(request), kept);
    }

    /**
     * A user carrying only the kept claims, and an ID token that keeps its raw value for {@code
     * id_token_hint} but only those claims, with no UserInfo. {@code sub} stays as well, because the ID
     * token cannot be built without it, and it is the principal name Spring reports, whatever
     * {@code user-name-attribute} the registration names.
     */
    static OidcUser oidcUser(OidcUser full, Set<String> kept) {
        Set<String> keep =
                Stream.concat(kept.stream(), Stream.of(IdTokenClaimNames.SUB)).collect(Collectors.toUnmodifiableSet());
        Map<String, Object> claims = new LinkedHashMap<>();
        full.getClaims().forEach((name, value) -> {
            if (keep.contains(name)) {
                claims.put(name, value);
            }
        });

        OidcIdToken original = full.getIdToken();
        OidcIdToken idToken =
                new OidcIdToken(original.getTokenValue(), original.getIssuedAt(), original.getExpiresAt(), claims);

        List<GrantedAuthority> authorities = new ArrayList<>();
        authorities.add(new OidcUserAuthority(idToken, null, IdTokenClaimNames.SUB));
        full.getAuthorities().stream()
                .filter(authority -> !(authority instanceof OAuth2UserAuthority))
                .forEach(authorities::add);

        return new DefaultOidcUser(authorities, idToken, IdTokenClaimNames.SUB);
    }

    /**
     * A SAML authentication carrying only the kept attributes, the NameID, the session indexes and the
     * relying party registration, and not the SAML response itself.
     */
    public static Saml2Authentication samlAuthentication(Saml2Authentication full, Set<String> kept) {
        if (!(full instanceof Saml2AssertionAuthentication signedIn)) {
            throw new IllegalStateException("Expected a SAML assertion authentication, was " + full.getClass());
        }

        Saml2ResponseAssertionAccessor assertion = signedIn.getCredentials();
        Map<String, List<Object>> attributes = new LinkedHashMap<>();
        assertion.getAttributes().forEach((name, values) -> {
            if (kept.contains(name)) {
                attributes.put(name, Collections.unmodifiableList(new ArrayList<>(values)));
            }
        });
        List<String> sessionIndexes = List.copyOf(assertion.getSessionIndexes());

        Saml2ResponseAssertionAccessor trimmed = Saml2ResponseAssertion.withResponseValue("")
                .nameId(assertion.getNameId())
                .sessionIndexes(sessionIndexes)
                .attributes(Map.copyOf(attributes))
                .build();

        DefaultSaml2AuthenticatedPrincipal principal =
                new DefaultSaml2AuthenticatedPrincipal(full.getName(), Map.copyOf(attributes), sessionIndexes);
        principal.setRelyingPartyRegistrationId(signedIn.getRelyingPartyRegistrationId());

        return new Saml2AssertionAuthentication(
                principal, trimmed, full.getAuthorities(), signedIn.getRelyingPartyRegistrationId());
    }
}
