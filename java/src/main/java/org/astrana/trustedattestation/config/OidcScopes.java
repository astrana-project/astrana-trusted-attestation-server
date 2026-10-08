package org.astrana.trustedattestation.config;

import java.util.Collection;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Set;

/**
 * The OpenID Connect scopes sign-in asks for. Spring uses a registration's {@code scope} list exactly as
 * written and asks for nothing at all when it is unset, so the identity system would not even treat the
 * request as OpenID Connect. The other two implementations always ask for {@code openid}, {@code profile}
 * and {@code email} and add any configured scope after them, and this does the same.
 */
final class OidcScopes {

    private static final List<String> DEFAULTS = List.of("openid", "profile", "email");

    private OidcScopes() {}

    /** The three default scopes, then each configured scope in its order, trimmed, with blanks and repeats dropped. */
    static Set<String> withDefaults(Collection<String> configured) {
        Set<String> scopes = new LinkedHashSet<>(DEFAULTS);
        if (configured != null) {
            configured.stream()
                    .map(String::trim)
                    .filter(scope -> !scope.isEmpty())
                    .forEach(scopes::add);
        }

        return scopes;
    }
}
