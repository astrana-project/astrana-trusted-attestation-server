package org.astrana.trustedattestation.manifest;

import java.util.List;
import java.util.Map;
import org.astrana.trustedattestation.config.TrustedAttestationProperties;

/**
 * The public manifest served at {@code /.well-known/ata-manifest.json}. Describes the organisation and
 * what its Astrana Trusted Attestation Server offers, so a verifying Astrana instance knows what the
 * organisation can attest to and a prospective member knows where to sign in, without either needing to be
 * told separately.
 *
 * <p>Built from configuration, never from the database. It describes the organisation, not any member,
 * which is also why it needs no authentication and carries no privacy concern.
 *
 * <p>Field names are camelCase and serialise to snake_case through the application-wide Jackson naming
 * strategy, so the JSON matches the contract without a single annotation. The locale-keyed maps are
 * unaffected, because a naming strategy applies to properties, not to map keys.
 *
 * @param manifestVersion an integer, not a URL segment. The manifest's own path is never versioned, which
 *     is the point of a well-known document
 * @param defaultLocale the locale a consumer falls back to when its preferred one is absent below
 * @param name locale-keyed, and never machine-translated by a consumer, since it is a proper noun
 * @param supportUrl optional, locale-keyed. A contact or support page a member is pointed to when they hold
 *     no relationship yet. A consumer, and the self-service page when it lists nothing, fall back to
 *     {@code website} when this is absent. Omitted from the JSON when empty.
 * @param logoData optional, and embedded rather than linked. A logo_url would mean any third-party
 *     consumer of this manifest, such as a directory or a verifying Astrana instance rendering the
 *     organisation's branding during connection review, had to fetch it from the organisation's own server.
 *     That is an extra request the organisation can see, correlated with whoever is viewing that content at
 *     that moment. It is the same side-channel the anonymous attest endpoint avoids, so the logo travels
 *     inside the one manifest fetch instead.
 * @param logoDataDark optional dark-mode variant of {@code logoData}, embedded the same way. A consumer
 *     that renders the organisation's branding in a dark context uses this when present. {@code logoData}
 *     stays the default and the fallback, so this never appears without it.
 * @param jurisdictions optional, the fact a verifying Astrana instance needs to judge issuer
 *     trustworthiness itself
 * @param relationshipTypes the subset of the governed vocabulary this organisation issues, never
 *     locale-keyed
 * @param enrollmentUrl the public landing page, where a member signs in to reach the self-service page
 * @param attestationUrl the exact endpoint a verifying Astrana instance calls, not a base to build a path
 *     from
 */
public record ManifestDocument(
        int manifestVersion,
        String defaultLocale,
        Map<String, String> name,
        Map<String, String> description,
        Map<String, String> website,
        Map<String, String> supportUrl,
        String logoData,
        String logoDataDark,
        Map<String, String> privacyNoticeUrl,
        List<String> jurisdictions,
        List<String> relationshipTypes,
        String enrollmentUrl,
        String attestationUrl) {

    /**
     * @param logoData already resolved to a data URI by {@link LogoData}
     * @param logoDataDark the dark-mode logo, also already resolved to a data URI
     */
    public static ManifestDocument from(
            TrustedAttestationProperties.Manifest properties, String logoData, String logoDataDark) {
        return new ManifestDocument(
                properties.getManifestVersion(),
                properties.getDefaultLocale(),
                properties.getName(),
                nullIfEmpty(properties.getDescription()),
                nullIfEmpty(properties.getWebsite()),
                nullIfEmpty(properties.getSupportUrl()),
                logoData,
                logoDataDark,
                nullIfEmpty(properties.getPrivacyNoticeUrl()),
                properties.getJurisdictions() == null
                                || properties.getJurisdictions().isEmpty()
                        ? null
                        : List.copyOf(properties.getJurisdictions()),
                properties.getRelationshipTypes() == null ? List.of() : List.copyOf(properties.getRelationshipTypes()),
                properties.getEnrollmentUrl(),
                properties.getAttestationUrl());
    }

    private static Map<String, String> nullIfEmpty(Map<String, String> value) {
        return value == null || value.isEmpty() ? null : Map.copyOf(value);
    }
}
