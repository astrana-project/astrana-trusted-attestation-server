using System.Globalization;
using Astrana.TrustedAttestation.Server.Contract;
using Astrana.TrustedAttestation.Server.Data;
using Astrana.TrustedAttestation.Server.Manifest;
using Astrana.TrustedAttestation.Server.Security;
using Astrana.TrustedAttestation.Server.Services;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc.RazorPages;

namespace Astrana.TrustedAttestation.Server.Pages;

/// <summary>
/// One relationship as the page needs to show it: already localized, already formatted, with no work
/// left for the view to do beyond rendering.
/// </summary>
/// <param name="RelationshipType">The wire value, used to build this row's own action URLs.</param>
/// <param name="Label">The governed type's label in the member's language.</param>
/// <param name="Subtype">Ungoverned free text, shown as given.</param>
/// <param name="Status">The wire status: unkeyed, active, revoked or expired.</param>
/// <param name="ExpiresAt">The expiry as the API spells it (see <see cref="WireTimestamp"/>), or null when there is none.</param>
public sealed record RelationshipView(
    string RelationshipType,
    string Label,
    string? Subtype,
    string Status,
    string? PublicKeyBase64,
    string? ExpiresAt)
{
    /// <summary>
    /// Whether the member can still act on this relationship from the page.
    ///
    /// A revoked relationship keeps its controls: the member can still rotate its key or remove it
    /// outright, and only restoring it is beyond them. Hiding the controls would suggest the row was
    /// frozen, when what is actually true is that the organisation has to be the one to lift it.
    /// </summary>
    public bool IsKeyed => PublicKeyBase64 is not null;
}

/// <summary>
/// The self-service page, for members only, beside the public landing and licence pages.
///
/// The signed-in member sees their own basic information, so they know they are signed in as themselves,
/// and every relationship they hold, each with its own key and its own controls. There is no
/// administrator or staff interface anywhere in Astrana Trusted Attestation. Standing is changed through
/// stored procedures instead, so there is no extra sign-in path and no extra endpoints for an attacker to
/// target.
///
/// Each relationship is listed separately rather than merged into one summary, because they really are
/// separate: independent keys, independent standing, independently removable. A page that showed a
/// single combined state would be describing something the data model does not have.
/// </summary>
[Authorize]
public sealed class MeModel(
    MemberIdentityResolver resolver,
    RelationshipTypeCatalog catalog,
    RelationshipService relationships,
    TimeProvider timeProvider,
    UiStrings strings,
    ManifestDocument manifest,
    Attribution attribution) : PageModel
{
    /// <summary>
    /// The organisation's own name, in the member's language where the manifest offers it.
    ///
    /// Branding is read from the manifest rather than from a separate branding setting: it is the same
    /// content the organisation already maintains for discovery, so there is nothing extra to configure
    /// and no second place for the organisation's name to be wrong.
    /// </summary>
    public string OrgName { get; private set; } = string.Empty;

    /// <summary>The organisation's logo as a data URI, or null when it has not set one.</summary>
    public string? OrgLogo => manifest.LogoData;

    /// <summary>The organisation's dark-mode logo as a data URI, or null when it has not set one.</summary>
    public string? OrgLogoDark => manifest.LogoDataDark;

    /// <summary>
    /// Where a member who holds no relationship yet is pointed for help: the manifest's support URL if set,
    /// otherwise its website, otherwise null (the empty-state message then names the org as plain text).
    /// Resolved for the member's locale, the same way the org name is.
    /// </summary>
    public string? ContactUrl { get; private set; }

    /// <summary>Fixed, and not organisation-configurable. See <see cref="Contract.Attribution"/>.</summary>
    public Attribution Attribution => attribution;

    public string MemberName { get; private set; } = string.Empty;

    /// <summary>
    /// Every relationship the member holds, in a stable order.
    ///
    /// Empty is an ordinary state, not an error: it is what a member looks like before the organisation
    /// has granted them anything. The page says so plainly rather than offering a key field that could
    /// not succeed.
    /// </summary>
    public IReadOnlyList<RelationshipView> Relationships { get; private set; } = [];

    public UiStrings Strings => strings;

    public IReadOnlyDictionary<string, string> LocalizedStrings { get; private set; } =
        new Dictionary<string, string>();

    /// <summary>
    /// Picks a locale-keyed manifest value, falling back the way decision record 34 in docs/adr says a consumer
    /// should: the requested locale, then its language, then the manifest's own declared default.
    /// </summary>
    private static string LocalizedFromManifest(
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

    public async Task OnGetAsync(CancellationToken cancellationToken)
    {
        var locale = CultureInfo.CurrentUICulture.Name;
        LocalizedStrings = strings.All(locale);
        OrgName = LocalizedFromManifest(manifest.Name, locale, manifest.DefaultLocale);

        var support = manifest.SupportUrl is null
            ? string.Empty
            : LocalizedFromManifest(manifest.SupportUrl, locale, manifest.DefaultLocale);
        var website = manifest.Website is null
            ? string.Empty
            : LocalizedFromManifest(manifest.Website, locale, manifest.DefaultLocale);
        ContactUrl = new[] { support, website }.FirstOrDefault(url => !string.IsNullOrWhiteSpace(url));

        if (!resolver.TryResolve(User, out var member))
        {
            return;
        }

        MemberName = member.Name;

        // The same read the API's /me uses, through the same service: one place decides what "the
        // relationships a member holds" means and in what order, rather than this page and the endpoint
        // each spelling the query out and risking drift.
        var rows = await relationships.FindAllHeldByAsync(member.IamSubjectId, cancellationToken);

        var now = timeProvider.GetUtcNow().UtcDateTime;

        Relationships =
        [
            .. rows.Select(row => new RelationshipView(
                row.RelationshipType,
                catalog.Label(row.RelationshipType, locale),
                row.RelationshipSubtype,
                row.StatusAt(now).ToWireValue(),
                row.PublicKey is null ? null : PublicKey.ToBase64(row.PublicKey),
                WireTimestamp.Format(row.ExpiresAt)))
        ];
    }
}
