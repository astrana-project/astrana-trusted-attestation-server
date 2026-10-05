package org.astrana.trustedattestation.web;

import jakarta.servlet.http.HttpServletRequest;
import java.util.Locale;
import java.util.Map;
import org.astrana.trustedattestation.contract.Attribution;
import org.astrana.trustedattestation.contract.RelationshipTypeCatalog;
import org.astrana.trustedattestation.manifest.ManifestDocument;
import org.astrana.trustedattestation.service.LocalizationSettings;
import org.astrana.trustedattestation.service.UiStrings;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.GetMapping;

/**
 * The public landing page: what this instance is, whose it is, and where to sign in.
 *
 * <p>Deliberately unauthenticated and deliberately indexable -- this is the page the manifest's
 * {@code enrollment_url} points at, so it is the first thing a prospective member sees, before they
 * have any session to show. It makes no API calls and reveals nothing member-specific: everything on it
 * is already public through the manifest.
 *
 * <p>Also where a member lands after signing out, with a confirmation -- so "sign out" visibly worked,
 * rather than dumping them back onto a login redirect that signs them straight back in.
 */
@Controller
public class LandingController {

    private final UiStrings strings;
    private final LocalizationSettings localization;
    private final ManifestDocument manifest;
    private final Attribution attribution;

    public LandingController(
            UiStrings strings, LocalizationSettings localization, ManifestDocument manifest, Attribution attribution) {
        this.strings = strings;
        this.localization = localization;
        this.manifest = manifest;
        this.attribution = attribution;
    }

    @GetMapping("/")
    public String landing(Locale locale, HttpServletRequest request, Model model) {
        return render(locale, request, false, model);
    }

    /**
     * Where the IdP sends the member after logout. A dedicated path rather than a query marker on /,
     * because the IdP matches its registered post-logout URIs exactly and one clean path is the only
     * registration an operator can be expected to make.
     */
    @GetMapping("/signed-out")
    public String signedOut(Locale locale, HttpServletRequest request, Model model) {
        return render(locale, request, true, model);
    }

    private String render(Locale locale, HttpServletRequest request, boolean signedOut, Model model) {
        // The locale arrives already resolved in the fixed order -- the switcher's cookie, the IAM claim,
        // Accept-Language, the organisation's default -- see MemberLocaleResolver.
        String tag = locale == null ? localization.defaultLocale() : locale.toLanguageTag();
        String rendered = strings.resolve(tag);

        model.addAttribute("t", strings.all(tag));
        // lang and dir describe the language the page is actually in, which is the resolved locale, not
        // whatever the browser asked for: a request for an untranslated language is served in English and
        // must say so.
        model.addAttribute("htmlLang", rendered);
        model.addAttribute("htmlDir", TextDirection.of(rendered));
        model.addAttribute("orgName", localized(manifest.name(), tag, manifest.defaultLocale()));
        model.addAttribute("orgLogo", manifest.logoData());
        model.addAttribute("orgLogoDark", manifest.logoDataDark());
        model.addAttribute("attribution", attribution);

        // The language switcher: which locales it offers, and where its POST should bring the member back to.
        model.addAttribute("localization", localization);
        model.addAttribute("returnTo", LanguageSwitcher.returnTo(request));

        // A marker, not data: its presence is the confirmation.
        model.addAttribute("signedOut", signedOut);

        return "landing";
    }

    private static String localized(Map<String, String> values, String locale, String defaultLocale) {
        if (values == null) {
            return "";
        }

        for (String candidate : RelationshipTypeCatalog.localeFallbacks(locale)) {
            String value = values.get(candidate);
            if (value != null) {
                return value;
            }
        }

        return values.getOrDefault(defaultLocale, "");
    }
}
