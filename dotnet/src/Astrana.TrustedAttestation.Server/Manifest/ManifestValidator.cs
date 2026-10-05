using System.Text.RegularExpressions;
using Astrana.TrustedAttestation.Server.Contract;

namespace Astrana.TrustedAttestation.Server.Manifest;

/// <summary>
/// Validates the configured manifest at startup, so a broken one is found at boot rather than by a
/// verifying Astrana instance whose lookup fails. Same reasoning as refusing to start without TLS.
///
/// Hand-written rather than driven by <c>shared/contract/manifest.schema.json</c>, because pulling in a JSON
/// Schema engine would work against the "no exotic dependency" bar the rest of this design holds to, for a
/// document with thirteen fields. The schema file remains the canonical shape, and these rules check all of it.
/// </summary>
public static partial class ManifestValidator
{
    /// <summary>The longest logo, in characters, the contract schema allows for either logo field.</summary>
    public const int MaxLogoLength = 65_536;

    public static IReadOnlyList<string> Validate(ManifestDocument manifest, RelationshipTypeCatalog catalog)
    {
        var errors = new List<string>();

        if (manifest.ManifestVersion != 1)
        {
            errors.Add($"manifest_version must be 1, was {manifest.ManifestVersion}.");
        }

        if (!LocaleTag().IsMatch(manifest.DefaultLocale))
        {
            errors.Add($"default_locale '{manifest.DefaultLocale}' is not a locale tag.");
        }

        ValidateLocalized(errors, "name", manifest.Name, manifest.DefaultLocale, required: true, urls: false);
        ValidateLocalized(errors, "description", manifest.Description, manifest.DefaultLocale, required: false, urls: false);
        ValidateLocalized(errors, "website", manifest.Website, manifest.DefaultLocale, required: false, urls: true);
        ValidateLocalized(errors, "support_url", manifest.SupportUrl, manifest.DefaultLocale, required: false, urls: true);
        ValidateLocalized(errors, "privacy_notice_url", manifest.PrivacyNoticeUrl, manifest.DefaultLocale, required: false, urls: true);

        if (manifest.RelationshipTypes.Count == 0)
        {
            errors.Add("relationship_types must list at least one value: an Astrana Trusted Attestation server that attests to nothing has nothing to serve.");
        }

        foreach (var type in manifest.RelationshipTypes.Where(type => !catalog.IsGoverned(type)))
        {
            errors.Add($"relationship_types contains '{type}', which is not in the governed enum. " +
                       "Adding a value is a change to the shared vocabulary, not per-organisation configuration.");
        }

        if (manifest.RelationshipTypes.Distinct(StringComparer.Ordinal).Count() != manifest.RelationshipTypes.Count)
        {
            errors.Add("relationship_types contains duplicates.");
        }

        ValidateLogo(errors, "logo_data", manifest.LogoData);
        ValidateLogo(errors, "logo_data_dark", manifest.LogoDataDark);

        // The light logo is the default and the fallback everything renders when no dark preference applies,
        // so a dark logo without a light one would silently never show. Caught here rather than surprising an
        // operator who set only the dark variant and saw the generic mark.
        if (manifest.LogoDataDark is not null && manifest.LogoData is null)
        {
            errors.Add("logo_data_dark is set without logo_data: the light logo is the default and fallback, " +
                       "so a dark-only logo would never be shown. Set logo_data as well.");
        }

        ValidateHttpsUrl(errors, "enrollment_url", manifest.EnrollmentUrl);
        ValidateHttpsUrl(errors, "attestation_url", manifest.AttestationUrl);

        foreach (var jurisdiction in (manifest.Jurisdictions ?? []).Where(j => !JurisdictionCode().IsMatch(j)))
        {
            errors.Add($"jurisdictions contains '{jurisdiction}', which is not an ISO 3166-1 alpha-2 " +
                       "or ISO 3166-2 subdivision code.");
        }

        var jurisdictions = manifest.Jurisdictions ?? [];
        if (jurisdictions.Distinct(StringComparer.Ordinal).Count() != jurisdictions.Count)
        {
            errors.Add("jurisdictions contains duplicates.");
        }

        return errors;
    }

    /// <summary>
    /// Optional, but if it is set it has to be embedded. A URL here would defeat the reason the field
    /// carries the image rather than a link to it. The length cap keeps the manifest small, because every
    /// verifying instance fetches it.
    /// </summary>
    private static void ValidateLogo(List<string> errors, string field, string? value)
    {
        if (value is null)
        {
            return;
        }

        if (value.Length > MaxLogoLength)
        {
            errors.Add($"{field} is {value.Length} characters, over the limit of {MaxLogoLength}: " +
                       "keep the logo icon-sized, because it inflates the manifest directly.");
        }

        if (!LogoData.Pattern().IsMatch(value))
        {
            errors.Add($"{field} must be a base64 image data URI (for example \"data:image/png;base64,...\"), " +
                       "not a URL: the logo is embedded so that rendering it needs no request to the org.");
        }
    }

    private static void ValidateLocalized(
        List<string> errors,
        string field,
        IReadOnlyDictionary<string, string>? value,
        string defaultLocale,
        bool required,
        bool urls)
    {
        if (value is null || value.Count == 0)
        {
            if (required)
            {
                errors.Add($"{field} is required and must be a locale-keyed object.");
            }

            return;
        }

        // The fallback rule must never have to guess: whatever default_locale names has to be present, or a
        // consumer whose preferred locale is missing has nowhere to fall back to.
        if (!value.ContainsKey(defaultLocale))
        {
            errors.Add($"{field} has no entry for the default locale '{defaultLocale}'.");
        }

        foreach (var (locale, text) in value)
        {
            if (!LocaleTag().IsMatch(locale))
            {
                errors.Add($"{field} has key '{locale}', which is not a locale tag.");
            }

            if (string.IsNullOrWhiteSpace(text))
            {
                errors.Add($"{field}.{locale} is empty.");
            }
            else if (urls)
            {
                ValidateHttpsUrl(errors, $"{field}.{locale}", text);
            }
        }
    }

    private static void ValidateHttpsUrl(List<string> errors, string field, string value)
    {
        if (string.IsNullOrWhiteSpace(value))
        {
            errors.Add($"{field} is required.");
            return;
        }

        // The scheme is checked on the text, not on the parsed Uri, which lower-cases it: the contract
        // schema's pattern is case-sensitive, so "HTTPS://" would pass here and fail every consumer that
        // validates the manifest against the schema.
        if (!value.StartsWith("https://", StringComparison.Ordinal) || !Uri.TryCreate(value, UriKind.Absolute, out _))
        {
            errors.Add($"{field} must be an absolute URL starting with lower-case https://, was '{value}'.");
        }
    }

    [GeneratedRegex("^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$")]
    private static partial Regex LocaleTag();

    [GeneratedRegex("^[A-Z]{2}(-[A-Z0-9]{1,3})?$")]
    private static partial Regex JurisdictionCode();

}
