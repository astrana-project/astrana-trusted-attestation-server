using Astrana.TrustedAttestation.Server.Configuration;
using Astrana.TrustedAttestation.Server.Manifest;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// How the served manifest is built from configuration.
///
/// The one decision here worth pinning is the empty-to-absent rule: an organisation that configures no
/// description, website, privacy notice or jurisdictions must serve a manifest that omits those keys
/// entirely, not one that carries empty objects a consumer would have to special-case. The required fields
/// pass straight through, and the logo arrives already resolved to a data URI.
/// </summary>
public class ManifestDocumentTests
{
    private static ManifestOptions FullOptions() => new()
    {
        ManifestVersion = 1,
        DefaultLocale = "en",
        Name = new Dictionary<string, string> { ["en"] = "Acme Bank" },
        Description = new Dictionary<string, string> { ["en"] = "Retail banking" },
        Website = new Dictionary<string, string> { ["en"] = "https://acme.example" },
        SupportUrl = new Dictionary<string, string> { ["en"] = "https://acme.example/contact" },
        PrivacyNoticeUrl = new Dictionary<string, string> { ["en"] = "https://acme.example/privacy" },
        Jurisdictions = ["US", "US-NY"],
        RelationshipTypes = ["employee"],
        EnrollmentUrl = "https://acme.example/me",
        AttestationUrl = "https://acme.example/api/v1/attest",
    };

    [Fact]
    public void From_maps_every_configured_field_and_passes_the_resolved_logo_through()
    {
        var document = ManifestDocument.From(FullOptions(), "data:image/png;base64,AAAA", "data:image/png;base64,BBBB");

        Assert.Equal(1, document.ManifestVersion);
        Assert.Equal("en", document.DefaultLocale);
        Assert.Equal("Acme Bank", document.Name["en"]);
        Assert.Equal("Retail banking", document.Description!["en"]);
        Assert.Equal("https://acme.example", document.Website!["en"]);
        Assert.Equal("https://acme.example/contact", document.SupportUrl!["en"]);
        Assert.Equal("https://acme.example/privacy", document.PrivacyNoticeUrl!["en"]);
        Assert.Equal(["US", "US-NY"], document.Jurisdictions);
        Assert.Equal(["employee"], document.RelationshipTypes);
        Assert.Equal("https://acme.example/me", document.EnrollmentUrl);
        Assert.Equal("https://acme.example/api/v1/attest", document.AttestationUrl);
        Assert.Equal("data:image/png;base64,AAAA", document.LogoData);
        Assert.Equal("data:image/png;base64,BBBB", document.LogoDataDark);
    }

    [Fact]
    public void Empty_optional_collections_become_absent_rather_than_empty()
    {
        var options = FullOptions();
        options.Description = [];
        options.Website = [];
        options.SupportUrl = [];
        options.PrivacyNoticeUrl = [];
        options.Jurisdictions = [];

        var document = ManifestDocument.From(options, logoData: null, logoDataDark: null);

        Assert.Null(document.Description);
        Assert.Null(document.Website);
        Assert.Null(document.SupportUrl);
        Assert.Null(document.PrivacyNoticeUrl);
        Assert.Null(document.Jurisdictions);
        Assert.Null(document.LogoData);
        Assert.Null(document.LogoDataDark);

        // The required content is unaffected by the optionals being empty.
        Assert.Equal("Acme Bank", document.Name["en"]);
        Assert.Equal(["employee"], document.RelationshipTypes);
    }

    [Fact]
    public void A_populated_optional_is_kept()
    {
        // The other side of the empty-to-absent rule: a description that is actually configured survives.
        var document = ManifestDocument.From(FullOptions(), logoData: null, logoDataDark: null);

        Assert.NotNull(document.Description);
        Assert.Single(document.Description);
    }
}
