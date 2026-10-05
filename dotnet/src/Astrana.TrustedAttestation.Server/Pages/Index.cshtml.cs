using System.Globalization;
using Astrana.TrustedAttestation.Server.Contract;
using Astrana.TrustedAttestation.Server.Manifest;
using Astrana.TrustedAttestation.Server.Services;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc.RazorPages;

namespace Astrana.TrustedAttestation.Server.Pages;

/// <summary>
/// The public landing page: what this instance is, whose it is, and where to sign in.
///
/// Deliberately unauthenticated and deliberately indexable — this is the page the manifest's
/// <c>enrollment_url</c> points at, so it is the first thing a prospective member sees, before they have
/// any session to show. It makes no API calls and reveals nothing member-specific: everything on it is
/// already public through the manifest.
///
/// Also where a member lands after signing out, with a confirmation — so "sign out" visibly worked,
/// rather than dumping them back onto a login redirect that signs them straight back in.
/// </summary>
[AllowAnonymous]
public sealed class IndexModel(
    UiStrings strings,
    ManifestDocument manifest,
    Attribution attribution) : PageModel
{
    public string OrgName { get; private set; } = string.Empty;

    public string? OrgLogo => manifest.LogoData;

    public string? OrgLogoDark => manifest.LogoDataDark;

    public Attribution Attribution => attribution;

    public UiStrings Strings => strings;

    /// <summary>The address sign-out lands on, the same in all three implementations.</summary>
    public const string SignedOutPath = "/signed-out";

    /// <summary>True when the page was reached at <see cref="SignedOutPath"/>. A marker, not data: the address is the confirmation.</summary>
    public bool SignedOut { get; private set; }

    public void OnGet()
    {
        SignedOut = HttpContext.Request.Path.Equals(SignedOutPath, StringComparison.OrdinalIgnoreCase);

        var locale = CultureInfo.CurrentUICulture.Name;
        OrgName = Localized(manifest.Name, locale, manifest.DefaultLocale);
    }

    private static string Localized(
        IReadOnlyDictionary<string, string> values, string locale, string defaultLocale)
    {
        if (values.TryGetValue(locale, out var exact))
        {
            return exact;
        }

        var separator = locale.IndexOf('-');
        if (separator > 0 && values.TryGetValue(locale[..separator], out var language))
        {
            return language;
        }

        return values.TryGetValue(defaultLocale, out var fallback) ? fallback : string.Empty;
    }
}
