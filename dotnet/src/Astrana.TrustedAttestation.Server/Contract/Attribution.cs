using System.Reflection;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Astrana.TrustedAttestation.Server.Contract;

/// <summary>
/// The software's own attribution, loaded from the embedded copy of the contract's <c>attribution.json</c>:
/// the footer every page shows and the content of the licence page.
///
/// Decision record 33 in docs/adr requires it on every deployment and does not let the organisation running the instance
/// configure it away, so it is not in <see cref="Configuration.TrustedAttestationOptions"/> at all. The same
/// file is imported by all three implementations, so the notice is identical wherever it appears.
/// Organisation branding is a separate thing entirely, and comes from the manifest.
/// </summary>
public sealed class Attribution
{
    private const string ResourceName = "Astrana.TrustedAttestation.Server.Contract.attribution.json";

    public required string ProjectName { get; init; }

    public required string ProjectUrl { get; init; }

    /// <summary>Where the source code and documentation live.</summary>
    public required string SourceUrl { get; init; }

    /// <summary>The one-line notice: the copyright line and the licence, shown first on the licence page.</summary>
    public required string LicenseNotice { get; init; }

    /// <summary>
    /// The short text the footer links with (for example, "Licence"), kept separate from the fuller
    /// <see cref="LicenseNotice"/>. Falls back to the notice when the contract file sets no label, so a
    /// footer is never empty.
    /// </summary>
    public required string LicenseLabel { get; init; }

    /// <summary>Where the footer's licence link goes. Null renders the label as plain text.</summary>
    public string? LicenseUrl { get; init; }

    /// <summary>The licence text, one entry per paragraph, as the licence page shows it.</summary>
    public required IReadOnlyList<string> LicenseText { get; init; }

    /// <summary>The trademark notice the licence page shows after the licence text.</summary>
    public required string TrademarkNotice { get; init; }

    public static Attribution Load()
    {
        using var stream = typeof(Attribution).GetTypeInfo().Assembly.GetManifestResourceStream(ResourceName)
            ?? throw new InvalidOperationException(
                $"Embedded contract resource '{ResourceName}' is missing. The build must embed the " +
                "contract's attribution.json; see Astrana.TrustedAttestation.Server.csproj.");

        var document = JsonSerializer.Deserialize<AttributionDocument>(stream)
            ?? throw new InvalidOperationException($"Embedded contract resource '{ResourceName}' is empty.");

        if (string.IsNullOrWhiteSpace(document.ProjectName) || string.IsNullOrWhiteSpace(document.ProjectUrl))
        {
            throw new InvalidOperationException(
                $"Embedded contract resource '{ResourceName}' must name the project and link to it.");
        }

        return new Attribution
        {
            ProjectName = document.ProjectName,
            ProjectUrl = document.ProjectUrl,
            SourceUrl = document.SourceUrl,
            LicenseNotice = document.License?.Notice ?? string.Empty,
            LicenseLabel = string.IsNullOrWhiteSpace(document.License?.Label)
                ? document.License?.Notice ?? string.Empty
                : document.License.Label,
            LicenseUrl = string.IsNullOrWhiteSpace(document.License?.Url) ? null : document.License.Url,
            LicenseText = document.License?.Text ?? [],
            TrademarkNotice = document.License?.TrademarkNotice ?? string.Empty,
        };
    }

    private sealed class AttributionDocument
    {
        [JsonPropertyName("project_name")]
        public string ProjectName { get; init; } = string.Empty;

        [JsonPropertyName("project_url")]
        public string ProjectUrl { get; init; } = string.Empty;

        [JsonPropertyName("source_url")]
        public string SourceUrl { get; init; } = string.Empty;

        [JsonPropertyName("license")]
        public LicenseDocument? License { get; init; }
    }

    private sealed class LicenseDocument
    {
        [JsonPropertyName("notice")]
        public string Notice { get; init; } = string.Empty;

        [JsonPropertyName("label")]
        public string? Label { get; init; }

        [JsonPropertyName("url")]
        public string? Url { get; init; }

        [JsonPropertyName("text")]
        public string[] Text { get; init; } = [];

        [JsonPropertyName("trademark_notice")]
        public string TrademarkNotice { get; init; } = string.Empty;
    }
}
