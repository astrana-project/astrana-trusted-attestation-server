using System.IO.Compression;
using System.Net;
using System.Security.Claims;
using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using System.Text;
using Astrana.TrustedAttestation.Server.Configuration;
using Astrana.TrustedAttestation.Server.Endpoints;
using Astrana.TrustedAttestation.Server.Security;
using Astrana.TrustedAttestation.Server.Tests.Support;
using Microsoft.AspNetCore.Antiforgery;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Hosting;
using Microsoft.Extensions.DependencyInjection;
using Sustainsys.Saml2;
using Sustainsys.Saml2.AspNetCore2;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// SAML single logout needs a signing certificate, in both directions. Without one the server offers no
/// single logout: its metadata advertises none, a member's sign-out stays local, and a logout request the
/// identity provider sends anyway is neither answered nor acted on, since the answer would have to be
/// signed. Each case runs on a real Kestrel host with the real SAML handler, against an identity provider
/// that advertises single logout, and each is paired with the same request made with a certificate, so a
/// test cannot pass because the request itself was wrong.
/// </summary>
public class SamlSingleLogoutTests
{
    private const string IdentityProviderEntityId = "https://idp.example.org/metadata";

    private const string SingleLogoutUrl = "https://idp.example.org/slo";

    private const string SignatureAlgorithm = "http://www.w3.org/2001/04/xmldsig-more#rsa-sha256";

    private const string CertificatePassword = "fixture-password";

    /// <summary>
    /// Where the fixture files are written: the test output folder, which every build replaces, so nothing
    /// is left outside it.
    /// </summary>
    private static readonly string FixtureDirectory =
        Directory.CreateDirectory(Path.Combine(AppContext.BaseDirectory, "saml-single-logout-fixtures")).FullName;

    /// <summary>The identity provider's certificate, with the private key it signs its logout requests with.</summary>
    private static readonly X509Certificate2 IdentityProvider = SelfSignedCertificate("CN=idp.example.org");

    /// <summary>The identity provider's metadata, advertising single logout, read from a local file.</summary>
    private static readonly string MetadataLocation = WriteFixture("idp-metadata.xml", Encoding.UTF8.GetBytes(
        $"""
        <EntityDescriptor xmlns="urn:oasis:names:tc:SAML:2.0:metadata" entityID="{IdentityProviderEntityId}">
          <IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
            <KeyDescriptor use="signing">
              <KeyInfo xmlns="http://www.w3.org/2000/09/xmldsig#">
                <X509Data><X509Certificate>{Convert.ToBase64String(IdentityProvider.RawData)}</X509Certificate></X509Data>
              </KeyInfo>
            </KeyDescriptor>
            <SingleLogoutService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="{SingleLogoutUrl}" />
            <SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"
                                 Location="https://idp.example.org/sso" />
          </IDPSSODescriptor>
        </EntityDescriptor>
        """));

    /// <summary>The server's own signing certificate, as a PKCS#12 file, for the deployments that have one.</summary>
    private static readonly string SigningCertificatePath = WriteSigningCertificate();

    private static X509Certificate2 SelfSignedCertificate(string subject)
    {
        using var key = RSA.Create(2048);
        var request = new CertificateRequest(subject, key, HashAlgorithmName.SHA256, RSASignaturePadding.Pkcs1);
        return request.CreateSelfSigned(DateTimeOffset.UtcNow.AddDays(-1), DateTimeOffset.UtcNow.AddDays(1));
    }

    private static string WriteFixture(string name, byte[] content)
    {
        var path = Path.Combine(FixtureDirectory, name);
        File.WriteAllBytes(path, content);
        return new Uri(path).AbsoluteUri;
    }

    private static string WriteSigningCertificate()
    {
        using var certificate = SelfSignedCertificate("CN=ata.example.org");
        var path = Path.Combine(FixtureDirectory, "signing.pfx");
        File.WriteAllBytes(path, certificate.Export(X509ContentType.Pfx, CertificatePassword));
        return path;
    }

    private static IamOptions Saml(bool withSigningCertificate) => new()
    {
        Protocol = IamProtocol.Saml,
        Authority = string.Empty,
        ClientId = string.Empty,
        Saml = new SamlOptions
        {
            EntityId = "https://ata.example.org/Saml2",
            IdentityProviderEntityId = IdentityProviderEntityId,
            IdentityProviderMetadataUrl = MetadataLocation,
            SigningCertificatePath = withSigningCertificate ? SigningCertificatePath : null,
            SigningCertificatePassword = withSigningCertificate ? CertificatePassword : null,
        },
    };

    /// <summary>
    /// What a request to the host answered: the status, where it redirects, the cookies it sets, its body and
    /// its content type.
    /// </summary>
    private sealed record Answer(
        HttpStatusCode Status, Uri? Location, IReadOnlyList<string> Cookies, string Body, string? ContentType)
    {
        /// <summary>Whether the answer deletes the session cookie, which is how the session here ends.</summary>
        public bool EndsTheSession => Cookies.Any(cookie =>
            cookie.StartsWith(SessionCookie.Name + "=;", StringComparison.Ordinal));
    }

    /// <summary>
    /// Sends one request to a real Kestrel host running the SAML handler and the sign-out endpoint, in the
    /// order the application runs them. The member is signed in over SAML, carrying the NameID and session
    /// index claims the handler keeps from the assertion, issued by the identity provider.
    /// </summary>
    private static async Task<Answer> Send(IamOptions iam, HttpMethod method, string path, HttpContent? content = null)
    {
        var builder = WebApplication.CreateEmptyBuilder(new WebApplicationOptions());
        builder.WebHost.UseKestrelCore().UseUrls("http://127.0.0.1:0");
        builder.Services.AddLogging().AddRouting();
        builder.Services.AddSingleton<IAntiforgery>(new FakeAntiforgery(valid: true));
        builder.Services.AddIamAuthentication(iam, FixtureDirectory);

        await using var app = builder.Build();
        app.UseRouting();
        app.UseSamlFailureHandling(IamAuthentication.SamlModulePath);
        app.UseSamlLogoutRequestRefusal(IamAuthentication.SamlModulePath);
        app.UseAuthentication();
        app.Use((http, next) =>
        {
            http.User = new ClaimsPrincipal(new ClaimsIdentity(
            [
                new Claim(Saml2ClaimTypes.LogoutNameIdentifier, ",,,,the-name-id", null, IdentityProviderEntityId),
                new Claim(Saml2ClaimTypes.SessionIndex, "the-session-index", null, IdentityProviderEntityId),
            ], Saml2Defaults.Scheme));
            return next(http);
        });
        app.MapSignOut(IamProtocol.Saml, Saml2Defaults.Scheme);
        await app.StartAsync();

        using var client = new HttpClient(new HttpClientHandler { AllowAutoRedirect = false, UseCookies = false })
        {
            BaseAddress = new Uri(app.Urls.Single()),
        };
        using var response = await client.SendAsync(
            new HttpRequestMessage(method, new Uri(path, UriKind.Relative)) { Content = content });
        var answer = new Answer(
            response.StatusCode,
            response.Headers.Location,
            response.Headers.TryGetValues("Set-Cookie", out var cookies) ? [.. cookies] : [],
            await response.Content.ReadAsStringAsync(),
            response.Content.Headers.ContentType?.ToString());
        await app.StopAsync();
        return answer;
    }

    /// <summary>A logout request from the identity provider, as it sends one when the member signs out of another service.</summary>
    private static string LogoutRequestXml() =>
        "<samlp:LogoutRequest xmlns:samlp=\"urn:oasis:names:tc:SAML:2.0:protocol\" "
        + "xmlns:saml=\"urn:oasis:names:tc:SAML:2.0:assertion\" "
        + $"ID=\"_{Guid.NewGuid():N}\" Version=\"2.0\" IssueInstant=\"{DateTime.UtcNow:yyyy-MM-ddTHH:mm:ssZ}\">"
        + $"<saml:Issuer>{IdentityProviderEntityId}</saml:Issuer>"
        + "<saml:NameID>the-name-id</saml:NameID>"
        + "<samlp:SessionIndex>the-session-index</samlp:SessionIndex>"
        + "</samlp:LogoutRequest>";

    /// <summary>
    /// The logout request over the HTTP-Redirect binding, deflated, encoded and signed over the query string
    /// with the identity provider's key.
    /// </summary>
    private static string SignedLogoutRequestPath()
    {
        var xml = LogoutRequestXml();

        using var deflated = new MemoryStream();
        using (var deflate = new DeflateStream(deflated, CompressionLevel.Optimal))
        {
            deflate.Write(Encoding.UTF8.GetBytes(xml));
        }

        var query = "SAMLRequest=" + Uri.EscapeDataString(Convert.ToBase64String(deflated.ToArray()))
            + "&SigAlg=" + Uri.EscapeDataString(SignatureAlgorithm);
        using var key = IdentityProvider.GetRSAPrivateKey()!;
        var signature = key.SignData(Encoding.UTF8.GetBytes(query), HashAlgorithmName.SHA256, RSASignaturePadding.Pkcs1);

        return IamAuthentication.SamlModulePath + "/Logout?" + query
            + "&Signature=" + Uri.EscapeDataString(Convert.ToBase64String(signature));
    }

    [Fact]
    public async Task Without_a_signing_certificate_sign_out_stays_local()
    {
        var answer = await Send(Saml(withSigningCertificate: false), HttpMethod.Post, SignOutEndpoint.Path);

        Assert.Equal(SignOutEndpoint.SignedOutPath, answer.Location?.OriginalString);
    }

    [Fact]
    public async Task With_a_signing_certificate_sign_out_goes_on_to_the_provider_single_logout()
    {
        var answer = await Send(Saml(withSigningCertificate: true), HttpMethod.Post, SignOutEndpoint.Path);

        Assert.StartsWith(SingleLogoutUrl + "?SAMLRequest=", answer.Location?.AbsoluteUri, StringComparison.Ordinal);
    }

    [Fact]
    public async Task Without_a_signing_certificate_the_metadata_advertises_no_single_logout()
    {
        var answer = await Send(Saml(withSigningCertificate: false), HttpMethod.Get, IamAuthentication.SamlModulePath);

        Assert.Equal(HttpStatusCode.OK, answer.Status);
        Assert.Contains("AssertionConsumerService", answer.Body, StringComparison.Ordinal);
        Assert.DoesNotContain("SingleLogoutService", answer.Body, StringComparison.Ordinal);
    }

    [Fact]
    public async Task With_a_signing_certificate_the_metadata_advertises_single_logout()
    {
        var answer = await Send(Saml(withSigningCertificate: true), HttpMethod.Get, IamAuthentication.SamlModulePath);

        Assert.Contains("SingleLogoutService", answer.Body, StringComparison.Ordinal);
    }

    [Fact]
    public async Task Without_a_signing_certificate_a_provider_logout_request_is_answered_as_absent()
    {
        // Single logout is not offered, so its address behaves as absent: HTTP 404 with no body and no
        // content type, and the member stays signed in. The same answer as the other two implementations.
        var answer = await Send(Saml(withSigningCertificate: false), HttpMethod.Get, SignedLogoutRequestPath());

        AssertAnsweredAsAbsent(answer);
    }

    [Fact]
    public async Task Without_a_signing_certificate_a_posted_provider_logout_request_is_answered_as_absent()
    {
        using var form = new FormUrlEncodedContent(new Dictionary<string, string>
        {
            ["SAMLRequest"] = Convert.ToBase64String(Encoding.UTF8.GetBytes(LogoutRequestXml())),
        });

        var answer = await Send(
            Saml(withSigningCertificate: false), HttpMethod.Post, IamAuthentication.SamlModulePath + "/Logout", form);

        AssertAnsweredAsAbsent(answer);
    }

    private static void AssertAnsweredAsAbsent(Answer answer)
    {
        Assert.Equal(HttpStatusCode.NotFound, answer.Status);
        Assert.Equal(string.Empty, answer.Body);
        Assert.Null(answer.ContentType);
        Assert.Null(answer.Location);
        Assert.False(answer.EndsTheSession, "the session was ended");
    }

    [Fact]
    public async Task With_a_signing_certificate_the_same_logout_request_is_processed_without_an_error()
    {
        // The pair to the tests above: the same request is accepted once there is a certificate, so the
        // refusal there comes from the missing certificate and not from the request.
        var answer = await Send(Saml(withSigningCertificate: true), HttpMethod.Get, SignedLogoutRequestPath());

        Assert.Equal(HttpStatusCode.SeeOther, answer.Status);
    }
}
