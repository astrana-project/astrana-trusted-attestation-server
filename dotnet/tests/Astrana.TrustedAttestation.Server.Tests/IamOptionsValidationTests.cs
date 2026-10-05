using Astrana.TrustedAttestation.Server.Tests.Support;
using Microsoft.Extensions.Options;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The OpenID Connect client identifier has no default. The shipped appsettings.json is read as the
/// application reads it, and validated by the same data annotation validation the application runs at start,
/// so an operator who leaves the client identifier out is refused in the same way as for any other
/// required setting.
/// </summary>
public class IamOptionsValidationTests
{
    private static ValidateOptionsResult ValidateShippedSettingsWith(params (string Key, string? Value)[] settings) =>
        ShippedSettings.ValidateWith(settings);

    [Fact]
    public void A_missing_client_identifier_under_openid_connect_is_refused_and_named()
    {
        var result = ValidateShippedSettingsWith();

        Assert.True(result.Failed);
        Assert.Contains("'Iam.ClientId'", result.FailureMessage, StringComparison.Ordinal);
        Assert.Contains("TrustedAttestation:Iam:ClientId", result.FailureMessage, StringComparison.Ordinal);
    }

    [Fact]
    public void A_blank_client_identifier_under_openid_connect_is_refused()
    {
        Assert.True(ValidateShippedSettingsWith(("TrustedAttestation:Iam:ClientId", "  ")).Failed);
    }

    [Fact]
    public void A_configured_client_identifier_is_accepted()
    {
        Assert.True(ValidateShippedSettingsWith(("TrustedAttestation:Iam:ClientId", "trusted-attestation")).Succeeded);
    }

    [Fact]
    public void Saml_needs_no_client_identifier()
    {
        Assert.True(ValidateShippedSettingsWith(("TrustedAttestation:Iam:Protocol", "Saml")).Succeeded);
    }
}
