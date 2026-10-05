using Astrana.TrustedAttestation.Server.Contract;
using Astrana.TrustedAttestation.Server.Manifest;

namespace Astrana.TrustedAttestation.Server.Tests;

public class ManifestValidatorTests
{
    private readonly RelationshipTypeCatalog _catalog = RelationshipTypeCatalog.Load();

    private static ManifestDocument Valid() => new()
    {
        ManifestVersion = 1,
        DefaultLocale = "en",
        Name = new Dictionary<string, string> { ["en"] = "Acme Bank", ["fr"] = "Banque Acme" },
        Description = new Dictionary<string, string> { ["en"] = "Retail and commercial banking" },
        Website = new Dictionary<string, string> { ["en"] = "https://acmebank.example" },
        SupportUrl = new Dictionary<string, string> { ["en"] = "https://acmebank.example/contact" },
        PrivacyNoticeUrl = new Dictionary<string, string> { ["en"] = "https://acmebank.example/privacy" },
        Jurisdictions = ["US", "US-NY"],
        RelationshipTypes = ["employee", "client"],
        EnrollmentUrl = "https://ata.acmebank.example/me",
        AttestationUrl = "https://ata.acmebank.example/api/v1/attest",
    };

    [Fact]
    public void Accepts_the_specs_own_example()
    {
        Assert.Empty(ManifestValidator.Validate(Valid(), _catalog));
    }

    [Fact]
    public void Accepts_a_manifest_with_only_the_required_fields()
    {
        var manifest = Valid() with
        {
            Description = null,
            Website = null,
            PrivacyNoticeUrl = null,
            Jurisdictions = null,   // optional: an org may decline to declare where it operates
        };

        Assert.Empty(ManifestValidator.Validate(manifest, _catalog));
    }

    [Fact]
    public void Rejects_a_relationship_type_outside_the_governed_enum()
    {
        var manifest = Valid() with { RelationshipTypes = ["employee", "patient"] };

        var errors = ManifestValidator.Validate(manifest, _catalog);

        Assert.Contains(errors, e => e.Contains("patient", StringComparison.Ordinal));
    }

    [Fact]
    public void Rejects_a_manifest_that_attests_to_nothing()
    {
        Assert.Contains(
            ManifestValidator.Validate(Valid() with { RelationshipTypes = [] }, _catalog),
            e => e.Contains("relationship_types", StringComparison.Ordinal));
    }

    [Fact]
    public void Rejects_a_missing_default_locale_entry()
    {
        // If default_locale names a locale that is not present, the fallback rule has nowhere to land.
        var manifest = Valid() with
        {
            DefaultLocale = "de",
            Name = new Dictionary<string, string> { ["en"] = "Acme Bank" },
        };

        Assert.Contains(
            ManifestValidator.Validate(manifest, _catalog),
            e => e.Contains("default locale", StringComparison.OrdinalIgnoreCase));
    }

    [Theory]
    [InlineData("http://ata.acmebank.example/api/v1/attest")]
    [InlineData("/api/v1/attest")]
    [InlineData("")]
    public void Rejects_an_attestation_url_that_is_not_absolute_https(string url)
    {
        Assert.Contains(
            ManifestValidator.Validate(Valid() with { AttestationUrl = url }, _catalog),
            e => e.Contains("attestation_url", StringComparison.Ordinal));
    }

    [Theory]
    [InlineData("http://acmebank.example/enrol")]
    [InlineData("/enrol")]
    [InlineData("")]
    public void Rejects_an_enrollment_url_that_is_not_absolute_https(string url)
    {
        // The same rule and the same code as attestation_url -- and, until this test, the same code with
        // no test: deleting the enrollment_url line broke nothing. A prospective member follows this URL,
        // so a plain-http one would send them somewhere their credentials travel in the clear.
        Assert.Contains(
            ManifestValidator.Validate(Valid() with { EnrollmentUrl = url }, _catalog),
            e => e.Contains("enrollment_url", StringComparison.Ordinal));
    }

    [Theory]
    [InlineData("HTTPS://ata.acmebank.example/api/v1/attest")]
    [InlineData("Https://ata.acmebank.example/api/v1/attest")]
    public void Rejects_an_upper_case_scheme_as_the_contract_schemas_pattern_does(string url)
    {
        // Uri lower-cases the scheme, so a check on the parsed Uri would accept these, while every consumer
        // validating the manifest against shared/contract/manifest.schema.json, whose pattern is
        // case-sensitive, would refuse the document.
        Assert.Contains(
            ManifestValidator.Validate(Valid() with { AttestationUrl = url }, _catalog),
            e => e.Contains("attestation_url", StringComparison.Ordinal));
        Assert.Contains(
            ManifestValidator.Validate(
                Valid() with { Website = new Dictionary<string, string> { ["en"] = url } }, _catalog),
            e => e.Contains("website.en", StringComparison.Ordinal));
    }

    [Fact]
    public void Rejects_a_localized_url_that_is_not_https()
    {
        // website and privacy_notice_url are locale-keyed and each entry must be https too, checked by
        // the same helper under a "field.locale" name -- a branch no other test reached.
        Assert.Contains(
            ManifestValidator.Validate(
                Valid() with { Website = new Dictionary<string, string> { ["en"] = "http://acmebank.example" } },
                _catalog),
            e => e.Contains("website.en", StringComparison.Ordinal));
    }

    [Fact]
    public void Rejects_an_unexpected_manifest_version()
    {
        Assert.Contains(
            ManifestValidator.Validate(Valid() with { ManifestVersion = 2 }, _catalog),
            e => e.Contains("manifest_version", StringComparison.Ordinal));
    }

    [Theory]
    [InlineData("USA")]
    [InlineData("us")]
    [InlineData("US-NEWYORK")]
    public void Rejects_a_jurisdiction_that_is_not_an_iso_code(string code)
    {
        Assert.Contains(
            ManifestValidator.Validate(Valid() with { Jurisdictions = [code] }, _catalog),
            e => e.Contains("jurisdictions", StringComparison.Ordinal));
    }

    /// <summary>A one-pixel PNG. The rule under test is the shape of the value, not the image.</summary>
    private const string Logo =
        "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==";

    [Fact]
    public void Accepts_an_embedded_logo()
    {
        Assert.Empty(ManifestValidator.Validate(Valid() with { LogoData = Logo }, _catalog));
    }

    [Fact]
    public void Accepts_a_manifest_with_no_logo_at_all()
    {
        Assert.Empty(ManifestValidator.Validate(Valid() with { LogoData = null }, _catalog));
    }

    [Fact]
    public void Rejects_a_logo_that_is_a_url_rather_than_embedded()
    {
        // A link would put the request back on the org's server, which is what embedding the image
        // exists to avoid.
        Assert.Contains(
            ManifestValidator.Validate(Valid() with { LogoData = "https://acmebank.example/logo.png" }, _catalog),
            e => e.Contains("logo_data", StringComparison.Ordinal));
    }

    [Fact]
    public void Rejects_a_logo_that_is_not_an_image()
    {
        Assert.Contains(
            ManifestValidator.Validate(Valid() with { LogoData = "data:text/html;base64,PGgxPmhpPC9oMT4=" }, _catalog),
            e => e.Contains("logo_data", StringComparison.Ordinal));
    }

    [Fact]
    public void Accepts_a_dark_logo_alongside_a_light_one()
    {
        Assert.Empty(ManifestValidator.Validate(Valid() with { LogoData = Logo, LogoDataDark = Logo }, _catalog));
    }

    [Fact]
    public void Rejects_a_dark_logo_that_is_a_url_rather_than_embedded()
    {
        Assert.Contains(
            ManifestValidator.Validate(
                Valid() with { LogoData = Logo, LogoDataDark = "https://acmebank.example/logo-dark.png" }, _catalog),
            e => e.Contains("logo_data_dark", StringComparison.Ordinal));
    }

    [Fact]
    public void Rejects_a_dark_logo_set_without_a_light_logo()
    {
        // The light logo is the default and the fallback everything renders when no dark preference applies,
        // so a dark-only logo would silently never show. The validator catches that at boot.
        Assert.Contains(
            ManifestValidator.Validate(Valid() with { LogoData = null, LogoDataDark = Logo }, _catalog),
            e => e.Contains("logo_data_dark", StringComparison.Ordinal));
    }

    /// <summary>A well-formed image data URI of exactly <paramref name="length"/> characters.</summary>
    private static string LogoOfLength(int length)
    {
        const string prefix = "data:image/png;base64,";
        return prefix + new string('A', length - prefix.Length);
    }

    [Fact]
    public void Accepts_logos_at_the_length_limit()
    {
        var logo = LogoOfLength(ManifestValidator.MaxLogoLength);

        Assert.Empty(ManifestValidator.Validate(Valid() with { LogoData = logo, LogoDataDark = logo }, _catalog));
    }

    [Fact]
    public void Rejects_a_logo_over_the_length_limit()
    {
        var errors = ManifestValidator.Validate(
            Valid() with { LogoData = LogoOfLength(ManifestValidator.MaxLogoLength + 1) }, _catalog);

        var error = Assert.Single(errors);
        Assert.StartsWith("logo_data is 65537 characters", error, StringComparison.Ordinal);
    }

    [Fact]
    public void Rejects_a_dark_logo_over_the_length_limit()
    {
        var errors = ManifestValidator.Validate(
            Valid() with { LogoData = Logo, LogoDataDark = LogoOfLength(ManifestValidator.MaxLogoLength + 1) },
            _catalog);

        var error = Assert.Single(errors);
        Assert.StartsWith("logo_data_dark is 65537 characters", error, StringComparison.Ordinal);
    }

    [Fact]
    public void Rejects_duplicate_jurisdictions()
    {
        var errors = ManifestValidator.Validate(Valid() with { Jurisdictions = ["US", "US-NY", "US"] }, _catalog);

        Assert.Equal(["jurisdictions contains duplicates."], errors);
    }

    [Fact]
    public void Accepts_a_manifest_with_no_support_url()
    {
        Assert.Empty(ManifestValidator.Validate(Valid() with { SupportUrl = null }, _catalog));
    }

    [Fact]
    public void Rejects_a_support_url_that_is_not_https()
    {
        var manifest = Valid() with
        {
            SupportUrl = new Dictionary<string, string> { ["en"] = "http://acmebank.example/contact" },
        };

        Assert.Contains(
            ManifestValidator.Validate(manifest, _catalog),
            e => e.Contains("support_url", StringComparison.Ordinal));
    }

    [Fact]
    public void Rejects_duplicate_relationship_types()
    {
        Assert.Contains(
            ManifestValidator.Validate(Valid() with { RelationshipTypes = ["employee", "employee"] }, _catalog),
            e => e.Contains("duplicates", StringComparison.OrdinalIgnoreCase));
    }
}
