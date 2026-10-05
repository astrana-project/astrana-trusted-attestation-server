package org.astrana.trustedattestation.web;

import jakarta.servlet.http.HttpServletRequest;
import java.time.Clock;
import java.time.Instant;
import java.util.List;
import java.util.Locale;
import java.util.Optional;
import org.astrana.trustedattestation.contract.Attribution;
import org.astrana.trustedattestation.contract.RelationshipTypeCatalog;
import org.astrana.trustedattestation.data.MemberRelationship;
import org.astrana.trustedattestation.manifest.ManifestDocument;
import org.astrana.trustedattestation.security.MemberIdentity;
import org.astrana.trustedattestation.security.MemberIdentityResolver;
import org.astrana.trustedattestation.security.PublicKeys;
import org.astrana.trustedattestation.service.LocalizationSettings;
import org.astrana.trustedattestation.service.RelationshipService;
import org.astrana.trustedattestation.service.UiStrings;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.GetMapping;

/**
 * The self-service page, for signed-in members only. The landing page and the licence page are the other
 * two pages, and both are public.
 *
 * <p>The signed-in member sees their own name, so they know they are signed in as themselves, and every
 * relationship they hold, each with its own key and its own controls. There is no administrator or staff
 * interface anywhere in Astrana Trusted Attestation. Standing is changed through the organisation's stored
 * procedures instead, so there is no extra sign-in path and no extra endpoints for an attacker to target.
 *
 * <p>Each relationship is listed separately rather than merged into one summary, because they really are
 * separate: independent keys, independent standing, independently removable. A page that showed a single
 * combined state would be describing something the data model does not have.
 */
@Controller
public class MeController {

    private final MemberIdentityResolver resolver;
    private final RelationshipService relationships;
    private final RelationshipTypeCatalog catalog;
    private final UiStrings strings;
    private final LocalizationSettings localization;
    private final Clock clock;

    private final ManifestDocument manifest;
    private final Attribution attribution;

    public MeController(
            MemberIdentityResolver resolver,
            RelationshipService relationships,
            RelationshipTypeCatalog catalog,
            UiStrings strings,
            LocalizationSettings localization,
            Clock clock,
            ManifestDocument manifest,
            Attribution attribution) {
        this.resolver = resolver;
        this.relationships = relationships;
        this.catalog = catalog;
        this.strings = strings;
        this.localization = localization;
        this.clock = clock;
        this.manifest = manifest;
        this.attribution = attribution;
    }

    /**
     * One relationship as the page needs it: already localized, with no work left for the template
     * beyond rendering.
     *
     * @param relationshipType the wire value, used to build this row's own action URLs
     * @param label the governed type's label in the member's language
     * @param subtype ungoverned free text, shown as given
     * @param status the wire status: unkeyed, active, revoked or expired
     * @param expiresAt the same string the API serialises, see {@link WireTimestamps}, or null
     */
    public record RelationshipView(
            String relationshipType, String label, String subtype, String status, String publicKey, String expiresAt) {}

    /**
     * Picks a locale-keyed manifest value, falling back the way decision record 34 in docs/adr says a consumer
     * should: the requested locale, then its language, then the manifest's own declared default.
     */
    private static String localized(java.util.Map<String, String> values, String locale, String defaultLocale) {
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

    @GetMapping("/me")
    public String me(Authentication authentication, Locale locale, HttpServletRequest request, Model model) {
        // Rendering in the member's language is a requirement, not optional polish. The locale arrives
        // already resolved in the fixed order, the switcher's cookie, then the identity system's locale
        // claim, then Accept-Language, then the organisation's default (see MemberLocaleResolver).
        String tag = locale == null ? localization.defaultLocale() : locale.toLanguageTag();
        String rendered = strings.resolve(tag);

        model.addAttribute("t", strings.all(tag));
        // See LandingController: the page's advertised language and direction follow the locale it is
        // rendered in, not the raw Accept-Language of the request.
        model.addAttribute("htmlLang", rendered);
        model.addAttribute("htmlDir", TextDirection.of(rendered));

        // The organisation's own name and logo, so the member can see whose page this is. Both come from
        // the manifest, the same content the organisation already maintains for discovery, rather than
        // from a separate branding setting.
        model.addAttribute("orgName", localized(manifest.name(), tag, manifest.defaultLocale()));
        model.addAttribute("orgLogo", manifest.logoData());
        model.addAttribute("orgLogoDark", manifest.logoDataDark());

        // Where a member who holds no relationship yet is pointed for help. The manifest's support URL if
        // set, otherwise its website, otherwise null, and the message then names the organisation as plain
        // text. Resolved for the member's locale, the same way the organisation's name is.
        String support = localized(manifest.supportUrl(), tag, manifest.defaultLocale());
        String website = localized(manifest.website(), tag, manifest.defaultLocale());
        String contactUrl = !support.isBlank() ? support : !website.isBlank() ? website : null;
        model.addAttribute("contactUrl", contactUrl);

        // The software's own attribution, which the server has no setting to remove.
        model.addAttribute("attribution", attribution);

        // The language switcher: which locales it offers, and where its POST should bring the member back to.
        model.addAttribute("localization", localization);
        model.addAttribute("returnTo", LanguageSwitcher.returnTo(request));

        Optional<MemberIdentity> member = resolver.resolve(authentication);

        // An empty list is an ordinary state, not an error. It is what a member looks like before the
        // organisation has granted them anything, and the page has to render for them too.
        model.addAttribute("memberName", member.map(MemberIdentity::name).orElse(""));
        model.addAttribute(
                "relationships",
                member.map(resolved -> held(resolved.iamSubjectId(), tag)).orElse(List.of()));

        return "me";
    }

    private List<RelationshipView> held(String iamSubjectId, String tag) {
        Instant now = Instant.now(clock);

        return relationships.findAllHeldBy(iamSubjectId).stream()
                .map(row -> view(row, tag, now))
                .toList();
    }

    private RelationshipView view(MemberRelationship row, String tag, Instant now) {
        return new RelationshipView(
                row.getRelationshipType(),
                catalog.label(row.getRelationshipType(), tag),
                row.getRelationshipSubtype(),
                row.statusAt(now).wireValue(),
                row.getPublicKey() == null ? null : PublicKeys.toBase64(row.getPublicKey()),
                WireTimestamps.format(row.getExpiresAt()));
    }
}
