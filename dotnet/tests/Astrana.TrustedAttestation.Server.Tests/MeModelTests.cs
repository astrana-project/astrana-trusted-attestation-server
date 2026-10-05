using System.Globalization;
using System.Security.Claims;
using Astrana.TrustedAttestation.Server.Configuration;
using Astrana.TrustedAttestation.Server.Contract;
using Astrana.TrustedAttestation.Server.Data;
using Astrana.TrustedAttestation.Server.Manifest;
using Astrana.TrustedAttestation.Server.Pages;
using Astrana.TrustedAttestation.Server.Security;
using Astrana.TrustedAttestation.Server.Services;
using Astrana.TrustedAttestation.Server.Tests.Support;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Mvc.RazorPages;
using Microsoft.Extensions.Options;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The self-service page's view-model assembly: turning the rows a member holds into what the view renders.
///
/// Each relationship is listed on its own -- independent key, independent standing, independently
/// removable -- so the page reduces every row to what the view needs, already localized and already
/// statused, in the order the read returns. The heavy security reasoning lives in the service and the
/// resolver; what this pins is the page's own share of it: that it keys the read on the resolved subject
/// alone (never a route or a claim it could be handed), that a revoked-but-keyed relationship keeps its
/// controls, and that an unresolvable member yields an empty page rather than a failure. The read itself
/// goes through the same RelationshipService the API uses -- faked here so no database is needed -- so this
/// page and the endpoint cannot drift on what "the relationships a member holds" means.
/// </summary>
public class MeModelTests
{
    private static readonly DateTimeOffset Now = new(2026, 8, 29, 12, 0, 0, TimeSpan.Zero);

    private static readonly byte[] Key = Enumerable.Range(1, PublicKey.Length).Select(i => (byte)i).ToArray();

    private readonly FakeRelationshipStore _store = new();

    private static ManifestDocument Manifest() => new()
    {
        ManifestVersion = 1,
        DefaultLocale = "en",
        Name = new Dictionary<string, string> { ["en"] = "Acme Inc" },
        RelationshipTypes = [],
        EnrollmentUrl = "https://org.example/enrol",
        AttestationUrl = "https://org.example/attest",
    };

    private MeModel Model(ClaimsPrincipal user, ManifestDocument? manifest = null)
    {
        var resolver = new MemberIdentityResolver(Options.Create(new TrustedAttestationOptions()));
        var service = new RelationshipService(
            _store, new ImmediateTransactionRunner(), new RecordingAuditWriter(), new RecordingSelfRevoke(),
            RelationshipTypeCatalog.Load());

        var model = new MeModel(
            resolver,
            RelationshipTypeCatalog.Load(),
            service,
            new FixedTimeProvider(Now),
            UiStrings.Load(),
            manifest ?? Manifest(),
            Attribution.Load());

        model.PageContext = new PageContext { HttpContext = new DefaultHttpContext { User = user } };

        return model;
    }

    private static ClaimsPrincipal SignedIn(string subject = "alice", string name = "Alice Anderson") =>
        new(new ClaimsIdentity([new Claim("sub", subject), new Claim("name", name)], "test"));

    private static MemberRelationship Relationship(string type, byte[]? key = null, DateTime? revokedAt = null) =>
        new()
        {
            IamSubjectId = "alice",
            RelationshipType = type,
            PublicKey = key,
            RevokedAt = revokedAt,
        };

    [Fact]
    public async Task It_reads_the_resolved_members_relationships_and_reduces_each_to_a_view_row()
    {
        // The read is keyed on the subject the resolver produced -- alice -- and never on anything the
        // request could carry. Each row the read returns becomes its localized label, its wire status, and
        // its base64 key (or null when unkeyed), in the order the read gave them.
        _store.Held =
        [
            Relationship("advisor", key: Key), // keyed, unrevoked -> active
            Relationship("employee"),          // unkeyed
        ];

        var model = Model(SignedIn());
        await model.OnGetAsync(default);

        Assert.Equal("alice", _store.ListedSubject);
        Assert.Equal("Alice Anderson", model.MemberName);

        Assert.Collection(model.Relationships,
            advisor =>
            {
                Assert.Equal("advisor", advisor.RelationshipType);
                Assert.Equal("Advisor", advisor.Label);
                Assert.Equal("active", advisor.Status);
                Assert.Equal(Convert.ToBase64String(Key), advisor.PublicKeyBase64);
                Assert.True(advisor.IsKeyed);
            },
            employee =>
            {
                Assert.Equal("employee", employee.RelationshipType);
                Assert.Equal("Employee", employee.Label);
                Assert.Equal("unkeyed", employee.Status);
                Assert.Null(employee.PublicKeyBase64);
                Assert.False(employee.IsKeyed);
            });
    }

    [Fact]
    public async Task The_expiry_is_shown_as_the_api_spells_it()
    {
        // ISO 8601 in UTC with a trailing Z and no fraction when there is none, the string the API sends,
        // so a member reading the page and a consumer reading the API see one spelling of one moment.
        var row = Relationship("employee", key: Key);
        row.ExpiresAt = new DateTime(2027, 3, 1, 9, 30, 0, DateTimeKind.Utc);
        _store.Held = [row];

        var model = Model(SignedIn());
        await model.OnGetAsync(default);

        Assert.Equal("2027-03-01T09:30:00Z", Assert.Single(model.Relationships).ExpiresAt);
    }

    [Fact]
    public async Task A_revoked_relationship_still_keeps_its_key_and_its_controls()
    {
        // The reason IsKeyed is not "is active": a revoked relationship reports revoked, but the member can
        // still rotate or remove its key -- only restoring it is the organisation's to do. Hiding the
        // controls would suggest the row was frozen when it is not.
        _store.Held = [Relationship("employee", key: Key, revokedAt: new DateTime(2026, 1, 1, 0, 0, 0, DateTimeKind.Utc))];

        var model = Model(SignedIn());
        await model.OnGetAsync(default);

        var row = Assert.Single(model.Relationships);
        Assert.Equal("revoked", row.Status);
        Assert.True(row.IsKeyed);
    }

    [Fact]
    public async Task The_org_name_is_localized_by_exact_locale_then_language_then_default()
    {
        // The consumer fallback decision record 34 in docs/adr sets, which the organisation's name follows. First the
        // exact locale, then its language, then the manifest's declared default. A member on a French page
        // sees the French name, one on a regional English variant still sees the English name, and one in an
        // untranslated language sees the default rather than a blank where the name should be.
        _store.Held = [];
        var bilingual = new ManifestDocument
        {
            ManifestVersion = 1,
            DefaultLocale = "en",
            Name = new Dictionary<string, string> { ["en"] = "Acme Inc", ["fr"] = "Acme SA" },
            RelationshipTypes = [],
            EnrollmentUrl = "https://org.example/enrol",
            AttestationUrl = "https://org.example/attest",
        };

        async Task<string> OrgNameFor(string culture)
        {
            var previous = CultureInfo.CurrentUICulture;
            CultureInfo.CurrentUICulture = CultureInfo.GetCultureInfo(culture);
            try
            {
                var model = Model(SignedIn(), bilingual);
                await model.OnGetAsync(default);
                return model.OrgName;
            }
            finally
            {
                CultureInfo.CurrentUICulture = previous;
            }
        }

        Assert.Equal("Acme SA", await OrgNameFor("fr"));      // exact match
        Assert.Equal("Acme Inc", await OrgNameFor("en-GB"));  // language fallback
        Assert.Equal("Acme Inc", await OrgNameFor("de"));     // default-locale fallback
    }

    [Fact]
    public async Task Without_a_resolvable_member_the_page_stays_empty_but_still_renders_the_org()
    {
        // [Authorize] guards the route; this is the defensive path if a session ever carries no usable
        // subject. It must not fall over and must not read any relationships -- there is no member to key
        // the read on -- so the member fields stay empty while the public org name is still set to render.
        _store.Held = [Relationship("employee")];

        // A multivalued subject resolves to no member, the same as an unauthenticated principal would.
        var ambiguous = new ClaimsPrincipal(new ClaimsIdentity(
            [new Claim("sub", "a"), new Claim("sub", "b")], "test"));

        var model = Model(ambiguous);
        await model.OnGetAsync(default);

        Assert.Empty(model.Relationships);
        Assert.Null(_store.ListedSubject);
        Assert.Equal(string.Empty, model.MemberName);
        Assert.Equal("Acme Inc", model.OrgName);
    }
}
