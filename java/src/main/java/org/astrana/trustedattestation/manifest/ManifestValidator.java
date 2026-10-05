package org.astrana.trustedattestation.manifest;

import java.net.URI;
import java.net.URISyntaxException;
import java.util.ArrayList;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.regex.Pattern;
import org.astrana.trustedattestation.contract.RelationshipTypeCatalog;

/**
 * Validates the configured manifest at startup, so a broken one is found at boot rather than by a
 * verifying Astrana instance whose lookup fails. Same reasoning as refusing to start without TLS.
 *
 * <p>Hand-written rather than driven by {@code shared/contract/manifest.schema.json}, because pulling in a
 * JSON Schema engine would work against the "no exotic dependency" bar the rest of this design holds to,
 * for a document with thirteen fields. The schema file remains the canonical shape, and these rules check
 * all of it.
 */
public final class ManifestValidator {

    /** The longest logo, in characters, the contract schema allows for either logo field. */
    static final int MAX_LOGO_LENGTH = 65_536;

    // Unbounded subtag count, exactly as the contract schema and the other two implementations have it
    // (^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$). A bound here rejected valid-per-schema tags they accept.
    // The subtag group is possessive and non-capturing ((?:...)*+) rather than a plain greedy (...)*,
    // because Java's regex engine recurses through a greedy repeated group and overflows the stack on a
    // long enough input. The possessive form matches iteratively, so a pathological input is refused
    // cleanly instead of crashing startup validation with a StackOverflowError.
    private static final Pattern LOCALE_TAG = Pattern.compile("^[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8})*+$");
    private static final Pattern JURISDICTION_CODE = Pattern.compile("^[A-Z]{2}(-[A-Z0-9]{1,3})?$");

    private ManifestValidator() {}

    public static List<String> validate(ManifestDocument manifest, RelationshipTypeCatalog catalog) {
        List<String> errors = new ArrayList<>();

        validateVersion(errors, manifest);
        validateDefaultLocale(errors, manifest);

        validateLocalized(errors, "name", manifest.name(), manifest.defaultLocale(), true, false);
        validateLocalized(errors, "description", manifest.description(), manifest.defaultLocale(), false, false);
        validateLocalized(errors, "website", manifest.website(), manifest.defaultLocale(), false, true);
        validateLocalized(errors, "support_url", manifest.supportUrl(), manifest.defaultLocale(), false, true);
        validateLocalized(
                errors, "privacy_notice_url", manifest.privacyNoticeUrl(), manifest.defaultLocale(), false, true);

        validateRelationshipTypes(errors, manifest.relationshipTypes(), catalog);
        validateLogo(errors, "logo_data", manifest.logoData());
        validateLogo(errors, "logo_data_dark", manifest.logoDataDark());
        validateDarkLogoHasALightOne(errors, manifest.logoData(), manifest.logoDataDark());

        validateHttpsUrl(errors, "enrollment_url", manifest.enrollmentUrl());
        validateHttpsUrl(errors, "attestation_url", manifest.attestationUrl());

        validateJurisdictions(errors, manifest.jurisdictions());

        return errors;
    }

    private static void validateVersion(List<String> errors, ManifestDocument manifest) {
        if (manifest.manifestVersion() != 1) {
            errors.add("manifest_version must be 1, was " + manifest.manifestVersion() + ".");
        }
    }

    private static void validateDefaultLocale(List<String> errors, ManifestDocument manifest) {
        if (manifest.defaultLocale() == null
                || !LOCALE_TAG.matcher(manifest.defaultLocale()).matches()) {
            errors.add("default_locale '" + manifest.defaultLocale() + "' is not a locale tag.");
        }
    }

    private static void validateRelationshipTypes(
            List<String> errors, List<String> types, RelationshipTypeCatalog catalog) {
        if (types == null || types.isEmpty()) {
            errors.add("relationship_types must list at least one value: an Astrana Trusted Attestation Server "
                    + "that attests to nothing has nothing to serve.");
            return;
        }

        for (String type : types) {
            if (!catalog.isGoverned(type)) {
                errors.add("relationship_types contains '" + type + "', which is not in the governed "
                        + "enum. Adding a value is a change to the shared vocabulary, not per-organisation "
                        + "configuration.");
            }
        }

        if (new HashSet<>(types).size() != types.size()) {
            errors.add("relationship_types contains duplicates.");
        }
    }

    /**
     * Optional, but if it is set it has to be embedded. A URL here would defeat the reason the field
     * carries the image rather than a link to it. The length cap is the contract schema's, and keeps the
     * manifest small, because every verifying instance fetches it. The light and dark logos are held to
     * the same rules.
     */
    private static void validateLogo(List<String> errors, String field, String value) {
        if (value == null) {
            return;
        }

        if (value.length() > MAX_LOGO_LENGTH) {
            errors.add(field + " is " + value.length() + " characters, over the limit of " + MAX_LOGO_LENGTH
                    + ": keep the logo icon-sized, because it inflates the manifest directly.");
        }

        if (!LogoData.PATTERN.matcher(value).matches()) {
            errors.add(field + " must be a base64 image data URI (for example \"data:image/png;base64,...\"), "
                    + "not a URL: the logo is embedded so that rendering it needs no request to the organisation.");
        }
    }

    /**
     * The dark-mode logo only rides alongside a light one. The light one is the default and fallback
     * everything renders when no dark preference applies, so a dark logo without it would silently never
     * show.
     */
    private static void validateDarkLogoHasALightOne(List<String> errors, String logoData, String logoDataDark) {
        if (logoDataDark != null && logoData == null) {
            errors.add("logo_data_dark is set without logo_data: the light logo is the default and fallback, "
                    + "so a dark-only logo would never be shown. Set logo_data as well.");
        }
    }

    private static void validateJurisdictions(List<String> errors, List<String> jurisdictions) {
        if (jurisdictions == null) {
            return;
        }

        for (String jurisdiction : jurisdictions) {
            if (jurisdiction == null || !JURISDICTION_CODE.matcher(jurisdiction).matches()) {
                errors.add("jurisdictions contains '" + jurisdiction + "', which is not an ISO 3166-1 "
                        + "alpha-2 or ISO 3166-2 subdivision code.");
            }
        }

        if (new HashSet<>(jurisdictions).size() != jurisdictions.size()) {
            errors.add("jurisdictions contains duplicates.");
        }
    }

    private static void validateLocalized(
            List<String> errors,
            String field,
            Map<String, String> value,
            String defaultLocale,
            boolean required,
            boolean urls) {
        if (value == null || value.isEmpty()) {
            if (required) {
                errors.add(field + " is required and must be a locale-keyed object.");
            }

            return;
        }

        // The fallback rule must never have to guess: whatever default_locale names has to be present, or
        // a consumer whose preferred locale is missing has nowhere to fall back to.
        if (!value.containsKey(defaultLocale)) {
            errors.add(field + " has no entry for the default locale '" + defaultLocale + "'.");
        }

        value.forEach((locale, text) -> {
            if (!LOCALE_TAG.matcher(locale).matches()) {
                errors.add(field + " has key '" + locale + "', which is not a locale tag.");
            }

            if (text == null || text.isBlank()) {
                errors.add(field + "." + locale + " is empty.");
            } else if (urls) {
                validateHttpsUrl(errors, field + "." + locale, text);
            }
        });
    }

    /**
     * An absolute URL whose scheme is spelt {@code https://} in lower case, the shape the contract schema's
     * case-sensitive pattern accepts and the other two implementations require. {@code HTTPS://} is a valid
     * URL to a browser, but a manifest that fails the schema is no use to a verifying instance, so it is
     * refused here, at startup.
     */
    private static void validateHttpsUrl(List<String> errors, String field, String value) {
        if (value == null || value.isBlank()) {
            errors.add(field + " is required.");
            return;
        }

        try {
            URI uri = new URI(value);
            if (!value.startsWith("https://") || uri.getHost() == null) {
                errors.add(field + " must be an absolute URL starting with lower-case https://, was '" + value + "'.");
            }
        } catch (URISyntaxException _) {
            errors.add(field + " is not a valid URL: '" + value + "'.");
        }
    }
}
