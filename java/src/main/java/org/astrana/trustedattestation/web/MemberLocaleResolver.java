package org.astrana.trustedattestation.web;

import jakarta.servlet.http.Cookie;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import java.util.ArrayList;
import java.util.Comparator;
import java.util.List;
import java.util.Locale;
import java.util.Optional;
import org.astrana.trustedattestation.service.LocalizationSettings;
import org.springframework.security.core.Authentication;
import org.springframework.security.core.context.SecurityContextHolder;
import org.springframework.security.oauth2.core.oidc.user.OidcUser;
import org.springframework.security.saml2.provider.service.authentication.Saml2AuthenticatedPrincipal;
import org.springframework.security.saml2.provider.service.authentication.Saml2ResponseAssertionAccessor;
import org.springframework.web.servlet.LocaleResolver;

/**
 * How the member's language is chosen from the request, in the fixed order decision record 34 in docs/adr sets and
 * the other two implementations follow.
 *
 * <p>First, a locale the member chose with the page's own language switcher, held in the {@code ata_locale}
 * cookie. A member who picked a language has said what they want, so it wins over everything else. The value
 * was validated against the offered set when the switcher stored it, and is validated again here, so an
 * unknown or no-longer-offered value is ignored and resolution falls through.
 *
 * <p>Then an IAM-provided {@code locale} claim, or the SAML attribute of the same name: the organisation
 * knows which language it holds this member's record in, where Accept-Language only says what the browser
 * was installed with.
 *
 * <p>Then the whole Accept-Language header, walked in the browser's q order, taking the first entry this
 * instance offers. Each entry is matched with the same rule as the cookie and the claim, {@link
 * LocalizationSettings#offered}, rather than Spring's own resolver: that resolver reads only the single
 * highest-priority entry, so a browser sending {@code pt-BR, fr;q=0.8} to an instance with French but not
 * Portuguese would be served the default, and it matches by language prefix, so {@code zh-TW} would be served
 * Simplified Chinese when Traditional is offered.
 *
 * <p>Then the organisation's default locale -- not the server's own JVM locale, which would make the served
 * language depend on where the app happens to run.
 */
public class MemberLocaleResolver implements LocaleResolver {

    /** The switcher's cookie. Carries a locale tag and nothing else, and is set only by {@code /set-language}. */
    public static final String COOKIE_NAME = "ata_locale";

    /** The OpenID Connect claim, and the SAML attribute of the same name, that carries the member's language. */
    private static final String LOCALE_CLAIM = "locale";

    private final LocalizationSettings localization;

    public MemberLocaleResolver(LocalizationSettings localization) {
        this.localization = localization;
    }

    @Override
    public Locale resolveLocale(HttpServletRequest request) {
        return chosen(request)
                .or(() -> claimed(SecurityContextHolder.getContext().getAuthentication()))
                .or(() -> accepted(request))
                .map(Locale::forLanguageTag)
                .orElseGet(() -> Locale.forLanguageTag(localization.defaultLocale()));
    }

    /** The language is changed through {@code /set-language}, which writes the cookie, never through here. */
    @Override
    public void setLocale(HttpServletRequest request, HttpServletResponse response, Locale locale) {
        throw new UnsupportedOperationException("The member's language is set by the " + COOKIE_NAME + " cookie");
    }

    private Optional<String> chosen(HttpServletRequest request) {
        Cookie[] cookies = request.getCookies();
        if (cookies == null) {
            return Optional.empty();
        }

        for (Cookie cookie : cookies) {
            if (COOKIE_NAME.equals(cookie.getName())) {
                return localization.offered(cookie.getValue());
            }
        }

        return Optional.empty();
    }

    /**
     * The IAM's locale claim, when the session carries one this instance can serve. An OpenID Connect
     * principal exposes it as the {@code locale} claim, a SAML principal as the attribute of the same name,
     * which is how the other two implementations read it too.
     */
    private Optional<String> claimed(Authentication authentication) {
        if (authentication == null) {
            return Optional.empty();
        }

        // Both SAML principal shapes, newest first -- see MemberIdentityResolver for why the deprecated
        // Saml2AuthenticatedPrincipal is still matched. The attribute's first value is read: a language
        // preference has one value, so a multivalued attribute is taken at its first rather than ignored.
        Object locale = switch (authentication.getPrincipal()) {
            case OidcUser user -> user.getClaims().get(LOCALE_CLAIM);
            case Saml2ResponseAssertionAccessor assertion -> assertion.getFirstAttribute(LOCALE_CLAIM);
            case Saml2AuthenticatedPrincipal principal -> principal.getFirstAttribute(LOCALE_CLAIM);
            case null, default -> null;
        };

        return locale == null ? Optional.empty() : localization.offered(String.valueOf(locale));
    }

    /**
     * The first Accept-Language entry, in the browser's q order, that this instance offers. The header is
     * read raw rather than through {@code request.getLocales()}, which answers a request with no header with
     * the container's own JVM locale: that must not stand in for the member's, the organisation's default
     * must.
     *
     * <p>Parsed entry by entry, because {@link Locale.LanguageRange#parse} refuses the whole header over one
     * malformed entry. A browser, or the proxy in front of it, that gets one entry wrong has still said
     * something usable in the others, so only the malformed entry is skipped. The entries that parse are
     * then put in weight order, highest first and header order among equals, which is the order the whole
     * header would have been given.
     */
    private Optional<String> accepted(HttpServletRequest request) {
        String header = request.getHeader("Accept-Language");
        if (header == null || header.isBlank()) {
            return Optional.empty();
        }

        List<Locale.LanguageRange> ranges = new ArrayList<>();
        for (String entry : header.split(",")) {
            if (entry.isBlank()) {
                continue;
            }

            try {
                ranges.addAll(Locale.LanguageRange.parse(entry));
            } catch (IllegalArgumentException _) {
                // This entry says nothing usable. The others may.
            }
        }
        ranges.sort(Comparator.comparingDouble(Locale.LanguageRange::getWeight).reversed());

        // A weight of zero is the browser saying "not this one", so it is skipped rather than served.
        return ranges.stream()
                .filter(range -> range.getWeight() > 0)
                .map(range -> localization.offered(range.getRange()))
                .flatMap(Optional::stream)
                .findFirst();
    }
}
