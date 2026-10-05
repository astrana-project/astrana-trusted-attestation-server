using System.Globalization;
using Astrana.TrustedAttestation.Server.Contract;
using Astrana.TrustedAttestation.Server.Manifest;
using Astrana.TrustedAttestation.Server.Pages;
using Astrana.TrustedAttestation.Server.Services;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Mvc.RazorPages;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The public landing page's two decisions: whose name to show, and whether to confirm a sign-out.
///
/// The page is deliberately unauthenticated and makes no API calls, so there is not much logic -- but the
/// two pieces it has both matter. The org name is localized the way decision record 34 in docs/adr says a manifest
/// consumer must fall back (requested locale, then its language, then the manifest's declared default), and
/// getting that wrong shows the wrong-language name to a prospective member on the first page they see. The
/// signed-out marker is what makes "sign out" visibly work rather than dumping the member back onto a login
/// redirect that signs them straight back in.
/// </summary>
public class IndexModelTests
{
    private static ManifestDocument Manifest(string defaultLocale, params (string Locale, string Name)[] names) =>
        new()
        {
            ManifestVersion = 1,
            DefaultLocale = defaultLocale,
            Name = names.ToDictionary(n => n.Locale, n => n.Name),
            RelationshipTypes = [],
            EnrollmentUrl = "https://org.example/enrol",
            AttestationUrl = "https://org.example/attest",
        };

    private static IndexModel Model(ManifestDocument manifest, string path = "/")
    {
        var http = new DefaultHttpContext();
        http.Request.Path = path;
        return new IndexModel(UiStrings.Load(), manifest, Attribution.Load())
        {
            PageContext = new PageContext { HttpContext = http },
        };
    }

    private static void InCulture(string culture, Action body)
    {
        var original = CultureInfo.CurrentUICulture;
        try
        {
            CultureInfo.CurrentUICulture = new CultureInfo(culture);
            body();
        }
        finally
        {
            CultureInfo.CurrentUICulture = original;
        }
    }

    [Fact]
    public void The_org_name_uses_an_exact_locale_match_when_the_manifest_has_one()
    {
        var model = Model(Manifest("en", ("en", "Acme Inc"), ("fr", "Acme SARL")));

        InCulture("fr", () => model.OnGet());

        Assert.Equal("Acme SARL", model.OrgName);
    }

    [Fact]
    public void The_org_name_falls_back_from_a_region_to_its_bare_language()
    {
        // fr-CA is not in the manifest, but fr is: the region falls back to the language rather than
        // straight past it to the default, which is what the consumer fallback in decision record 34 requires.
        var model = Model(Manifest("en", ("en", "Acme Inc"), ("fr", "Acme SARL")));

        InCulture("fr-CA", () => model.OnGet());

        Assert.Equal("Acme SARL", model.OrgName);
    }

    [Fact]
    public void The_org_name_falls_back_to_the_declared_default_when_the_language_is_absent()
    {
        // German is offered nowhere, so the manifest's own default_locale decides -- not an empty string,
        // and not the first entry that happens to be in the dictionary.
        var model = Model(Manifest("en", ("en", "Acme Inc"), ("fr", "Acme SARL")));

        InCulture("de", () => model.OnGet());

        Assert.Equal("Acme Inc", model.OrgName);
    }

    [Fact]
    public void A_sign_out_is_confirmed_only_at_the_signed_out_address()
    {
        // The marker exists so the sign-out is visibly acknowledged. It must be off at the root, or the
        // landing page would claim every ordinary visitor had just signed out, and on at /signed-out, the
        // one address sign-out lands on in all three implementations.
        var manifest = Manifest("en", ("en", "Acme Inc"));

        var root = Model(manifest);
        root.OnGet();
        Assert.False(root.SignedOut);

        var signedOut = Model(manifest, IndexModel.SignedOutPath);
        signedOut.OnGet();
        Assert.True(signedOut.SignedOut);
    }
}
