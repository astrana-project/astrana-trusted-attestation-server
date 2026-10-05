using System.Globalization;
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
using Microsoft.AspNetCore.Localization;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Options;
using StringWithQualityHeaderValue = Microsoft.Net.Http.Headers.StringWithQualityHeaderValue;

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

// One codebase, three engines: the ORM abstracts the engine, so an org picks whichever database it
// already runs regardless of which stack it deployed.
builder.Services.AddDbContext<TrustedAttestationDbContext>(options =>
{
    var connectionString = settings.Database.ConnectionString;

    switch (settings.Database.Provider)
    {
        case DatabaseProvider.SqlServer:
            options.UseSqlServer(connectionString);
            break;
        case DatabaseProvider.PostgreSql:
            options.UseNpgsql(connectionString);
            break;
        case DatabaseProvider.MySql:
            options.UseMySQL(connectionString);
            break;
        default:
            throw new InvalidOperationException($"Unsupported database provider '{settings.Database.Provider}'.");
    }
});

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

// Rendering in the member's language is a requirement, not optional polish, and there is little to
// localise. Three pages, a handful of controls, the member's own details and their relationship labels.
builder.Services.Configure<RequestLocalizationOptions>(options =>
{
    var supported = localization.Locales.Select(c => new CultureInfo(c)).ToList();
    options.DefaultRequestCulture = new RequestCulture(localization.DefaultLocale);
    options.SupportedCultures = supported;
    options.SupportedUICultures = supported;

    // ASP.NET Core ships three culture providers by default -- query string, then cookie, then
    // Accept-Language. The query-string provider stays out: a ?ui-culture=de link would let a crafted URL
    // silently set the language, which the other two implementations do not allow either. The stock cookie
    // provider also goes, because it parses its own "c=..|uic=.." format; a plain, validated cookie set only
    // by the language switcher (below) replaces it, kept identical across the three stacks.
    var queryAndCookie = options.RequestCultureProviders
        .Where(p => p is QueryStringRequestCultureProvider or CookieRequestCultureProvider)
        .ToList();
    foreach (var provider in queryAndCookie)
    {
        options.RequestCultureProviders.Remove(provider);
    }

    // The built-in Accept-Language provider matches by .NET's culture parent chain, which has no rule for
    // a language shipped in two scripts: a bare "zh" would match nothing here where the other two serve
    // zh-Hans. It is replaced by a provider that walks the whole header in quality order, every entry it
    // carries rather than the default cap of three, and matches each with the one rule all three
    // implementations share (LocalizationSettings.Match).
    foreach (var accept in options.RequestCultureProviders.OfType<AcceptLanguageHeaderRequestCultureProvider>().ToList())
    {
        options.RequestCultureProviders.Remove(accept);
    }

    options.RequestCultureProviders.Add(new CustomRequestCultureProvider(context =>
    {
        if (!StringWithQualityHeaderValue.TryParseList(context.Request.Headers.AcceptLanguage, out var entries))
        {
            return Task.FromResult<ProviderCultureResult?>(null);
        }

        var matched = entries
            .Where(entry => entry.Quality is null or > 0)
            .OrderByDescending(entry => entry.Quality ?? 1.0)
            .Select(entry => localization.Match(entry.Value.Value))
            .FirstOrDefault(match => match is not null);

        return Task.FromResult(matched is null ? null : new ProviderCultureResult(matched));
    }));

    // An IAM-provided locale claim wins over the browser's Accept-Language: the org knows which language it
    // holds this member's record in. Accept-Language remains as the fallback behind it.
    options.RequestCultureProviders.Insert(0, new ClaimsRequestCultureProvider(localization));

    // The language switcher's explicit choice wins over everything else: a member who picked a language has
    // said what they want. It is a plain cookie holding just the locale code, validated on write to the
    // offered set (see /set-language) and constrained again here by SupportedCultures, so an unknown or
    // no-longer-offered value is ignored and resolution falls through to the claim, then Accept-Language.
    options.RequestCultureProviders.Insert(0, new CustomRequestCultureProvider(context =>
    {
        var cookie = localization.Match(context.Request.Cookies["ata_locale"]);
        return Task.FromResult<ProviderCultureResult?>(
            cookie is null ? null : new ProviderCultureResult(cookie));
    }));
});

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

// Normalise JSON responses to a bare "application/json". ASP.NET Core appends "; charset=utf-8"; the
// other two implementations do not, and since JSON is UTF-8 by definition the parameter carries no
// information -- dropping it makes the manifest and every API response byte-identical across all three.
// Outermost in the pipeline, so its OnStarting callback sees the content type each endpoint set, just
// before the headers are written.
app.Use(async (context, next) =>
{
    context.Response.OnStarting(() =>
    {
        var contentType = context.Response.ContentType;
        if (contentType is not null
            && contentType.StartsWith("application/json", StringComparison.OrdinalIgnoreCase)
            && contentType.Contains("charset", StringComparison.OrdinalIgnoreCase))
        {
            context.Response.ContentType = "application/json";
        }

        return Task.CompletedTask;
    });

    await next();
});

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

// The language switcher posts here. It stores the chosen locale in a plain cookie, validated against the
// offered set so a forged value cannot render an unsupported language, and returns to the page the member
// was on. There is no anti-forgery token, because the switcher is on the public landing page too, which
// carries no session to hold a token. A cross-site form can still submit the switcher, and the most it
// changes is the display language. Kept identical across the three stacks.
app.MapPost("/set-language", async (HttpContext http) =>
{
    // The form body only, and only when there is one. A request with no form (no body, or JSON) carries no
    // locale and no return path, so it sets nothing and lands on the landing page rather than failing.
    var form = await ReadFormAsync(http.Request);
    var posted = form?["locale"].ToString();

    // Stored in the shipped spelling, whatever case was posted, so the three implementations agree.
    var offered = localization.Locales.FirstOrDefault(l => string.Equals(l, posted, StringComparison.OrdinalIgnoreCase));
    if (offered is not null)
    {
        http.Response.Cookies.Append("ata_locale", offered, new CookieOptions
        {
            HttpOnly = true,
            Secure = true,
            SameSite = SameSiteMode.Lax,
            MaxAge = TimeSpan.FromDays(365),
            Path = "/",
            IsEssential = true,
        });
    }

    // Only a path on this server, by the rule all three implementations apply (see ReturnPath).
    return Results.LocalRedirect(ReturnPath.Sanitize(form?["next"].ToString()));
});

static async Task<IFormCollection?> ReadFormAsync(HttpRequest request)
{
    if (!request.HasFormContentType)
    {
        return null;
    }

    try
    {
        return await request.ReadFormAsync(request.HttpContext.RequestAborted);
    }
    catch (InvalidDataException)
    {
        // A form Content-Type on a body that is not a form, or one past the form limits. No form, then.
        return null;
    }
}

await app.RunAsync();
