using System.Globalization;
using System.Net;
using System.Security.Claims;
using System.Text.RegularExpressions;
using Astrana.TrustedAttestation.Server.Configuration;
using Astrana.TrustedAttestation.Server.Contract;
using Astrana.TrustedAttestation.Server.Data;
using Astrana.TrustedAttestation.Server.Manifest;
using Astrana.TrustedAttestation.Server.Pages;
using Astrana.TrustedAttestation.Server.Security;
using Astrana.TrustedAttestation.Server.Services;
using Astrana.TrustedAttestation.Server.Tests.Support;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Localization;
using Microsoft.AspNetCore.TestHost;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Options;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The self-service page as the member receives it: the compiled Razor page rendered through the framework,
/// with the relationship read faked so no database or identity provider is needed. The view-model tests pin
/// what the page is handed. These pin what the markup does with it.
/// </summary>
public partial class MePageRenderingTests
{
    private const string OrgName = "Acme Inc";

    private static readonly DateTimeOffset Now = new(2026, 8, 29, 12, 0, 0, TimeSpan.Zero);

    [Fact]
    public async Task The_expired_paragraph_names_the_organisation()
    {
        // The page's script carries the same sentence with the name already in it, so the script is left
        // out of the search: the paragraph the member reads has to name the organisation by itself.
        var expired = new MemberRelationship
        {
            IamSubjectId = "alice",
            RelationshipType = "employee",
            PublicKey = Enumerable.Range(1, PublicKey.Length).Select(i => (byte)i).ToArray(),
            ExpiresAt = new DateTime(2026, 1, 1, 0, 0, 0, DateTimeKind.Utc),
        };

        var page = await RenderAsync(expired);

        var expected = UiStrings.Load().Get("expired_help", "en").Replace("{org}", OrgName);
        Assert.Contains(expected, page);
        Assert.DoesNotContain("{org}", page);
    }

    /// <summary>The page at /me for a signed-in member holding <paramref name="held"/>, as the text a reader
    /// sees: script elements removed and character references resolved.</summary>
    private static async Task<string> RenderAsync(params MemberRelationship[] held)
    {
        await using var app = Build(new FakeRelationshipStore { Held = held });
        await app.StartAsync();

        var markup = await app.GetTestClient().GetStringAsync("/me");

        return WebUtility.HtmlDecode(ScriptElement().Replace(markup, string.Empty));
    }

    private static WebApplication Build(FakeRelationshipStore store)
    {
        var builder = WebApplication.CreateBuilder(new WebApplicationOptions
        {
            ApplicationName = typeof(MeModel).Assembly.GetName().Name,
        });
        builder.WebHost.UseTestServer();

        var uiStrings = UiStrings.Load();
        var endonyms = uiStrings.SupportedLocales
            .ToDictionary(locale => locale, locale => uiStrings.Get("language_endonym", locale));
        var catalog = RelationshipTypeCatalog.Load();

        builder.Services.AddRazorPages();
        builder.Services.AddAuthentication();
        builder.Services.AddAuthorization();
        builder.Services.AddSingleton(uiStrings);
        builder.Services.AddSingleton(new LocalizationSettings(["en"], uiStrings.SupportedLocales, "en", endonyms));
        builder.Services.AddSingleton(catalog);
        builder.Services.AddSingleton(Attribution.Load());
        builder.Services.AddSingleton<TimeProvider>(new FixedTimeProvider(Now));
        builder.Services.AddSingleton(new ManifestDocument
        {
            ManifestVersion = 1,
            DefaultLocale = "en",
            Name = new Dictionary<string, string> { ["en"] = OrgName },
            RelationshipTypes = [],
            EnrollmentUrl = "https://org.example/enrol",
            AttestationUrl = "https://org.example/attest",
        });
        builder.Services.AddSingleton(new RunningRelease("1.2.3"));
        builder.Services.AddSingleton(new MemberIdentityResolver(Options.Create(new TrustedAttestationOptions())));
        builder.Services.AddSingleton(new RelationshipService(
            store, new ImmediateTransactionRunner(), new RecordingAuditWriter(), new RecordingSelfRevoke(), catalog));

        var app = builder.Build();

        app.UseRequestLocalization(new RequestLocalizationOptions
        {
            DefaultRequestCulture = new RequestCulture("en"),
            SupportedCultures = [new CultureInfo("en")],
            SupportedUICultures = [new CultureInfo("en")],
            RequestCultureProviders = [],
        });
        app.Use((context, next) =>
        {
            context.User = new ClaimsPrincipal(new ClaimsIdentity(
                [new Claim("sub", "alice"), new Claim("name", "Alice Anderson")], "test"));
            return next(context);
        });
        app.UseAuthorization();
        app.MapRazorPages();

        return app;
    }

    [GeneratedRegex("<script\\b[^>]*>.*?</script\\s*>", RegexOptions.Singleline | RegexOptions.IgnoreCase)]
    private static partial Regex ScriptElement();
}
