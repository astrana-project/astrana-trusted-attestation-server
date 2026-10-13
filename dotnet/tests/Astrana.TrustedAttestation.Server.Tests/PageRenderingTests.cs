using System.Net;
using System.Security.Claims;
using System.Text.Encodings.Web;
using Astrana.TrustedAttestation.Server.Configuration;
using Astrana.TrustedAttestation.Server.Contract;
using Astrana.TrustedAttestation.Server.Manifest;
using Astrana.TrustedAttestation.Server.Pages;
using Astrana.TrustedAttestation.Server.Security;
using Astrana.TrustedAttestation.Server.Services;
using Astrana.TrustedAttestation.Server.Tests.Support;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Hosting;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The pages as the real Razor files render them, served by Kestrel on a free loopback port, for what the
/// templates decide on their own. The page models are the server's, wired to the same fakes the model tests
/// use, so no database or identity provider is needed, and every request is signed in as alice.
/// </summary>
public class PageRenderingTests
{
    private const string SchemeName = "SignedInAsAlice";

    private static readonly RunningRelease Release = RunningRelease.OfThisBuild();

    /// <summary>Signs every request in as alice, so the self-service page renders rather than redirects.</summary>
    private sealed class SignedInAsAlice(
        IOptionsMonitor<AuthenticationSchemeOptions> options, ILoggerFactory logger, UrlEncoder encoder)
        : AuthenticationHandler<AuthenticationSchemeOptions>(options, logger, encoder)
    {
        protected override Task<AuthenticateResult> HandleAuthenticateAsync()
        {
            var identity = new ClaimsIdentity([new Claim("sub", "alice"), new Claim("name", "Alice")], SchemeName);
            return Task.FromResult(AuthenticateResult.Success(
                new AuthenticationTicket(new ClaimsPrincipal(identity), SchemeName)));
        }
    }

    private static ManifestDocument Manifest() => new()
    {
        ManifestVersion = 1,
        DefaultLocale = "en",
        Name = new Dictionary<string, string> { ["en"] = "Acme Inc" },
        RelationshipTypes = [],
        EnrollmentUrl = "https://org.example/enrol",
        AttestationUrl = "https://org.example/attest",
    };

    private static void AddPageServices(IServiceCollection services)
    {
        var strings = UiStrings.Load();
        services.AddSingleton(strings);
        services.AddSingleton(new LocalizationSettings(
            ["en"], strings.SupportedLocales, "en", new Dictionary<string, string> { ["en"] = "English" }));
        services.AddSingleton(Manifest());
        services.AddSingleton(Attribution.Load());
        services.AddSingleton(Release);
        services.AddSingleton(RelationshipTypeCatalog.Load());
        services.AddSingleton(TimeProvider.System);
        services.AddSingleton(new MemberIdentityResolver(Options.Create(new TrustedAttestationOptions())));
        services.AddSingleton(provider => new RelationshipService(
            new FakeRelationshipStore(), new ImmediateTransactionRunner(), new RecordingAuditWriter(),
            new RecordingSelfRevoke(), provider.GetRequiredService<RelationshipTypeCatalog>()));
    }

    /// <summary>Each path's status and body, from one server started for the call.</summary>
    private static async Task<Dictionary<string, (HttpStatusCode Status, string Body)>> Render(params string[] paths)
    {
        var builder = WebApplication.CreateEmptyBuilder(new WebApplicationOptions
        {
            // The server's own assembly, so that its compiled Razor pages are the ones found.
            ApplicationName = typeof(MeModel).Assembly.GetName().Name,
        });
        builder.WebHost.UseKestrelCore().UseUrls("http://127.0.0.1:0");
        builder.Services.AddLogging();
        builder.Services.AddRazorPages(options => options.Conventions.AddPageRoute("/Index", "/signed-out"));
        builder.Services.AddAuthentication(SchemeName)
            .AddScheme<AuthenticationSchemeOptions, SignedInAsAlice>(SchemeName, null);
        builder.Services.AddAuthorization();
        AddPageServices(builder.Services);

        await using var app = builder.Build();
        app.UseAuthentication();
        app.UseAuthorization();
        app.UseRequestLocalization("en");
        app.MapRazorPages();
        await app.StartAsync();

        using var client = new HttpClient { BaseAddress = new Uri(app.Urls.Single()) };
        var pages = new Dictionary<string, (HttpStatusCode, string)>();
        foreach (var path in paths)
        {
            using var response = await client.GetAsync(new Uri(path, UriKind.Relative));
            pages[path] = (response.StatusCode, await response.Content.ReadAsStringAsync());
        }

        await app.StopAsync();
        return pages;
    }

    private static string Footer(string page)
    {
        var start = page.IndexOf("<footer", StringComparison.Ordinal);
        var end = page.IndexOf("</footer>", start, StringComparison.Ordinal);
        return page[start..end];
    }

    [Fact]
    public async Task The_self_service_page_footer_links_the_running_version_to_the_releases_page()
    {
        // The one page that shows the running version, for the people who operate and support the server to
        // confirm which version is running (decision record 46 in docs/adr).
        var (status, body) = (await Render("/me"))["/me"];

        Assert.Equal(HttpStatusCode.OK, status);
        Assert.Contains(
            $"<a href=\"https://www.astrana.org/releases\" rel=\"noopener\" target=\"_blank\">v{Release.Version}</a>",
            Footer(body),
            StringComparison.Ordinal);
    }

    [Fact]
    public async Task The_public_pages_do_not_show_the_running_version()
    {
        foreach (var (path, (status, body)) in await Render("/", "/signed-out", "/license"))
        {
            Assert.True(status == HttpStatusCode.OK, $"{path} answered {status}");
            Assert.False(body.Contains(RunningRelease.ReleasesUrl, StringComparison.Ordinal), $"{path} links releases");
            Assert.False(body.Contains(Release.Version, StringComparison.Ordinal), $"{path} states the version");
        }
    }
}
