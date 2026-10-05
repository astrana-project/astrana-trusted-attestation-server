using System.ComponentModel.DataAnnotations;
using Microsoft.Extensions.Options;

namespace Astrana.TrustedAttestation.Server.Configuration;

/// <summary>
/// Everything IT staff configure. Deployment is three things (decision records 1, 12 and 16 in docs/adr):
/// deploy the app, create an empty database, and set the database and IAM connection details.
/// The app creates its own schema on first run.
/// </summary>
public sealed class TrustedAttestationOptions : IValidatableObject
{
    public const string SectionName = "TrustedAttestation";

    /// <summary>
    /// The rules that depend on another setting, so they cannot be attributes. Run by the same data
    /// annotation validation at start as the attributes, and reported in the same form.
    /// </summary>
    public IEnumerable<ValidationResult> Validate(ValidationContext validationContext)
    {
        // There is no default client identifier, because only the identity provider's administrator knows
        // the one they registered. SAML has no client identifier.
        if (Iam.Protocol == IamProtocol.Oidc && string.IsNullOrWhiteSpace(Iam.ClientId))
        {
            yield return new ValidationResult(
                $"The ClientId field is required under OpenID Connect. Set {SectionName}:Iam:ClientId to the " +
                "client identifier registered with the identity provider.",
                [$"{nameof(Iam)}.{nameof(IamOptions.ClientId)}"]);
        }
    }

    // Data annotation validation does not look inside a nested object unless it carries
    // [ValidateObjectMembers], so without it the rules on the nested settings below would never run. Every
    // nested settings object carries it, so a rule added to one later is enforced without further wiring.
    // A rule that holds under one protocol only stays in Validate above, never an attribute here.

    [Required]
    [ValidateObjectMembers]
    public DatabaseOptions Database { get; set; } = new();

    [Required]
    [ValidateObjectMembers]
    public IamOptions Iam { get; set; } = new();

    [ValidateObjectMembers]
    public TlsOptions Tls { get; set; } = new();

    [ValidateObjectMembers]
    public AuditOptions Audit { get; set; } = new();

    [Required]
    [ValidateObjectMembers]
    public ManifestOptions Manifest { get; set; } = new();
}

public enum DatabaseProvider
{
    SqlServer,
    PostgreSql,
    MySql,
}

public sealed class DatabaseOptions
{
    public DatabaseProvider Provider { get; set; } = DatabaseProvider.PostgreSql;

    [Required(AllowEmptyStrings = false)]
    public string ConnectionString { get; set; } = string.Empty;

    /// <summary>
    /// Whether the application creates its own schema on first run (decision record 12 in docs/adr). Left on by
    /// default. An organisation whose database administrator applies <c>shared/schema/*.sql</c> by hand can
    /// turn it off, and the application then fails fast if the schema is missing rather than creating it.
    /// </summary>
    public bool CreateSchemaOnStartup { get; set; } = true;
}

/// <summary>Which protocol the org's IAM speaks. A deployment picks one; both are wired.</summary>
public enum IamProtocol
{
    Oidc,
    Saml,
}

public sealed class IamOptions
{
    /// <summary>
    /// OIDC or SAML. Astrana Trusted Attestation speaks the protocol, not the vendor, so this is the only
    /// thing that changes between an organisation on Entra ID and one on Active Directory Federation
    /// Services.
    /// </summary>
    public IamProtocol Protocol { get; set; } = IamProtocol.Oidc;

    /// <summary>OIDC issuer, e.g. <c>https://keycloak.example.org/realms/staff</c>. Unused under SAML.</summary>
    public string Authority { get; set; } = string.Empty;

    /// <summary>SAML settings. Unused under OIDC.</summary>
    [ValidateObjectMembers]
    public SamlOptions Saml { get; set; } = new();

    /// <summary>The client identifier registered with the identity provider. Required under OpenID Connect, with no default.</summary>
    public string ClientId { get; set; } = string.Empty;

    /// <summary>
    /// Required for a confidential client. Astrana Trusted Attestation uses Authorization Code with PKCE
    /// (decision record 22 in docs/adr), and PKCE does not replace client authentication for a server-side
    /// application that can keep a secret.
    /// </summary>
    public string ClientSecret { get; set; } = string.Empty;

    /// <summary>
    /// Claim carrying the member's <c>iam_subject_id</c>. OIDC <c>sub</c> by default.
    ///
    /// Under SAML this names a SAML attribute instead, and falls back to the assertion's NameID when no
    /// attribute of that name is present -- which is the usual case, since NameID is SAML's equivalent
    /// of <c>sub</c>.
    /// </summary>
    public string SubjectClaim { get; set; } = "sub";

    /// <summary>Claim carrying the member's display name, shown on the self-service page only.</summary>
    public string NameClaim { get; set; } = "name";

    /// <summary>Additional scopes to request beyond <c>openid</c>, <c>profile</c> and <c>email</c>.</summary>
    public string[] AdditionalScopes { get; set; } = [];

    /// <summary>
    /// Whether the IdP's discovery document must be fetched over HTTPS. Only ever turned off to talk to a
    /// local development IdP. Astrana Trusted Attestation's own TLS requirement is separate and cannot be
    /// turned off at all.
    /// </summary>
    public bool RequireHttpsMetadata { get; set; } = true;

    /// <summary>
    /// Whether to call the UserInfo endpoint. Needed when the IdP puts the subject, display name or locale
    /// claim there rather than in the ID token. No relationship is ever read from a claim.
    /// </summary>
    public bool GetClaimsFromUserInfoEndpoint { get; set; } = true;
}

public sealed class SamlOptions
{
    /// <summary>
    /// This service provider's entity ID: the identifier an IdP administrator registers. Must match what
    /// the IdP has on record exactly.
    /// </summary>
    public string EntityId { get; set; } = string.Empty;

    /// <summary>The IdP's entity ID, as published in its metadata.</summary>
    public string IdentityProviderEntityId { get; set; } = string.Empty;

    /// <summary>
    /// Where to fetch the IdP's metadata. Endpoints, bindings and the certificate assertions are verified
    /// against all come from there, so none of it is configured by hand.
    /// </summary>
    public string IdentityProviderMetadataUrl { get; set; } = string.Empty;

    /// <summary>
    /// PKCS#12 file holding this SP's signing keypair. Needed when the IdP requires signed AuthnRequests,
    /// which most do, and for single logout.
    /// </summary>
    public string? SigningCertificatePath { get; set; }

    public string? SigningCertificatePassword { get; set; }
}

public sealed class TlsOptions
{
    /// <summary>
    /// Set when a reverse proxy terminates TLS in front of the application, the normal shape for Kestrel
    /// behind IIS or Nginx. It has to be set explicitly. There is no auto-detection, and no default that
    /// lets an unconfigured instance serve plain HTTP (decision record 31 in docs/adr).
    /// </summary>
    public bool TerminatedByProxy { get; set; }

    /// <summary>
    /// Sends Strict-Transport-Security with one year and no subdomains (decision record 35 in docs/adr). An operator
    /// who sets a stronger policy at the proxy turns this off, so the browser sees one header and not two.
    /// </summary>
    public bool HstsEnabled { get; set; } = true;
}

public sealed class AuditOptions
{
    /// <summary>
    /// Whether the application runs its own daily prune (decision record 18 in docs/adr). Turning it off stops only
    /// the prune job, for an organisation that runs the prune procedure from its own scheduler. Audit
    /// writing cannot be turned off (decision record 17).
    /// </summary>
    public bool PruneEnabled { get; set; } = true;

    /// <summary>Default 5 years (decision record 17). Regulated organisations may have longer or shorter obligations.</summary>
    [Range(1, 36_500)]
    public int RetentionDays { get; set; } = 1825;

    /// <summary>Time of day, server time, at which the daily prune runs.</summary>
    public TimeOnly RunAt { get; set; } = new(2, 0);
}

public sealed class ManifestOptions
{
    public int ManifestVersion { get; set; } = 1;

    [Required(AllowEmptyStrings = false)]
    public string DefaultLocale { get; set; } = "en";

    /// <summary>
    /// The locales the organisation offers in the member-facing UI as a language switcher, ordered (the
    /// first is the org's primary). Empty means every locale the app ships strings for. Configured values
    /// with no shipped strings are ignored; the switcher appears only when more than one remains effective.
    /// </summary>
    public string[] SupportedLocales { get; set; } = [];

    public Dictionary<string, string> Name { get; set; } = [];

    public Dictionary<string, string> Description { get; set; } = [];

    public Dictionary<string, string> Website { get; set; } = [];

    /// <summary>
    /// Optional. The org's logo as a base64 data URI, embedded rather than linked, so a consumer of the
    /// manifest needs no second request to the org to render it. Not locale-keyed: a logo has no text.
    /// This is the light-mode and default logo -- the one rendered whenever no dark preference applies.
    /// </summary>
    public string? LogoData { get; set; }

    /// <summary>
    /// Optional dark-mode logo, in the same embedded form as <see cref="LogoData"/>. When set, a member
    /// whose device prefers a dark colour scheme sees this; everyone else sees <see cref="LogoData"/>. Only
    /// meaningful alongside a light logo, which stays the fallback the manifest and the page render when no
    /// scheme preference applies -- so a dark logo without a light one is a configuration error.
    /// </summary>
    public string? LogoDataDark { get; set; }

    public Dictionary<string, string> PrivacyNoticeUrl { get; set; } = [];

    /// <summary>
    /// Optional. Where a member who has been granted nothing yet is pointed for help -- a contact or support
    /// page. Locale-keyed like the other URLs. The empty-state message links to it, falling back to
    /// <see cref="Website"/> when it is unset, and to plain text when neither is set.
    /// </summary>
    public Dictionary<string, string> SupportUrl { get; set; } = [];

    /// <summary>
    /// Optional. ISO 3166-1 alpha-2 country codes, or ISO 3166-2 subdivision codes, for where the
    /// organisation operates or is registered.
    /// </summary>
    public string[] Jurisdictions { get; set; } = [];

    /// <summary>The subset of the governed enum this org actually issues.</summary>
    public string[] RelationshipTypes { get; set; } = [];

    [Required(AllowEmptyStrings = false)]
    public string EnrollmentUrl { get; set; } = string.Empty;

    [Required(AllowEmptyStrings = false)]
    public string AttestationUrl { get; set; } = string.Empty;
}
