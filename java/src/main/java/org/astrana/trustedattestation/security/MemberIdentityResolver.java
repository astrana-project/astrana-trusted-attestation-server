package org.astrana.trustedattestation.security;

import java.util.Optional;
import org.astrana.trustedattestation.config.TrustedAttestationProperties;
import org.springframework.security.core.Authentication;
import org.springframework.security.oauth2.core.oidc.user.OidcUser;
import org.springframework.security.oauth2.core.user.OAuth2User;
import org.springframework.security.saml2.provider.service.authentication.Saml2AuthenticatedPrincipal;
import org.springframework.security.saml2.provider.service.authentication.Saml2ResponseAssertionAccessor;
import org.springframework.stereotype.Component;

/**
 * Resolves the authenticated session into a member, and nothing more than that.
 *
 * <p>It deliberately does not resolve a relationship type from a claim. A relationship exists only
 * because the organisation created it with {@code grant_member_relationship}, and the database is the
 * only place that records one. Reading a relationship from a token would let whoever controls the
 * identity system's claim mapping hand a member a relationship the organisation never granted. That is
 * the hole that keeping grant out of the API closes, reopened one layer down, where it would be much
 * harder to notice.
 *
 * <p>So the session answers exactly one question here: who is this? What they hold is a database lookup.
 */
@Component
public class MemberIdentityResolver {

    private final TrustedAttestationProperties.Iam iam;

    public MemberIdentityResolver(TrustedAttestationProperties properties) {
        this.iam = properties.getIam();
    }

    /**
     * The authenticated member, or empty when the session carries no usable subject.
     *
     * <p>There is no second failure mode. "Authenticated but holding nothing" is not a failure
     * at all: it is an ordinary member before their first grant, and they get a 200 with an empty list.
     */
    public Optional<MemberIdentity> resolve(Authentication authentication) {
        return subject(authentication).map(subject -> {
            String name = Optional.ofNullable(claim(authentication, iam.getNameClaim()))
                    .filter(value -> !value.isBlank())
                    .orElse(subject);

            return new MemberIdentity(subject, name);
        });
    }

    /**
     * The member's subject identifier.
     *
     * <p>Under SAML this falls back to the assertion's NameID when no attribute of the configured name is
     * present, which is the usual case: NameID is SAML's equivalent of OIDC's {@code sub}, and the
     * subject normally lives there rather than in an attribute of its own.
     *
     * <p>This is the value the organisation granted against, so getting it wrong does not produce an
     * error -- it produces a member who signs in successfully and appears to hold nothing at all.
     */
    public Optional<String> subject(Authentication authentication) {
        if (authentication == null || !authentication.isAuthenticated()) {
            return Optional.empty();
        }

        return subjectOf(authentication.getPrincipal());
    }

    /**
     * The subject a principal carries, before it is in a session: what sign-in checks so a token or
     * assertion with no usable subject is refused at the callback rather than signed in as nobody. The
     * same reading as {@link #subject}, missing, empty and whitespace all count as absent.
     */
    public Optional<String> subjectOf(Object principal) {
        return Optional.ofNullable(subjectClaim(principal)).filter(value -> !value.isBlank());
    }

    private String subjectClaim(Object principal) {
        String claimed = claim(principal, iam.getSubjectClaim());
        if (claimed != null && !claimed.isBlank()) {
            return claimed;
        }

        // Both SAML principal shapes, newest first.
        //
        // Spring Security 7 deprecated Saml2AuthenticatedPrincipal in favour of
        // Saml2ResponseAssertionAccessor, but the two are unrelated types: the principal this
        // application actually receives today implements only the deprecated one. Replacing the type
        // outright would compile cleanly and then fail at runtime by matching neither branch, which
        // resolves to no subject -- an authenticated member silently treated as anonymous.
        //
        // So both are handled until the deprecated type stops being produced, and the replacement is
        // matched first so it wins the day a principal implements both.
        return switch (principal) {
            case Saml2ResponseAssertionAccessor assertion -> assertion.getNameId();
            case Saml2AuthenticatedPrincipal saml -> saml.getName();
            case null, default -> null;
        };
    }

    /**
     * Reads a claim by name, whichever protocol the organisation's IAM speaks.
     *
     * <p>This is the seam that makes OIDC and SAML interchangeable to everything downstream. Claims are
     * named in configuration rather than assumed, so a SAML deployment names SAML attributes and nothing
     * else in the application has to know which protocol produced them.
     *
     * <p>Returns only what the assertion or token actually carries. An absent claim is absent.
     */
    private static String claim(Authentication authentication, String name) {
        if (authentication == null || !authentication.isAuthenticated()) {
            return null;
        }

        return claim(authentication.getPrincipal(), name);
    }

    private static String claim(Object principal, String name) {
        Object value = switch (principal) {
            case OidcUser user -> user.getClaims().get(name);
            case OAuth2User user -> user.getAttributes().get(name);

            // See subjectClaim: the replacement interface first, the deprecated one behind it, because
            // the principal in use today implements only the latter. The whole attribute is read, not
            // getFirstAttribute: a multivalued attribute has to reach the single-scalar check below as the
            // list it is, so it reads as absent. getFirstAttribute would silently unwrap it to its first
            // element -- keying the member by one arbitrary value of a multivalued subject where .NET (and
            // the OIDC branch above) treat it as absent and fall back to the NameID.
            case Saml2ResponseAssertionAccessor assertion -> singleOrNull(assertion.getAttribute(name));
            case Saml2AuthenticatedPrincipal saml -> singleOrNull(saml.getAttribute(name));
            case null, default -> null;
        };

        // A usable claim is a single scalar. An array or object -- a shape a provider might send for sub
        // or name -- has no meaningful string form here: String.valueOf(List.of("a", "b")) is "[a, b]",
        // which would key the member by that literal. Treating it as absent is the safe reading, and the
        // one the other two implementations already take (PHP's is_scalar, .NET's single-value check).
        if (value == null
                || value instanceof java.util.Collection<?>
                || value instanceof java.util.Map<?, ?>
                || value.getClass().isArray()) {
            return null;
        }

        return String.valueOf(value);
    }

    /**
     * The one value of a single-valued attribute, or null for an absent or multivalued one. A SAML
     * attribute is always a list; only a list of exactly one has an unambiguous value, and anything else
     * (none, or several) reads as absent -- the same reading the {@link java.util.Collection} guard in
     * {@link #claim} gives a multivalued OIDC claim.
     */
    private static Object singleOrNull(java.util.List<Object> values) {
        return values != null && values.size() == 1 ? values.get(0) : null;
    }
}
