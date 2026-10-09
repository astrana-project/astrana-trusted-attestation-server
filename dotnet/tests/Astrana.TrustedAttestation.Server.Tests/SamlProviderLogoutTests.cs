using System.IO.Compression;
using System.Net;
using System.Security.Claims;
using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using System.Text;
using System.Text.RegularExpressions;
using Astrana.TrustedAttestation.Server.Configuration;
using Astrana.TrustedAttestation.Server.Security;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Authentication.Cookies;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Http;
using Microsoft.Extensions.DependencyInjection;
using Sustainsys.Saml2;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// A logout request the identity provider signs, sent when the member signs out of another service, ends the
/// member's session here only when its NameID names that member, and is answered with a signed logout response.
/// One naming anyone else, addressed anywhere but this server's single logout address, or past its NotOnOrAfter
/// time is answered with the Requester status and the session stays. One that is not signed, or is signed with
/// a key other than the identity provider's, is not answered at all. It runs on a real Kestrel host with the
/// real SAML handler, against an identity provider that advertises single logout, with the server holding its
/// signing certificate.
/// </summary>
public partial class SamlProviderLogoutTests
{
    private const string IdentityProviderEntityId = "https://idp.example.org/metadata";

    private const string SingleLogoutUrl = "https://idp.example.org/slo";

    private const string SignatureAlgorithm = "http://www.w3.org/2001/04/xmldsig-more#rsa-sha256";

    private const string CertificatePassword = "fixture-password";

    private const string Success = "urn:oasis:names:tc:SAML:2.0:status:Success";

    private const string Requester = "urn:oasis:names:tc:SAML:2.0:status:Requester";

    private const string LogoutPath = IamAuthentication.SamlModulePath + "/Logout";

    /// <summary>Signs a member in, so a test can send a logout request from a browser that holds a session.</summary>
    private const string TestSignInPath = "/test-sign-in";

    /// <summary>
    /// Where the fixture files are written: the test output folder, which every build replaces, so nothing
    /// is left outside it.
    /// </summary>
    private static readonly string FixtureDirectory =
        Directory.CreateDirectory(Path.Combine(AppContext.BaseDirectory, "saml-provider-logout-fixtures")).FullName;

    /// <summary>The identity provider's certificate, with the private key it signs its logout requests with.</summary>
    private static readonly X509Certificate2 IdentityProvider = SelfSignedCertificate("CN=idp.example.org");

    /// <summary>A certificate the identity provider's metadata does not name, whose key nobody should trust.</summary>
    private static readonly X509Certificate2 Stranger = SelfSignedCertificate("CN=stranger.example.org");

    /// <summary>The identity provider's metadata, advertising single logout, read from a local file.</summary>
    private static readonly string MetadataLocation = WriteFixture("idp-metadata.xml", Encoding.UTF8.GetBytes(
        $"""
        <EntityDescriptor xmlns="urn:oasis:names:tc:SAML:2.0:metadata" entityID="{IdentityProviderEntityId}">
          <IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
            <KeyDescriptor use="signing">
              <KeyInfo xmlns="http://www.w3.org/2000/09/xmldsig#">
                <X509Data>
                  <X509Certificate>{Convert.ToBase64String(IdentityProvider.RawData)}</X509Certificate>
                </X509Data>
              </KeyInfo>
            </KeyDescriptor>
            <SingleLogoutService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"
                                 Location="{SingleLogoutUrl}" />
            <SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"
                                 Location="https://idp.example.org/sso" />
          </IDPSSODescriptor>
        </EntityDescriptor>
        """));

    /// <summary>The server's own signing certificate, as a PKCS#12 file.</summary>
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

    private static IamOptions Saml() => new()
    {
        Protocol = IamProtocol.Saml,
        Authority = string.Empty,
        ClientId = string.Empty,
        Saml = new SamlOptions
        {
            EntityId = "https://ata.example.org/Saml2",
            IdentityProviderEntityId = IdentityProviderEntityId,
            IdentityProviderMetadataUrl = MetadataLocation,
            SigningCertificatePath = SigningCertificatePath,
            SigningCertificatePassword = CertificatePassword,
        },
    };

    private sealed record Answer(HttpStatusCode Status, Uri? Location, IReadOnlyList<string> Cookies)
    {
        public bool EndsSession =>
            Cookies.Any(cookie => cookie.StartsWith(SessionCookie.Name + "=;", StringComparison.Ordinal));
    }

    /// <summary>
    /// Sends one request to a real Kestrel host running the SAML handler, in the order the application runs
    /// it. The path is made from the host's own address. When <paramref name="signedInAs"/> names a member,
    /// that member is signed in first on the same host and the request carries the session cookie.
    /// </summary>
    private static async Task<Answer> Send(Func<Uri, string> path, string? signedInAs)
    {
        var builder = WebApplication.CreateEmptyBuilder(new WebApplicationOptions());
        builder.WebHost.UseKestrelCore().UseUrls("http://127.0.0.1:0");
        builder.Services.AddLogging().AddRouting();
        builder.Services.AddIamAuthentication(Saml(), FixtureDirectory);

        await using var app = builder.Build();
        app.UseRouting();
        app.UseSamlFailureHandling(IamAuthentication.SamlModulePath);
        app.UseSessionForProviderLogout(IamAuthentication.SamlModulePath);
        app.UseAuthentication();
        app.Map(TestSignInPath, branch => branch.Run(context => context.SignInAsync(
            CookieAuthenticationDefaults.AuthenticationScheme, Member(context.Request.Query["nameId"].ToString()))));
        await app.StartAsync();

        var server = new Uri(app.Urls.Single());
        using var client = new HttpClient(new HttpClientHandler { AllowAutoRedirect = false, UseCookies = false })
        {
            BaseAddress = server,
        };

        string? session = null;
        if (signedInAs is not null)
        {
            var signIn = await Get(client, TestSignInPath + "?nameId=" + Uri.EscapeDataString(signedInAs), null);
            session = signIn.Cookies
                .Single(cookie => cookie.StartsWith(SessionCookie.Name + "=", StringComparison.Ordinal))
                .Split(';')[0];
        }

        var answer = await Get(client, path(server), session);
        await app.StopAsync();
        return answer;
    }

    private static async Task<Answer> Get(HttpClient client, string path, string? session)
    {
        using var request = new HttpRequestMessage(HttpMethod.Get, new Uri(path, UriKind.Relative));
        if (session is not null)
        {
            request.Headers.Add("Cookie", session);
        }

        using var response = await client.SendAsync(request);
        IReadOnlyList<string> cookies = response.Headers.TryGetValues("Set-Cookie", out var values) ? [.. values] : [];
        return new Answer(response.StatusCode, response.Headers.Location, cookies);
    }

    /// <summary>
    /// A member signed in over SAML, carrying the claims the SAML handler gives a session: the NameID, and the
    /// NameID and session index single logout reads, issued by the identity provider.
    /// </summary>
    private static ClaimsPrincipal Member(string nameId) => new(new ClaimsIdentity(
        [
            new Claim(ClaimTypes.NameIdentifier, nameId, null, IdentityProviderEntityId),
            new Claim(Saml2ClaimTypes.LogoutNameIdentifier, ",,,," + nameId, null, IdentityProviderEntityId),
            new Claim(Saml2ClaimTypes.SessionIndex, "the-session-index", null, IdentityProviderEntityId),
        ],
        "test"));

    /// <summary>
    /// A logout request from the identity provider over the HTTP-Redirect binding, deflated, encoded and, unless
    /// <see cref="SignedBy"/> is null, signed over the query string with that certificate's key. By default it
    /// is addressed to the server's single logout address, carries no NotOnOrAfter time, is signed with the
    /// identity provider's key and is sent to the single logout address.
    /// </summary>
    private sealed record LogoutRequest(string NameId)
    {
        public string SessionIndex { get; init; } = "the-session-index";

        /// <summary>The Destination attribute, made from the server's address, or null to leave it out.</summary>
        public Func<Uri, string?> Destination { get; init; } = server => new Uri(server, LogoutPath).AbsoluteUri;

        public DateTime? NotOnOrAfter { get; init; }

        public X509Certificate2? SignedBy { get; init; } = IdentityProvider;

        public string SentTo { get; init; } = LogoutPath;

        public string PathOn(Uri server)
        {
            var destination = Destination(server);
            var xml =
                "<samlp:LogoutRequest xmlns:samlp=\"urn:oasis:names:tc:SAML:2.0:protocol\" "
                + "xmlns:saml=\"urn:oasis:names:tc:SAML:2.0:assertion\" "
                + $"ID=\"_{Guid.NewGuid():N}\" Version=\"2.0\" IssueInstant=\"{Timestamp(DateTime.UtcNow)}\""
                + (destination is null ? string.Empty : $" Destination=\"{destination}\"")
                + (NotOnOrAfter is { } notOnOrAfter ? $" NotOnOrAfter=\"{Timestamp(notOnOrAfter)}\"" : string.Empty)
                + ">"
                + $"<saml:Issuer>{IdentityProviderEntityId}</saml:Issuer>"
                + $"<saml:NameID>{NameId}</saml:NameID>"
                + $"<samlp:SessionIndex>{SessionIndex}</samlp:SessionIndex>"
                + "</samlp:LogoutRequest>";

            using var deflated = new MemoryStream();
            using (var deflate = new DeflateStream(deflated, CompressionLevel.Optimal))
            {
                deflate.Write(Encoding.UTF8.GetBytes(xml));
            }

            var query = "SAMLRequest=" + Uri.EscapeDataString(Convert.ToBase64String(deflated.ToArray()));
            if (SignedBy is null)
            {
                return SentTo + "?" + query;
            }

            query += "&SigAlg=" + Uri.EscapeDataString(SignatureAlgorithm);
            using var key = SignedBy.GetRSAPrivateKey()!;
            var signature = key.SignData(
                Encoding.UTF8.GetBytes(query), HashAlgorithmName.SHA256, RSASignaturePadding.Pkcs1);
            return SentTo + "?" + query + "&Signature=" + Uri.EscapeDataString(Convert.ToBase64String(signature));
        }

        private static string Timestamp(DateTime instant) => instant.ToString("yyyy-MM-ddTHH:mm:ssZ");
    }

    /// <summary>The status of the signed logout response the answer sends back to the identity provider.</summary>
    private static string AnsweredStatus(Answer answer)
    {
        var location = answer.Location?.AbsoluteUri;
        Assert.StartsWith(SingleLogoutUrl + "?SAMLResponse=", location, StringComparison.Ordinal);
        Assert.Contains("&Signature=", location, StringComparison.Ordinal);

        var encoded = Uri.UnescapeDataString(SamlResponseParameter().Match(location!).Groups[1].Value);
        using var inflate = new DeflateStream(
            new MemoryStream(Convert.FromBase64String(encoded)), CompressionMode.Decompress);
        using var reader = new StreamReader(inflate, Encoding.UTF8);
        return StatusCodeValue().Match(reader.ReadToEnd()).Groups[1].Value;
    }

    /// <summary>Whether the answer sends any logout response back to the identity provider.</summary>
    private static bool AnswersTheProvider(Answer answer) =>
        answer.Location?.OriginalString.StartsWith(SingleLogoutUrl, StringComparison.Ordinal) == true;

    [GeneratedRegex("[?&]SAMLResponse=([^&]+)")]
    private static partial Regex SamlResponseParameter();

    [GeneratedRegex("StatusCode Value=\"([^\"]+)\"")]
    private static partial Regex StatusCodeValue();

    [Fact]
    public async Task A_provider_logout_request_naming_the_signed_in_member_ends_the_session_and_is_answered()
    {
        var request = new LogoutRequest("member-a") { SessionIndex = "another-session-index" };

        var answer = await Send(request.PathOn, signedInAs: "member-a");

        Assert.True(answer.EndsSession);
        Assert.Equal(Success, AnsweredStatus(answer));
    }

    [Fact]
    public async Task A_provider_logout_request_naming_another_member_keeps_the_session_and_is_answered_requester()
    {
        var answer = await Send(new LogoutRequest("member-b").PathOn, signedInAs: "member-a");

        Assert.False(answer.EndsSession);
        Assert.Equal(Requester, AnsweredStatus(answer));
    }

    [Fact]
    public async Task A_provider_logout_request_with_no_session_is_answered()
    {
        var answer = await Send(new LogoutRequest("member-b").PathOn, signedInAs: null);

        Assert.Equal(Success, AnsweredStatus(answer));
    }

    [Fact]
    public async Task A_provider_logout_request_with_a_future_not_on_or_after_ends_the_session()
    {
        var request = new LogoutRequest("member-a") { NotOnOrAfter = DateTime.UtcNow.AddMinutes(5) };

        var answer = await Send(request.PathOn, signedInAs: "member-a");

        Assert.True(answer.EndsSession);
        Assert.Equal(Success, AnsweredStatus(answer));
    }

    [Fact]
    public async Task A_provider_logout_request_with_no_destination_keeps_the_session_and_is_answered_requester()
    {
        var request = new LogoutRequest("member-a") { Destination = _ => null };

        var answer = await Send(request.PathOn, signedInAs: "member-a");

        Assert.False(answer.EndsSession);
        Assert.Equal(Requester, AnsweredStatus(answer));
    }

    [Fact]
    public async Task A_provider_logout_request_for_another_address_keeps_the_session_and_is_answered_requester()
    {
        var request = new LogoutRequest("member-a") { Destination = _ => "https://elsewhere.example.org/Saml2/Logout" };

        var answer = await Send(request.PathOn, signedInAs: "member-a");

        Assert.False(answer.EndsSession);
        Assert.Equal(Requester, AnsweredStatus(answer));
    }

    [Fact]
    public async Task A_provider_logout_request_for_another_address_with_no_session_is_answered_requester()
    {
        var request = new LogoutRequest("member-a") { Destination = _ => "https://elsewhere.example.org/Saml2/Logout" };

        var answer = await Send(request.PathOn, signedInAs: null);

        Assert.Equal(Requester, AnsweredStatus(answer));
    }

    [Fact]
    public async Task A_provider_logout_request_past_its_not_on_or_after_keeps_the_session_and_is_answered_requester()
    {
        var request = new LogoutRequest("member-a") { NotOnOrAfter = DateTime.UtcNow.AddMinutes(-1) };

        var answer = await Send(request.PathOn, signedInAs: "member-a");

        Assert.False(answer.EndsSession);
        Assert.Equal(Requester, AnsweredStatus(answer));
    }

    [Fact]
    public async Task An_unsigned_provider_logout_request_keeps_the_session_and_is_not_answered()
    {
        var request = new LogoutRequest("member-a") { SignedBy = null };

        var answer = await Send(request.PathOn, signedInAs: "member-a");

        Assert.False(answer.EndsSession);
        Assert.Equal(SignInFailure.Path, answer.Location?.OriginalString);
    }

    [Fact]
    public async Task A_provider_logout_request_signed_with_another_key_keeps_the_session_and_is_not_answered()
    {
        var request = new LogoutRequest("member-a") { SignedBy = Stranger };

        var answer = await Send(request.PathOn, signedInAs: "member-a");

        Assert.False(answer.EndsSession);
        Assert.Equal(SignInFailure.Path, answer.Location?.OriginalString);
    }

    [Fact]
    public async Task A_provider_logout_request_sent_with_a_trailing_slash_keeps_the_session_and_is_not_answered()
    {
        var request = new LogoutRequest("member-a") { SentTo = LogoutPath + "/" };

        var answer = await Send(request.PathOn, signedInAs: "member-a");

        Assert.False(answer.EndsSession);
        Assert.False(AnswersTheProvider(answer));
        Assert.Equal(HttpStatusCode.NotFound, answer.Status);
    }

    [Fact]
    public async Task The_single_logout_address_without_a_logout_request_keeps_the_session()
    {
        var answer = await Send(_ => LogoutPath, signedInAs: "member-a");

        Assert.False(answer.EndsSession);
    }

    [Fact]
    public async Task A_provider_logout_request_sent_with_a_doubled_slash_is_checked_like_any_other()
    {
        var request = new LogoutRequest("member-b") { SentTo = IamAuthentication.SamlModulePath + "//Logout" };

        var answer = await Send(request.PathOn, signedInAs: "member-a");

        Assert.False(answer.EndsSession);
        Assert.Equal(Requester, AnsweredStatus(answer));
    }

    /// <summary>
    /// The paths taken as the single logout address are the ones the SAML handler runs its logout for: the module
    /// path as written, then the command in any case after any number of slashes, and nothing after it.
    /// </summary>
    [Theory]
    [InlineData("/Saml2/Logout", true)]
    [InlineData("/Saml2/logout", true)]
    [InlineData("/Saml2//Logout", true)]
    [InlineData("/Saml2/Logout/", false)]
    [InlineData("/Saml2/Logout/more", false)]
    [InlineData("/saml2/Logout", false)]
    [InlineData("/Saml2/Acs", false)]
    [InlineData("/Saml2", false)]
    public void The_single_logout_address_is_matched_as_the_handler_matches_it(string path, bool matches)
    {
        Assert.Equal(matches, SamlLogoutPath.Matches(new PathString(path), IamAuthentication.SamlModulePath));
    }
}
