using System.Net;
using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using System.Text;
using Astrana.TrustedAttestation.Server.Configuration;
using Astrana.TrustedAttestation.Server.Endpoints;
using Astrana.TrustedAttestation.Server.Security;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Authentication.OpenIdConnect;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Http;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Options;
using Sustainsys.Saml2.AspNetCore2;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// Which sign-in handler a deployment gets, and when its SAML signing certificate is checked. A deployment
/// registers the handler for the protocol it configured and no other, so settings that only the other
/// protocol needs can stay empty. The certificate is read while the application starts, so a missing or
/// unreadable one stops start-up rather than turning every request into an error.
/// </summary>
public class IamAuthenticationTests
{
    private const string IdentityProviderEntityId = "https://idp.example.org/metadata";

    /// <summary>
    /// Where the fixture files are written: the test output folder, which every build replaces, so nothing
    /// is left outside it.
    /// </summary>
    private static readonly string FixtureDirectory =
        Directory.CreateDirectory(Path.Combine(AppContext.BaseDirectory, "iam-authentication-fixtures")).FullName;

    /// <summary>
    /// The smallest identity provider metadata the SAML handler accepts, read from a local file so that no
    /// test reaches the network.
    /// </summary>
    private static readonly string MetadataLocation = WriteFixture("idp-metadata.xml", Encoding.UTF8.GetBytes(
        $"""
        <EntityDescriptor xmlns="urn:oasis:names:tc:SAML:2.0:metadata" entityID="{IdentityProviderEntityId}">
          <IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
            <KeyDescriptor use="signing">
              <KeyInfo xmlns="http://www.w3.org/2000/09/xmldsig#">
                <X509Data><X509Certificate>{IdentityProviderCertificate()}</X509Certificate></X509Data>
              </KeyInfo>
            </KeyDescriptor>
            <SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"
                                 Location="https://idp.example.org/sso" />
          </IDPSSODescriptor>
        </EntityDescriptor>
        """));

    private static X509Certificate2 SelfSignedCertificate()
    {
        using var key = RSA.Create(2048);
        var request = new CertificateRequest("CN=ata.example.org", key, HashAlgorithmName.SHA256, RSASignaturePadding.Pkcs1);
        return request.CreateSelfSigned(DateTimeOffset.UtcNow.AddDays(-1), DateTimeOffset.UtcNow.AddDays(1));
    }

    /// <summary>The certificate the metadata says the identity provider signs with, base64 encoded.</summary>
    private static string IdentityProviderCertificate()
    {
        using var certificate = SelfSignedCertificate();
        return Convert.ToBase64String(certificate.RawData);
    }

    private static string WriteFixture(string name, byte[] content)
    {
        var path = Path.Combine(FixtureDirectory, name);
        File.WriteAllBytes(path, content);
        return new Uri(path).AbsoluteUri;
    }

    /// <summary>A SAML deployment, with every OpenID Connect setting left empty as the installation guide allows.</summary>
    private static IamOptions Saml(string? signingCertificatePath = null, string? password = null) => new()
    {
        Protocol = IamProtocol.Saml,
        Authority = string.Empty,
        ClientId = string.Empty,
        Saml = new SamlOptions
        {
            EntityId = "https://ata.example.org/Saml2",
            IdentityProviderEntityId = IdentityProviderEntityId,
            IdentityProviderMetadataUrl = MetadataLocation,
            SigningCertificatePath = signingCertificatePath,
            SigningCertificatePassword = password,
        },
    };

    private static ServiceProvider Register(IamOptions iam)
    {
        var services = new ServiceCollection().AddLogging();
        services.AddIamAuthentication(iam, FixtureDirectory);
        return services.BuildServiceProvider();
    }

    private static async Task<IReadOnlyList<string>> SchemesFor(IamOptions iam)
    {
        await using var provider = Register(iam);
        var schemes = await provider.GetRequiredService<IAuthenticationSchemeProvider>().GetAllSchemesAsync();
        return [.. schemes.Select(scheme => scheme.Name)];
    }

    /// <summary>
    /// The status an anonymous page answers with on a real Kestrel host running the authentication
    /// middleware, which is where a handler's invalid options surface: every request handler is built on
    /// every request.
    /// </summary>
    private static async Task<HttpStatusCode> AnonymousPageStatus(IamOptions iam)
    {
        var builder = WebApplication.CreateEmptyBuilder(new WebApplicationOptions());
        builder.WebHost.UseKestrelCore().UseUrls("http://127.0.0.1:0");
        builder.Services.AddLogging().AddRouting();
        builder.Services.AddIamAuthentication(iam, FixtureDirectory);

        await using var app = builder.Build();
        app.UseRouting();
        app.UseAuthentication();
        app.MapGet(ManifestEndpoint.Path, () => Results.Ok());
        await app.StartAsync();

        using var client = new HttpClient { BaseAddress = new Uri(app.Urls.Single()) };
        using var response = await client.GetAsync(new Uri(ManifestEndpoint.Path, UriKind.Relative));
        await app.StopAsync();
        return response.StatusCode;
    }

    [Fact]
    public async Task A_saml_deployment_with_no_openid_connect_settings_serves_its_pages()
    {
        Assert.Equal(HttpStatusCode.OK, await AnonymousPageStatus(Saml()));
    }

    [Fact]
    public async Task A_saml_deployment_registers_no_openid_connect_handler()
    {
        var schemes = await SchemesFor(Saml());

        Assert.Contains(Saml2Defaults.Scheme, schemes);
        Assert.DoesNotContain(OpenIdConnectDefaults.AuthenticationScheme, schemes);
    }

    [Fact]
    public async Task An_openid_connect_deployment_registers_no_saml_handler()
    {
        var schemes = await SchemesFor(new IamOptions
        {
            Protocol = IamProtocol.Oidc,
            Authority = "https://idp.example.org",
            ClientId = "trusted-attestation",
        });

        Assert.Contains(OpenIdConnectDefaults.AuthenticationScheme, schemes);
        Assert.DoesNotContain(Saml2Defaults.Scheme, schemes);
    }

    [Fact]
    public void A_missing_signing_certificate_stops_start_up_and_names_the_file()
    {
        // Thrown while the services are registered, before the application is built or any request arrives.
        var refusal = Assert.Throws<InvalidOperationException>(() => Register(Saml("saml/absent.pfx")));

        Assert.Contains(Path.Combine(FixtureDirectory, "saml/absent.pfx"), refusal.Message, StringComparison.Ordinal);
        Assert.Contains("TrustedAttestation:Iam:Saml:SigningCertificatePath", refusal.Message, StringComparison.Ordinal);
    }

    [Fact]
    public void An_unreadable_signing_certificate_stops_start_up_and_names_the_file()
    {
        WriteFixture("not-a-certificate.pfx", "not a PKCS#12 file"u8.ToArray());

        var refusal = Assert.Throws<InvalidOperationException>(() => Register(Saml("not-a-certificate.pfx")));

        Assert.Contains(Path.Combine(FixtureDirectory, "not-a-certificate.pfx"), refusal.Message, StringComparison.Ordinal);
        Assert.Contains("TrustedAttestation:Iam:Saml:SigningCertificatePassword", refusal.Message, StringComparison.Ordinal);
    }

    [Fact]
    public void A_readable_signing_certificate_signs_the_requests()
    {
        using var certificate = SelfSignedCertificate();
        WriteFixture("signing.pfx", certificate.Export(X509ContentType.Pfx, "fixture-password"));

        using var provider = Register(Saml("signing.pfx", "fixture-password"));
        var options = provider.GetRequiredService<IOptionsMonitor<Saml2Options>>().Get(Saml2Defaults.Scheme);

        var signing = Assert.Single(options.SPOptions.ServiceCertificates);
        Assert.Equal(certificate.Thumbprint, signing.Certificate.Thumbprint);
        Assert.True(signing.Certificate.HasPrivateKey);
    }
}
