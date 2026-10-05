using System.Security.Authentication;
using Astrana.TrustedAttestation.Server.Configuration;
using Astrana.TrustedAttestation.Server.Contract;
using Astrana.TrustedAttestation.Server.Data;
using Astrana.TrustedAttestation.Server.Endpoints;
using Astrana.TrustedAttestation.Server.Manifest;
using Astrana.TrustedAttestation.Server.Security;
using Astrana.TrustedAttestation.Server.Services;
using Microsoft.AspNetCore.Authentication.Cookies;
using Microsoft.AspNetCore.Authentication.OpenIdConnect;
using Microsoft.AspNetCore.HttpOverrides;
using Microsoft.Extensions.Options;

// The locales this app ships strings for, read from the shared strings file rather than hardcoded here
// -- so the offered set and the translated set are one list from one source, the same source the other
// two implementations now read. The loaded instance is reused as the registered singleton below.
var uiStrings = UiStrings.Load();

var builder = WebApplication.CreateBuilder(args);

// When Kestrel terminates TLS itself, serve TLS 1.2+ only. Kestrel's default follows the host OS, which on
// some machines still negotiates TLS 1.0/1.1 -- a DAST run (Nuclei) found exactly that here, while the Java
// (Tomcat) and PHP (proxy) implementations already refuse the deprecated versions. Pinning the floor keeps
// this stack consistent with them and independent of host configuration. No effect when TLS is terminated
// by a reverse proxy, where Kestrel serves plain HTTP behind it.
builder.WebHost.ConfigureKestrel(kestrel =>
{
    SecurityHeaders.ConfigureServerHeader(kestrel);
    kestrel.ConfigureHttpsDefaults(https =>
        https.SslProtocols = SslProtocols.Tls12 | SslProtocols.Tls13);
});

// Bound eagerly as well as registered, because authentication and the database provider have to be wired
// up before the container exists.
var settings = builder.Configuration.GetSection(TrustedAttestationOptions.SectionName).Get<TrustedAttestationOptions>()
          ?? throw new InvalidOperationException(
              $"No '{TrustedAttestationOptions.SectionName}' configuration section found. See appsettings.json.");

// The locales this instance offers: the org's configured set intersected with the locales the app ships
// strings for (empty config = all shipped). Shared by the request pipeline below and the language switcher.
// Endonyms drive the default alphabetical order, so a speaker finds their language by its own native name.
var endonyms = uiStrings.SupportedLocales.ToDictionary(locale => locale, locale => uiStrings.Get("language_endonym", locale));
var localization = new LocalizationSettings(
    settings.Manifest.SupportedLocales, uiStrings.SupportedLocales, settings.Manifest.DefaultLocale, endonyms);
builder.Services.AddSingleton(localization);

builder.Services
    .AddOptions<TrustedAttestationOptions>()
    .Bind(builder.Configuration.GetSection(TrustedAttestationOptions.SectionName))
    .ValidateDataAnnotations()
    .ValidateOnStart();

// ---------------------------------------------------------------------------------------------------
// Contract
// ---------------------------------------------------------------------------------------------------

// Loaded once from the embedded contract file. Failing here means the build did not embed it, which is a
// packaging fault, not a runtime condition to degrade around.
builder.Services.AddSingleton(RelationshipTypeCatalog.Load());

// The fixed footer. Not in configuration at all: decision record 33 in docs/adr requires it on every deployment
// and puts it beyond the organisation's reach, so there is deliberately no setting that could remove it.
builder.Services.AddSingleton(Attribution.Load());
builder.Services.AddSingleton(TimeProvider.System);

// The manifest is validated as the container builds it, so an instance with a broken manifest never
// reaches the point of serving one. Same reasoning as the TLS check: better found at boot than by a
// verifying peer whose lookup fails.
builder.Services.AddSingleton(serviceProvider =>
{
    var options = serviceProvider.GetRequiredService<IOptions<TrustedAttestationOptions>>().Value.Manifest;
    var catalog = serviceProvider.GetRequiredService<RelationshipTypeCatalog>();
    var manifest = ManifestDocument.From(
        options,
        LogoData.Resolve(options.LogoData, builder.Environment.ContentRootPath, LogoData.LightSetting),
        LogoData.Resolve(options.LogoDataDark, builder.Environment.ContentRootPath, LogoData.DarkSetting));

    var errors = ManifestValidator.Validate(manifest, catalog);
    if (errors.Count > 0)
    {
        throw new InvalidOperationException(
            $"The configured manifest ({ManifestEndpoint.Path}) is invalid:{Environment.NewLine}  - " +
            string.Join($"{Environment.NewLine}  - ", errors));
    }

    return manifest;
});

// ---------------------------------------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------------------------------------

// One codebase, three engines. See TrustedAttestationDbContext.UseConfiguredEngine.
builder.Services.AddDbContext<TrustedAttestationDbContext>(options =>
    TrustedAttestationDbContext.UseConfiguredEngine(options, settings.Database));

builder.Services.AddScoped<IAuditWriter, AuditWriter>();

// Scoped, because it runs on the request's own DbContext -- the self-revoke has to be visible
// to the same connection that just checked the relationship exists.
builder.Services.AddScoped<ISelfRevokeCommand, SelfRevokeCommand>();
builder.Services.AddScoped<SchemaInitializer>();

// The write path: its decisions live in RelationshipService, off the database and the HTTP layer, over a
// store and a transaction runner that the Entity Framework implementations satisfy at run time and
// hand-written fakes satisfy under test. All scoped -- they share the request's own DbContext.
builder.Services.AddScoped<IRelationshipStore, EfRelationshipStore>();
builder.Services.AddScoped<ITransactionRunner, EfTransactionRunner>();
builder.Services.AddScoped<RelationshipService>();
builder.Services.AddSingleton<MemberIdentityResolver>();
builder.Services.AddHostedService<AuditPruneService>();

// ---------------------------------------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------------------------------------

// The session cookie and the sign-in handler for the configured protocol, and no handler for the other one.
// Under SAML the signing certificate is read here, so a missing or unreadable one stops start-up. See
// IamAuthentication.
var challengeScheme = IamAuthentication.ChallengeScheme(settings.Iam.Protocol);
builder.Services.AddIamAuthentication(settings.Iam, builder.Environment.ContentRootPath);

builder.Services.AddAuthorizationBuilder()
    .AddPolicy(ApiEndpoints.PolicyName, policy => policy
        .AddAuthenticationSchemes(CookieAuthenticationDefaults.AuthenticationScheme)
        .RequireAuthenticatedUser());

// ---------------------------------------------------------------------------------------------------
// The pages
// ---------------------------------------------------------------------------------------------------

// The landing page also answers at /signed-out, the fixed address sign-out lands on in all three
// implementations, where it carries the signed-out confirmation.
builder.Services.AddRazorPages(options => options.Conventions.AddPageRoute("/Index", "/signed-out"));
builder.Services.AddAntiforgery(ApiEndpoints.ConfigureAntiforgery);
builder.Services.AddSingleton(uiStrings);
builder.Services.AddHsts(SecurityHeaders.ConfigureHsts);

// The language switcher's cookie, then the identity system's locale claim, then Accept-Language. See
// RequestLocalization.
builder.Services.Configure<RequestLocalizationOptions>(options => RequestLocalization.Configure(options, localization));

if (settings.Tls.TerminatedByProxy)
{
    builder.Services.Configure<ForwardedHeadersOptions>(TlsRequirement.ConfigureForwardedHeaders);
}

var app = builder.Build();

// ---------------------------------------------------------------------------------------------------
// Startup checks -- fail rather than start wrong
// ---------------------------------------------------------------------------------------------------

var startupLogger = app.Services.GetRequiredService<ILoggerFactory>().CreateLogger("TrustedAttestation.Startup");

TlsRequirement.Enforce(app.Configuration, settings.Tls, startupLogger);

// Building the manifest validates it. Resolved here so an invalid one stops startup rather than surfacing
// on the first request for it.
_ = app.Services.GetRequiredService<ManifestDocument>();

// The IdP, for the same reason. The OpenID Connect handler would otherwise fetch its discovery document
// on the first challenge, so an unreachable authority lets this instance start cleanly and serve every
// anonymous path while being incapable of signing anybody in -- healthy to every signal an operator has,
// and broken for members one at a time.
//
// SAML is left to its own handler: its metadata is fetched by a different route, and the deployment that
// speaks it has no discovery document to read.
if (settings.Iam.Protocol == IamProtocol.Oidc)
{
    var oidc = app.Services
        .GetRequiredService<IOptionsMonitor<OpenIdConnectOptions>>()
        .Get(OpenIdConnectDefaults.AuthenticationScheme);

    await IamRequirement.AssertReachableAsync(
        token => oidc.ConfigurationManager!.GetConfigurationAsync(token),
        settings.Iam.Authority,
        CancellationToken.None);
}

using (var scope = app.Services.CreateScope())
{
    await scope.ServiceProvider.GetRequiredService<SchemaInitializer>().InitializeAsync();
}

// ---------------------------------------------------------------------------------------------------
// Pipeline
// ---------------------------------------------------------------------------------------------------

// First, so it wraps everything: an unhandled exception anywhere below answers HTTP 500 with the security
// headers and no body, in every environment. The developer exception page the framework adds in
// Development sits outside this and never sees an exception, so no stack trace reaches a response.
app.UseStatusOnlyErrors();

// The security headers, behind a proxy the forwarded-scheme guard and the forwarded headers, and then
// Strict-Transport-Security, so every response any later middleware produces carries all of them.
app.UseSecurityHeadersAndSchemeGuard(settings.Tls);

// The stylesheets and the favicon, served before authentication runs so the sign-in redirect and any
// error along the way are styled too. The only other file under wwwroot is THIRD-PARTY-NOTICES.txt, which
// the licence page links to and the build writes. Placed after UseSecurityHeaders,
// which sets its headers before calling next(), so serving an asset from inside that next() carries the
// same nosniff, frame, referrer and cache headers a dynamic response gets, and after the HSTS middleware
// for the same reason. Java's Spring Security applies its header writers to static assets too, so all
// three implementations send the same headers on the stylesheet and favicon. Still ahead of
// UseAuthentication, so the assets stay anonymous.
app.UseStaticFiles();

// Ahead of UseAuthentication, so it wraps the handler that processes the assertion.
if (settings.Iam.Protocol == IamProtocol.Saml)
{
    app.UseSamlFailureHandling(IamAuthentication.SamlModulePath);
}

// JSON goes out as a bare "application/json", as the other two implementations send it. See JsonContentType.
app.UseBareJsonContentType();

app.UseAuthentication();
app.UseAuthorization();

// After authentication, so the request's own culture can be resolved from the member's IAM locale claim:
// ClaimsRequestCultureProvider reads HttpContext.User, which UseAuthentication is what populates. Before
// it, User is anonymous, the locale claim is never seen, and language silently falls back to
// Accept-Language -- the .NET stack ignoring an IdP-supplied locale that Java and PHP both honour.
app.UseRequestLocalization();

app.MapTrustedAttestationApi();
app.MapTrustedAttestationManifest();
app.MapRazorPages();

// / is the public landing page (Pages/Index.cshtml): unauthenticated, indexable, and what the
// manifest's enrollment_url points at. A prospective member has no session to show yet, so the first
// page they meet must not be a login redirect.

app.MapSignOut(settings.Iam.Protocol, challengeScheme);

// The language switcher posts here. See LanguageSwitchEndpoint.
app.MapLanguageSwitch(localization);

await app.RunAsync();
