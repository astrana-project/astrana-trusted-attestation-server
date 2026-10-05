package org.astrana.trustedattestation.security;

import static org.assertj.core.api.Assertions.assertThat;

import java.time.Instant;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.Optional;
import org.astrana.trustedattestation.config.TrustedAttestationProperties;
import org.junit.jupiter.api.Test;
import org.springframework.security.authentication.TestingAuthenticationToken;
import org.springframework.security.core.Authentication;
import org.springframework.security.core.authority.SimpleGrantedAuthority;
import org.springframework.security.oauth2.core.oidc.OidcIdToken;
import org.springframework.security.oauth2.core.oidc.user.DefaultOidcUser;
import org.springframework.security.oauth2.core.user.DefaultOAuth2User;
import org.springframework.security.saml2.provider.service.authentication.DefaultSaml2AuthenticatedPrincipal;
import org.springframework.security.saml2.provider.service.authentication.Saml2ResponseAssertionAccessor;

/**
 * What the session is allowed to decide.
 *
 * <p>One question only: who is this? A relationship is deliberately never read from a claim. It exists
 * only because the organisation created it with grant_member_relationship, and anything read from a token
 * could be handed to a member by whoever controls the identity provider's claim mapping. Keeping grant out
 * of the API would count for little if a claim could still conjure one.
 */
class MemberIdentityResolverTest {

    private static MemberIdentityResolver resolver() {
        return new MemberIdentityResolver(new TrustedAttestationProperties());
    }

    private static Authentication oidc(Map<String, Object> claims) {
        OidcIdToken token =
                new OidcIdToken("id-token", Instant.now(), Instant.now().plusSeconds(300), claims);

        DefaultOidcUser user = new DefaultOidcUser(List.of(new SimpleGrantedAuthority("ROLE_USER")), token, "sub");

        TestingAuthenticationToken authentication =
                new TestingAuthenticationToken(user, null, List.of(new SimpleGrantedAuthority("ROLE_USER")));
        authentication.setAuthenticated(true);

        return authentication;
    }

    private static Map<String, Object> claims(String... keyValuePairs) {
        Map<String, Object> claims = new HashMap<>();
        for (int i = 0; i < keyValuePairs.length; i += 2) {
            claims.put(keyValuePairs[i], keyValuePairs[i + 1]);
        }

        return claims;
    }

    @Test
    void readsTheSubjectAndNameFromTheSession() {
        Optional<MemberIdentity> member = resolver().resolve(oidc(claims("sub", "abc-123", "name", "Alice Anderson")));

        assertThat(member).isPresent();
        assertThat(member.get().iamSubjectId()).isEqualTo("abc-123");
        assertThat(member.get().name()).isEqualTo("Alice Anderson");
    }

    @Test
    void noSubjectIsTreatedAsUnauthenticated() {
        // An OAuth2 principal with no sub at all -- the resolver must not invent one.
        DefaultOAuth2User user = new DefaultOAuth2User(
                List.of(new SimpleGrantedAuthority("ROLE_USER")), Map.of("name", "Nobody"), "name");

        TestingAuthenticationToken authentication = new TestingAuthenticationToken(user, null, "ROLE_USER");
        authentication.setAuthenticated(true);

        assertThat(resolver().resolve(authentication)).isEmpty();
    }

    @Test
    void aMemberHoldingNothingStillResolves() {
        // Authenticated but granted nothing is an ordinary member with an empty list, not a 403, because
        // that is what everyone looks like before their first grant. Refusing them would mean a member
        // could not even see the page that tells them there is nothing yet.
        Optional<MemberIdentity> member = resolver().resolve(oidc(claims("sub", "dave")));

        assertThat(member).isPresent();
        assertThat(member.get().iamSubjectId()).isEqualTo("dave");
    }

    @Test
    void aRelationshipClaimOnTheSessionIsIgnoredEntirely() {
        // Whoever controls the identity provider's claim mapping must not be able to hand a member a
        // relationship the organisation never granted, and the only way to be sure of that is for the
        // resolver to have nowhere to put one.
        Optional<MemberIdentity> member = resolver()
                .resolve(oidc(claims("sub", "abc", "relationship_type", "director", "relationship_subtype", "Fellow")));

        // The resolved member is exactly the subject and its name fallback: the relationship claims did
        // not become the subject, and did not leak into the name (which falls back to the subject here
        // rather than picking up "director"). A toString() check would pass whatever the resolver did,
        // since the record has only these two fields; asserting the fields themselves is the real test.
        assertThat(member).isPresent();
        assertThat(member.get().iamSubjectId()).isEqualTo("abc");
        assertThat(member.get().name()).isEqualTo("abc");
    }

    @Test
    void fallsBackToTheSubjectWhenTheIdpSendsNoName() {
        assertThat(resolver().resolve(oidc(claims("sub", "abc-123"))))
                .get()
                .extracting(MemberIdentity::name)
                .isEqualTo("abc-123");
    }

    @Test
    void anArrayValuedSubjectIsNotCoercedIntoASubject() {
        // A provider that sends sub as an array arrives here as a List. String.valueOf(List) is
        // "[a, b]", which would key the member by that literal; absent is the safe reading, and the one
        // the other two implementations already take.
        Map<String, Object> claims = new HashMap<>();
        claims.put("sub", List.of("a", "b"));

        assertThat(resolver().subject(oidc(claims))).isEmpty();
        assertThat(resolver().resolve(oidc(claims))).isEmpty();
    }

    @Test
    void anArrayValuedNameFallsBackToTheSubject() {
        Map<String, Object> claims = new HashMap<>();
        claims.put("sub", "abc");
        claims.put("name", List.of("Ann", "Bob"));

        assertThat(resolver().resolve(oidc(claims)))
                .get()
                .extracting(MemberIdentity::name)
                .isEqualTo("abc");
    }

    @Test
    void aBlankNameFallsBackToTheSubject() {
        // A present-but-empty name claim is not a name -- the page falls back to the subject rather than
        // showing the member a blank where their name should be.
        assertThat(resolver().resolve(oidc(claims("sub", "abc", "name", "   "))))
                .get()
                .extracting(MemberIdentity::name)
                .isEqualTo("abc");
    }

    // -----------------------------------------------------------------------------------------------
    // SAML. The same resolver, the same configured claim names, a different protocol underneath.
    // -----------------------------------------------------------------------------------------------

    /**
     * A principal of the shape Spring Security 7 is moving towards.
     *
     * <p>Written out by hand because nothing in this application produces one yet: the provider in use
     * still hands over a {@code DefaultSaml2AuthenticatedPrincipal}, which implements only the
     * deprecated interface. The two are unrelated types, so a resolver that handled just one of them
     * would keep compiling and start returning no subject the day the provider changed -- an
     * authenticated member silently treated as anonymous. This is the test that would notice.
     */
    private static Authentication samlViaAssertionAccessor(String nameId, Map<String, List<Object>> attributes) {
        Saml2ResponseAssertionAccessor assertion = new Saml2ResponseAssertionAccessor() {
            @Override
            public String getNameId() {
                return nameId;
            }

            @Override
            public List<String> getSessionIndexes() {
                return List.of();
            }

            @Override
            public Map<String, List<Object>> getAttributes() {
                return attributes;
            }

            @Override
            public String getResponseValue() {
                return "";
            }
        };

        TestingAuthenticationToken authentication =
                new TestingAuthenticationToken(assertion, null, List.of(new SimpleGrantedAuthority("ROLE_USER")));
        authentication.setAuthenticated(true);

        return authentication;
    }

    private static Authentication saml(String nameId, Map<String, List<Object>> attributes) {
        DefaultSaml2AuthenticatedPrincipal principal = new DefaultSaml2AuthenticatedPrincipal(nameId, attributes);

        TestingAuthenticationToken authentication =
                new TestingAuthenticationToken(principal, null, List.of(new SimpleGrantedAuthority("ROLE_USER")));
        authentication.setAuthenticated(true);

        return authentication;
    }

    @Test
    void theReplacementPrincipalShapeResolvesIdentically() {
        Optional<MemberIdentity> member =
                resolver().resolve(samlViaAssertionAccessor("carol-nameid", Map.of("name", List.of("Carol"))));

        assertThat(member).isPresent();
        assertThat(member.get().iamSubjectId()).isEqualTo("carol-nameid");
        assertThat(member.get().name()).isEqualTo("Carol");
    }

    @Test
    void theSubjectFallsBackToTheNameIdWhenNoAttributeCarriesIt() {
        // NameID is SAML's equivalent of "sub", and is where the subject normally lives. It also has to
        // be the same value the organisation granted against, or the member signs in successfully and
        // appears to hold nothing at all -- a failure with no error anywhere.
        assertThat(resolver().resolve(saml("persistent-name-id", Map.of())))
                .get()
                .extracting(MemberIdentity::iamSubjectId)
                .isEqualTo("persistent-name-id");
    }

    @Test
    void aSamlAttributeNamedAsTheSubjectClaimWinsOverTheNameId() {
        assertThat(resolver().resolve(saml("persistent-name-id", Map.of("sub", List.of("attribute-subject")))))
                .get()
                .extracting(MemberIdentity::iamSubjectId)
                .isEqualTo("attribute-subject");
    }

    @Test
    void bothSamlShapesAgreeOnTheSubject() {
        // The whole reason both are handled. If they ever disagreed, a deployment would silently key its
        // members by one value and look them up by another.
        assertThat(resolver().subject(saml("same-nameid", Map.of())))
                .isEqualTo(resolver().subject(samlViaAssertionAccessor("same-nameid", Map.of())));
    }

    @Test
    void subjectAloneIsReadableWithoutADisplayName() {
        // What DELETE and self-revoke rely on: neither needs to show the member anything, so neither
        // should fail because the IdP sent no name.
        assertThat(resolver().subject(saml("persistent-name-id", Map.of()))).contains("persistent-name-id");
    }

    @Test
    void thePrincipalAloneIsReadableBeforeItIsInASession() {
        // What sign-in checks at the callback, before any session exists: the same reading as subject(),
        // so a token the callback accepts is one the pages and the API will resolve afterwards.
        MemberIdentityResolver resolver = resolver();
        OidcIdToken token =
                new OidcIdToken("id-token", Instant.now(), Instant.now().plusSeconds(300), claims("sub", "abc-123"));
        DefaultOidcUser user = new DefaultOidcUser(List.of(new SimpleGrantedAuthority("ROLE_USER")), token, "sub");

        assertThat(resolver.subjectOf(user)).contains("abc-123");
        assertThat(resolver.subjectOf(new DefaultSaml2AuthenticatedPrincipal("name-id", Map.of())))
                .contains("name-id");
        assertThat(resolver.subjectOf(new DefaultSaml2AuthenticatedPrincipal("   ", Map.of())))
                .isEmpty();
        assertThat(resolver.subjectOf("a string principal")).isEmpty();
        assertThat(resolver.subjectOf(null)).isEmpty();
    }

    @Test
    void aBlankSubjectClaimIsNoSubject() {
        // Missing, empty and whitespace all count as absent: an organisation's identity system that sends
        // the claim with nothing in it has not identified anyone.
        assertThat(resolver().subject(oidc(claims("sub", "")))).isEmpty();
        assertThat(resolver().subject(oidc(claims("sub", "   ")))).isEmpty();
    }

    @Test
    void noSubjectMeansNoSubjectForTheLighterPathToo() {
        DefaultOAuth2User user = new DefaultOAuth2User(
                List.of(new SimpleGrantedAuthority("ROLE_USER")), Map.of("name", "Nobody"), "name");

        TestingAuthenticationToken authentication = new TestingAuthenticationToken(user, null, "ROLE_USER");
        authentication.setAuthenticated(true);

        assertThat(resolver().subject(authentication)).isEmpty();
    }
}
