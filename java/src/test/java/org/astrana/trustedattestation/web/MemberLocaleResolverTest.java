package org.astrana.trustedattestation.web;

import static org.assertj.core.api.Assertions.assertThat;

import jakarta.servlet.http.Cookie;
import java.time.Instant;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import org.astrana.trustedattestation.service.LocalizationSettings;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.Test;
import org.springframework.mock.web.MockHttpServletRequest;
import org.springframework.security.authentication.TestingAuthenticationToken;
import org.springframework.security.core.Authentication;
import org.springframework.security.core.authority.SimpleGrantedAuthority;
import org.springframework.security.core.context.SecurityContextHolder;
import org.springframework.security.oauth2.core.oidc.OidcIdToken;
import org.springframework.security.oauth2.core.oidc.user.DefaultOidcUser;
import org.springframework.security.saml2.provider.service.authentication.DefaultSaml2AuthenticatedPrincipal;
import org.springframework.security.saml2.provider.service.authentication.Saml2ResponseAssertionAccessor;

/**
 * The fixed order a request's language is decided in: the switcher's cookie, then the IAM's locale claim,
 * then the whole Accept-Language header, then the organisation's default.
 *
 * <p>Record 34 in docs/adr fixes the order so the same request renders the same page on all three stacks; what
 * these pin is each step yielding to the one before it, and each step ignoring a value this instance does
 * not offer rather than letting it through.
 */
class MemberLocaleResolverTest {

    private static final LocalizationSettings OFFERING_EN_FR_DE = new LocalizationSettings(
            List.of("en", "fr", "de"),
            List.of("en", "fr", "de", "es"),
            "en",
            Map.of("en", "English", "fr", "Français", "de", "Deutsch", "es", "Español"));

    private final MemberLocaleResolver resolver = new MemberLocaleResolver(OFFERING_EN_FR_DE);

    @AfterEach
    void clearSecurityContext() {
        SecurityContextHolder.clearContext();
    }

    private static MockHttpServletRequest request() {
        return new MockHttpServletRequest("GET", "/me");
    }

    private static MockHttpServletRequest withCookie(String value) {
        MockHttpServletRequest request = request();
        request.setCookies(new Cookie(MemberLocaleResolver.COOKIE_NAME, value));
        return request;
    }

    private static void signedInWithLocaleClaim(String locale) {
        OidcIdToken token = new OidcIdToken(
                "id-token", Instant.now(), Instant.now().plusSeconds(300), Map.of("sub", "alice", "locale", locale));
        DefaultOidcUser user = new DefaultOidcUser(List.of(new SimpleGrantedAuthority("ROLE_USER")), token, "sub");

        Authentication authentication =
                new TestingAuthenticationToken(user, null, List.of(new SimpleGrantedAuthority("ROLE_USER")));
        SecurityContextHolder.getContext().setAuthentication(authentication);
    }

    /** A SAML session of the shape the provider hands over today, carrying a {@code locale} attribute. */
    private static void signedInWithSamlLocaleAttribute(List<Object> values) {
        DefaultSaml2AuthenticatedPrincipal principal =
                new DefaultSaml2AuthenticatedPrincipal("alice", Map.of("locale", values));
        SecurityContextHolder.getContext()
                .setAuthentication(new TestingAuthenticationToken(
                        principal, null, List.of(new SimpleGrantedAuthority("ROLE_USER"))));
    }

    /**
     * A SAML session of the shape Spring Security 7 is moving towards. Nothing produces one yet, and the
     * two interfaces are unrelated, so this is the test that notices if the resolver stops reading the
     * attribute the day the provider changes shape.
     */
    private static void signedInViaAssertionAccessorWithLocale(String locale) {
        Saml2ResponseAssertionAccessor assertion = new Saml2ResponseAssertionAccessor() {
            @Override
            public String getNameId() {
                return "alice";
            }

            @Override
            public List<String> getSessionIndexes() {
                return List.of();
            }

            @Override
            public Map<String, List<Object>> getAttributes() {
                return Map.of("locale", List.of(locale));
            }

            @Override
            public String getResponseValue() {
                return "";
            }
        };
        SecurityContextHolder.getContext()
                .setAuthentication(new TestingAuthenticationToken(
                        assertion, null, List.of(new SimpleGrantedAuthority("ROLE_USER"))));
    }

    // ---------------------------------------------------------------------------------------------
    // The cookie
    // ---------------------------------------------------------------------------------------------

    @Test
    void theSwitchersCookieWinsOverTheClaimAndTheHeader() {
        // A member who picked a language has said what they want.
        signedInWithLocaleClaim("de");
        MockHttpServletRequest request = withCookie("fr");
        request.addHeader("Accept-Language", "en");

        assertThat(resolver.resolveLocale(request)).isEqualTo(Locale.FRENCH);
    }

    @Test
    void aCookieNamingALocaleNotOfferedIsIgnored() {
        // Shipped but not offered by this organisation ("es"), or simply unknown: both fall through to the
        // claim, exactly as a forged or stale cookie must.
        signedInWithLocaleClaim("de");

        assertThat(resolver.resolveLocale(withCookie("es"))).isEqualTo(Locale.GERMAN);
        assertThat(resolver.resolveLocale(withCookie("xx"))).isEqualTo(Locale.GERMAN);
        assertThat(resolver.resolveLocale(withCookie(""))).isEqualTo(Locale.GERMAN);
    }

    @Test
    void aRegionalCookieValueIsServedByItsLanguage() {
        assertThat(resolver.resolveLocale(withCookie("fr-CA"))).isEqualTo(Locale.FRENCH);
    }

    @Test
    void anUnrelatedCookieDoesNotSetTheLanguage() {
        MockHttpServletRequest request = request();
        request.setCookies(new Cookie("ata_session", "fr"));
        request.addHeader("Accept-Language", "de");

        assertThat(resolver.resolveLocale(request)).isEqualTo(Locale.GERMAN);
    }

    // ---------------------------------------------------------------------------------------------
    // The IAM claim
    // ---------------------------------------------------------------------------------------------

    @Test
    void anIamLocaleClaimWinsOverAcceptLanguage() {
        // The organisation knows which language it holds this member's record in.
        signedInWithLocaleClaim("fr");
        MockHttpServletRequest request = request();
        request.addHeader("Accept-Language", "de");

        assertThat(resolver.resolveLocale(request)).isEqualTo(Locale.FRENCH);
    }

    @Test
    void aRegionalClaimFallsBackToItsLanguage() {
        signedInWithLocaleClaim("fr-CA");

        assertThat(resolver.resolveLocale(request())).isEqualTo(Locale.FRENCH);
    }

    @Test
    void aClaimForALocaleNotOfferedDefersToAcceptLanguage() {
        signedInWithLocaleClaim("es");
        MockHttpServletRequest request = request();
        request.addHeader("Accept-Language", "de");

        assertThat(resolver.resolveLocale(request)).isEqualTo(Locale.GERMAN);
    }

    @Test
    void aBlankClaimDefersRatherThanPinningTheDefault() {
        // An IdP that sends the claim but leaves it empty has said nothing.
        signedInWithLocaleClaim("   ");
        MockHttpServletRequest request = request();
        request.addHeader("Accept-Language", "de");

        assertThat(resolver.resolveLocale(request)).isEqualTo(Locale.GERMAN);
    }

    @Test
    void aSamlLocaleAttributeIsHonouredLikeTheOidcClaim() {
        // .NET and PHP read the attribute of the same name, so a SAML organisation's record language wins
        // over Accept-Language here too.
        signedInWithSamlLocaleAttribute(List.of("fr"));
        MockHttpServletRequest request = request();
        request.addHeader("Accept-Language", "de");

        assertThat(resolver.resolveLocale(request)).isEqualTo(Locale.FRENCH);
    }

    @Test
    void aMultivaluedSamlLocaleAttributeIsTakenAtItsFirstValue() {
        // A language preference has one value. A second is noise, not a reason to ignore the first.
        signedInWithSamlLocaleAttribute(List.of("fr", "de"));

        assertThat(resolver.resolveLocale(request())).isEqualTo(Locale.FRENCH);
    }

    @Test
    void aSamlLocaleAttributeNotOfferedDefersToAcceptLanguage() {
        signedInWithSamlLocaleAttribute(List.of("es"));
        MockHttpServletRequest request = request();
        request.addHeader("Accept-Language", "de");

        assertThat(resolver.resolveLocale(request)).isEqualTo(Locale.GERMAN);
    }

    @Test
    void theReplacementSamlPrincipalShapeIsReadIdentically() {
        signedInViaAssertionAccessorWithLocale("fr");
        MockHttpServletRequest request = request();
        request.addHeader("Accept-Language", "de");

        assertThat(resolver.resolveLocale(request)).isEqualTo(Locale.FRENCH);
    }

    @Test
    void aPrincipalWithoutClaimsDefersToAcceptLanguage() {
        SecurityContextHolder.getContext().setAuthentication(new TestingAuthenticationToken("alice", null));
        MockHttpServletRequest request = request();
        request.addHeader("Accept-Language", "fr");

        assertThat(resolver.resolveLocale(request)).isEqualTo(Locale.FRENCH);
    }

    // ---------------------------------------------------------------------------------------------
    // Accept-Language, then the default
    // ---------------------------------------------------------------------------------------------

    @Test
    void theWholeAcceptLanguageListIsWalkedWithRegionFallback() {
        // Two unsupported languages ahead of a regional French: the whole q-ordered list is considered and
        // fr-CA is served by fr, not by the default.
        MockHttpServletRequest request = request();
        request.addHeader("Accept-Language", "pt-BR, es;q=0.9, fr-CA;q=0.8");

        assertThat(resolver.resolveLocale(request)).isEqualTo(Locale.FRENCH);
    }

    @Test
    void aChineseEntryIsMatchedByScriptNotByLanguagePrefix() {
        // Spring's own resolver would match zh-TW to zh-Hans, the first offered Chinese, by language prefix.
        // The unsupported entry ahead of it checks the whole header is still walked.
        List<String> offered = List.of("en", "zh-Hans", "zh-Hant");
        MemberLocaleResolver bothScripts =
                new MemberLocaleResolver(new LocalizationSettings(offered, offered, "en", Map.of("en", "English")));
        MockHttpServletRequest request = request();
        request.addHeader("Accept-Language", "zz, zh-TW;q=0.8");

        assertThat(bothScripts.resolveLocale(request)).isEqualTo(Locale.forLanguageTag("zh-Hant"));
    }

    @Test
    void aMalformedAcceptLanguageHeaderFallsToTheOrganisationsDefault() {
        MockHttpServletRequest request = request();
        request.addHeader("Accept-Language", "fr;q=plenty");

        assertThat(resolver.resolveLocale(request)).isEqualTo(Locale.ENGLISH);
    }

    @Test
    void oneMalformedEntryIsSkippedAndTheOthersStillCount() {
        // Locale.LanguageRange.parse refuses the whole header over one bad entry. The entries that parse
        // are still what the browser asked for, in their weight order.
        MockHttpServletRequest request = request();
        request.addHeader("Accept-Language", "fr;q=plenty, de;q=0.5, fr-CA;q=0.9");

        assertThat(resolver.resolveLocale(request)).isEqualTo(Locale.FRENCH);

        MockHttpServletRequest afterTheBadEntry = request();
        afterTheBadEntry.addHeader("Accept-Language", "x;;q=1, de");

        assertThat(resolver.resolveLocale(afterTheBadEntry)).isEqualTo(Locale.GERMAN);
    }

    @Test
    void nothingUsableFallsToTheOrganisationsDefault() {
        MockHttpServletRequest request = request();
        request.addHeader("Accept-Language", "cy, es");

        assertThat(resolver.resolveLocale(request)).isEqualTo(Locale.ENGLISH);
        assertThat(resolver.resolveLocale(request())).isEqualTo(Locale.ENGLISH);
    }

    @Test
    void theDefaultIsTheOrganisationsNotTheServers() {
        LocalizationSettings frenchByDefault = new LocalizationSettings(
                List.of("fr", "en"), List.of("en", "fr"), "fr", Map.of("en", "English", "fr", "Français"));

        assertThat(new MemberLocaleResolver(frenchByDefault).resolveLocale(request()))
                .isEqualTo(Locale.FRENCH);
    }
}
