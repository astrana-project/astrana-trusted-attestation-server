using System.Text.Json.Serialization;
using Astrana.TrustedAttestation.Server.Configuration;

namespace Astrana.TrustedAttestation.Server.Manifest;

/// <summary>
/// The public manifest served at <c>/.well-known/ata-manifest.json</c>. Describes the organisation and
/// what its Astrana Trusted Attestation server offers, so a verifying Astrana instance knows what the
/// organisation can attest to and a prospective member knows where to sign in, without either needing to be
/// told separately.
///
/// Built from configuration, never from the database: it describes the org, not any member, which is also
/// why it needs no auth and carries no privacy concern.
/// </summary>
public sealed record ManifestDocument
{
    /// <summary>
    /// An integer, not a URL segment. The manifest's own path is never versioned -- that is the point of a
    /// well-known document. Consumers check this to know how to parse the rest.
    /// </summary>
    [JsonPropertyName("manifest_version")]
    public required int ManifestVersion { get; init; }

    /// <summary>The locale a consumer falls back to when its preferred one is absent below.</summary>
    [JsonPropertyName("default_locale")]
    public required string DefaultLocale { get; init; }

    /// <summary>Locale-keyed. Never machine-translated by a consumer: an organisation name is a proper noun.</summary>
    [JsonPropertyName("name")]
    public required IReadOnlyDictionary<string, string> Name { get; init; }

    [JsonPropertyName("description")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public IReadOnlyDictionary<string, string>? Description { get; init; }

    [JsonPropertyName("website")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public IReadOnlyDictionary<string, string>? Website { get; init; }

    /// <summary>
    /// Optional. A contact/support page a member is pointed to when they hold no relationship yet. Locale-keyed.
    /// A consumer, and the self-service empty state, fall back to <see cref="Website"/> when this is absent.
    /// </summary>
    [JsonPropertyName("support_url")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public IReadOnlyDictionary<string, string>? SupportUrl { get; init; }

    /// <summary>
    /// Optional, and embedded rather than linked. A <c>logo_url</c> would mean any third-party consumer
    /// of this manifest -- a directory, or a peer rendering the org's branding during connection review
    /// -- had to fetch it from the org's own server: an extra request the org can see, correlated with
    /// whoever is viewing that content at that moment. That is the same side-channel the anonymous
    /// attest endpoint avoids, so the logo travels inside the one manifest fetch instead.
    /// </summary>
    [JsonPropertyName("logo_data")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public string? LogoData { get; init; }

    /// <summary>
    /// Optional dark-mode variant of <see cref="LogoData"/>, embedded the same way. A consumer that renders
    /// the org's branding in a dark context uses this when present; <see cref="LogoData"/> stays the default
    /// and the fallback, so this never appears without it.
    /// </summary>
    [JsonPropertyName("logo_data_dark")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public string? LogoDataDark { get; init; }

    [JsonPropertyName("privacy_notice_url")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public IReadOnlyDictionary<string, string>? PrivacyNoticeUrl { get; init; }

    /// <summary>
    /// Optional. The fact a verifying Astrana instance needs to judge issuer trustworthiness for itself,
    /// because whether identity-verified registration is legally required for this organisation's
    /// relationship types depends on jurisdiction. Astrana Trusted Attestation states it and forms no
    /// opinion about it.
    /// </summary>
    [JsonPropertyName("jurisdictions")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public IReadOnlyList<string>? Jurisdictions { get; init; }

    /// <summary>The subset of the governed enum this org issues. Never locale-keyed: fixed identifiers.</summary>
    [JsonPropertyName("relationship_types")]
    public required IReadOnlyList<string> RelationshipTypes { get; init; }

    /// <summary>
    /// The landing page, where a prospective member starts and signs in on the way to the self-service
    /// page.
    /// </summary>
    [JsonPropertyName("enrollment_url")]
    public required string EnrollmentUrl { get; init; }

    /// <summary>The exact endpoint a verifying peer calls. A full URL, not a base to build a path from.</summary>
    [JsonPropertyName("attestation_url")]
    public required string AttestationUrl { get; init; }

    /// <param name="logoData">Already resolved to a data URI by <see cref="Manifest.LogoData"/>.</param>
    /// <param name="logoDataDark">The dark-mode logo, also already resolved to a data URI.</param>
    public static ManifestDocument From(ManifestOptions options, string? logoData, string? logoDataDark) => new()
    {
        ManifestVersion = options.ManifestVersion,
        DefaultLocale = options.DefaultLocale,
        Name = options.Name,
        Description = NullIfEmpty(options.Description),
        Website = NullIfEmpty(options.Website),
        SupportUrl = NullIfEmpty(options.SupportUrl),
        LogoData = logoData,
        LogoDataDark = logoDataDark,
        PrivacyNoticeUrl = NullIfEmpty(options.PrivacyNoticeUrl),
        Jurisdictions = options.Jurisdictions.Length == 0 ? null : options.Jurisdictions,
        RelationshipTypes = options.RelationshipTypes,
        EnrollmentUrl = options.EnrollmentUrl,
        AttestationUrl = options.AttestationUrl,
    };

    private static Dictionary<string, string>? NullIfEmpty(Dictionary<string, string> value) =>
        value.Count == 0 ? null : value;
}
