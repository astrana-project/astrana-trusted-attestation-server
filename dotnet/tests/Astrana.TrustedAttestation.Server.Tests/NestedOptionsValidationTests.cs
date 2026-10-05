using Astrana.TrustedAttestation.Server.Tests.Support;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The rules on the nested settings (database, identity provider, audit and manifest) are enforced at start
/// like the rules on the root, so the .NET implementation refuses the same configuration the Java and PHP
/// implementations refuse. Data annotation validation does not look inside a nested object unless told to,
/// which is what these pin.
/// </summary>
public class NestedOptionsValidationTests
{
    private const string ClientId = "TrustedAttestation:Iam:ClientId";

    [Fact]
    public void The_shipped_settings_with_the_operator_values_filled_in_are_accepted()
    {
        Assert.True(ShippedSettings.ValidateWith((ClientId, "trusted-attestation")).Succeeded);
    }

    [Theory]
    [InlineData("0")]
    [InlineData("99999")]
    public void A_retention_period_outside_one_day_to_one_hundred_years_is_refused(string retentionDays)
    {
        var result = ShippedSettings.ValidateWith(
            (ClientId, "trusted-attestation"), ("TrustedAttestation:Audit:RetentionDays", retentionDays));

        Assert.True(result.Failed);
        Assert.Contains("RetentionDays", result.FailureMessage, StringComparison.Ordinal);
    }

    [Theory]
    [InlineData("1")]
    [InlineData("36500")]
    public void A_retention_period_at_either_end_of_the_range_is_accepted(string retentionDays)
    {
        Assert.True(ShippedSettings.ValidateWith(
            (ClientId, "trusted-attestation"), ("TrustedAttestation:Audit:RetentionDays", retentionDays)).Succeeded);
    }

    [Fact]
    public void An_empty_connection_string_is_refused()
    {
        var result = ShippedSettings.ValidateWith(
            (ClientId, "trusted-attestation"), ("TrustedAttestation:Database:ConnectionString", ""));

        Assert.True(result.Failed);
        Assert.Contains("ConnectionString", result.FailureMessage, StringComparison.Ordinal);
    }

    [Fact]
    public void An_empty_enrolment_address_is_refused()
    {
        var result = ShippedSettings.ValidateWith(
            (ClientId, "trusted-attestation"), ("TrustedAttestation:Manifest:EnrollmentUrl", ""));

        Assert.True(result.Failed);
        Assert.Contains("EnrollmentUrl", result.FailureMessage, StringComparison.Ordinal);
    }

    [Fact]
    public void An_empty_attestation_address_is_refused()
    {
        var result = ShippedSettings.ValidateWith(
            (ClientId, "trusted-attestation"), ("TrustedAttestation:Manifest:AttestationUrl", ""));

        Assert.True(result.Failed);
        Assert.Contains("AttestationUrl", result.FailureMessage, StringComparison.Ordinal);
    }

    [Fact]
    public void Saml_settings_with_no_openid_connect_values_are_accepted()
    {
        // The client identifier and the authority are needed only under OpenID Connect, so a nested rule
        // must not demand them of a SAML deployment.
        Assert.True(ShippedSettings.ValidateWith(
            ("TrustedAttestation:Iam:Protocol", "Saml"),
            ("TrustedAttestation:Iam:Authority", ""),
            (ClientId, "")).Succeeded);
    }
}
