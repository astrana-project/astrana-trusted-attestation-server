using Astrana.TrustedAttestation.Server.Configuration;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.Options;

namespace Astrana.TrustedAttestation.Server.Tests.Support;

/// <summary>
/// The shipped appsettings.json, read as the application reads it and validated by the same data
/// annotation validation the application runs at start.
/// </summary>
internal static class ShippedSettings
{
    /// <summary>
    /// The required settings the shipped file leaves empty for the operator to fill, apart from the client
    /// identifier, whose absence is what some tests are about.
    /// </summary>
    private static readonly Dictionary<string, string?> OtherRequiredSettings = new()
    {
        ["TrustedAttestation:Database:ConnectionString"] = "Host=db;Database=ata",
        ["TrustedAttestation:Manifest:EnrollmentUrl"] = "https://ata.example.org/",
        ["TrustedAttestation:Manifest:AttestationUrl"] = "https://ata.example.org/api/v1/attest",
    };

    public static ValidateOptionsResult ValidateWith(params (string Key, string? Value)[] settings)
    {
        var configuration = new ConfigurationBuilder()
            .AddJsonFile(Path.Combine(AppContext.BaseDirectory, "appsettings.json"))
            .AddInMemoryCollection(OtherRequiredSettings)
            .AddInMemoryCollection(settings.Select(s => new KeyValuePair<string, string?>(s.Key, s.Value)))
            .Build();

        var options = configuration.GetSection(TrustedAttestationOptions.SectionName).Get<TrustedAttestationOptions>()!;

        return new DataAnnotationValidateOptions<TrustedAttestationOptions>(name: null).Validate(name: null, options);
    }
}
