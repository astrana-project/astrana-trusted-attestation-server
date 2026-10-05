using Astrana.TrustedAttestation.Server.Configuration;
using Astrana.TrustedAttestation.Server.Security;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.HttpOverrides;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Logging.Abstractions;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The rule this enforces is that there is no way to end up serving plain HTTP by accident, so the tests
/// that matter are the ones where something looks configured but is not.
///
/// <para>The case that matters most is an explicit opt-out, which slips past a check that only looks for
/// the presence of key material. Three implementations held to parity (decision record 5 in docs/adr) should not
/// disagree about which of their security rules are proven.</para>
/// </summary>
public class TlsRequirementTests
{
    private static Exception? EnforcementOf(TlsOptions? tls = null,
                                            params (string Key, string Value)[] settings)
        => Record.Exception(() => Enforce(tls, settings));

    private static void Enforce(TlsOptions? tls = null, params (string Key, string Value)[] settings)
    {
        var configuration = new ConfigurationBuilder()
            .AddInMemoryCollection(settings.Select(s => new KeyValuePair<string, string?>(s.Key, s.Value)))
            .Build();

        TlsRequirement.Enforce(configuration, tls ?? new TlsOptions(), NullLogger.Instance);
    }

    [Fact]
    public void NothingConfiguredAtAllIsRefused()
    {
        var refusal = Assert.Throws<InvalidOperationException>(() => Enforce());

        Assert.Contains("Refusing to start without TLS", refusal.Message, StringComparison.Ordinal);
    }

    [Fact]
    public void AnHttpOnlyEndpointIsRefused()
    {
        Assert.Throws<InvalidOperationException>(() => Enforce(settings: ("urls", "http://localhost:5000")));
    }

    [Fact]
    public void AnHttpsEndpointIsAccepted()
    {
        Assert.Null(EnforcementOf(settings: ("urls", "https://localhost:7443")));
    }

    [Fact]
    public void HttpAlongsideHttpsIsRefusedAndNamed()
    {
        // There is no redirect from the plain port, so a plain listener beside the https one serves the
        // same pages and the same API in the clear. Every endpoint has to be https, and the refusal says
        // which one is not.
        var refusal = Assert.Throws<InvalidOperationException>(
            () => Enforce(settings: ("urls", "http://localhost:5000;https://localhost:7443")));

        Assert.Contains("http://localhost:5000", refusal.Message, StringComparison.Ordinal);
    }

    [Fact]
    public void TheSchemeIsMatchedWithoutRegardToCase()
    {
        Assert.Null(EnforcementOf(settings: ("urls", "HTTPS://localhost:7443")));
    }

    [Fact]
    public void TheAspNetCoreUrlsVariableIsReadWhenUrlsIsAbsent()
    {
        Assert.Null(EnforcementOf(settings: ("ASPNETCORE_URLS", "https://localhost:7443")));
    }

    [Fact]
    public void AKestrelEndpointCountsAsWellAsTheUrlsSetting()
    {
        // Configuring endpoints under Kestrel rather than urls is the more usual production shape, and a
        // check that only read urls would refuse a correctly configured app.
        Assert.Null(EnforcementOf(settings: ("Kestrel:Endpoints:Https:Url", "https://localhost:7443")));
    }

    [Fact]
    public void AnHttpOnlyKestrelEndpointIsRefused()
    {
        Assert.Throws<InvalidOperationException>(
            () => Enforce(settings: ("Kestrel:Endpoints:Http:Url", "http://localhost:5000")));
    }

    [Fact]
    public void KestrelEndpointsAreJudgedAndUrlsIgnoredWhenBothAreSet()
    {
        // Kestrel binds the configured endpoints and ignores urls when both are present, so an https
        // address in ASPNETCORE_URLS must not approve a deployment that listens on a plain Kestrel endpoint.
        Assert.Throws<InvalidOperationException>(() => Enforce(settings:
            [("urls", "https://localhost:7443"), ("Kestrel:Endpoints:Http:Url", "http://localhost:5000")]));

        // And the other way about, a plain urls value is harmless once Kestrel endpoints decide.
        Assert.Null(EnforcementOf(settings:
            [("urls", "http://localhost:5000"), ("Kestrel:Endpoints:Https:Url", "https://localhost:7443")]));
    }

    [Fact]
    public void AnHttpsPortWithADefaultCertificateIsAccepted()
    {
        Assert.Null(EnforcementOf(settings:
            [("ASPNETCORE_HTTPS_PORTS", "8443"), ("Kestrel:Certificates:Default:Path", "/certs/server.pfx")]));
        Assert.Null(EnforcementOf(settings:
            [("HTTPS_PORTS", "8443"), ("Kestrel:Certificates:Default:Subject", "attest.example.org")]));
    }

    [Fact]
    public void AnHttpsPortWithoutACertificateProvesNothing()
    {
        // Kestrel cannot bind an https port without a certificate, so the port alone is not a TLS endpoint.
        var refusal = Assert.Throws<InvalidOperationException>(() => Enforce(settings: ("ASPNETCORE_HTTPS_PORTS", "8443")));

        Assert.Contains("Kestrel:Certificates:Default", refusal.Message, StringComparison.Ordinal);
    }

    [Fact]
    public void AnHttpPortIsRefusedUnlessAProxyTerminatesTls()
    {
        var refusal = Assert.Throws<InvalidOperationException>(() => Enforce(settings: ("ASPNETCORE_HTTP_PORTS", "8080")));
        Assert.Contains("ASPNETCORE_HTTP_PORTS=8080", refusal.Message, StringComparison.Ordinal);

        Assert.Null(EnforcementOf(new TlsOptions { TerminatedByProxy = true }, ("ASPNETCORE_HTTP_PORTS", "8080")));
    }

    [Fact]
    public void AnHttpPortBesideAnHttpsPortIsRefused()
    {
        Assert.Throws<InvalidOperationException>(() => Enforce(settings:
        [
            ("ASPNETCORE_HTTPS_PORTS", "8443"),
            ("ASPNETCORE_HTTP_PORTS", "8080"),
            ("Kestrel:Certificates:Default:Path", "/certs/server.pfx"),
        ]));
    }

    [Fact]
    public void AProxyTerminatingTlsIsAcceptedButOnlyWhenSaidExplicitly()
    {
        Assert.Null(EnforcementOf(new TlsOptions { TerminatedByProxy = true }));
    }

    [Fact]
    public void AProxyClaimIsWhatMakesAnHttpEndpointAcceptable()
    {
        // The same configuration that is refused above becomes fine once the operator states that
        // something in front is terminating TLS. Nothing infers this; it has to be declared.
        Assert.Null(EnforcementOf(new TlsOptions { TerminatedByProxy = true }, ("urls", "http://localhost:5000")));
    }

    // ---------------------------------------------------------------------------------------------
    // The forwarded-scheme guard, which is what stops "terminated by proxy" becoming a way to turn the
    // requirement off.
    // ---------------------------------------------------------------------------------------------

    private static async Task<int> StatusForForwardedProto(string? forwardedProto)
    {
        var context = new DefaultHttpContext();
        if (forwardedProto is not null)
        {
            context.Request.Headers["X-Forwarded-Proto"] = forwardedProto;
        }

        var reachedTheApp = false;

        var builder = new ApplicationBuilder(serviceProvider: null!);
        builder.UseForwardedSchemeGuard();
        builder.Run(_ =>
        {
            reachedTheApp = true;
            return Task.CompletedTask;
        });

        await builder.Build()(context);

        return reachedTheApp ? StatusCodes.Status200OK : context.Response.StatusCode;
    }

    [Fact]
    public async Task ARequestTheProxySaysArrivedOverHttpIsRefused()
    {
        Assert.Equal(StatusCodes.Status421MisdirectedRequest, await StatusForForwardedProto("http"));
    }

    [Fact]
    public async Task ARequestTheProxySaysArrivedOverHttpsIsServed()
    {
        Assert.Equal(StatusCodes.Status200OK, await StatusForForwardedProto("https"));
    }

    [Fact]
    public async Task ARequestWithNoForwardedSchemeIsServed()
    {
        // Not every proxy sets the header, so its absence says nothing to refuse.
        Assert.Equal(StatusCodes.Status200OK, await StatusForForwardedProto(null));
    }

    [Theory]
    [InlineData("")]
    [InlineData(" ")]
    public async Task AForwardedSchemeHeaderThatIsPresentButEmptyIsRefused(string forwardedProto)
    {
        // A proxy that sets the header always gives it a value, and the frameworks read an empty one
        // differently, so all three implementations refuse it.
        Assert.Equal(StatusCodes.Status421MisdirectedRequest, await StatusForForwardedProto(forwardedProto));
    }

    [Theory]
    [InlineData("http,https")]
    [InlineData("http, https")]
    [InlineData("https,http")]
    [InlineData("https, http")]
    [InlineData("https,https")]
    [InlineData("https,")]
    public async Task AForwardedSchemeCarryingMoreThanOneValueIsRefused(string forwardedProto)
    {
        // The one declared proxy sends one value. A list means a client sent its own value that the proxy
        // appended to, and the frameworks disagree on which entry counts (ASP.NET Core takes the last,
        // Spring and Laravel the first). All three implementations refuse any list, so the same request
        // gets the same answer whichever stack receives it.
        Assert.Equal(StatusCodes.Status421MisdirectedRequest, await StatusForForwardedProto(forwardedProto));
    }

    [Fact]
    public async Task AForwardedSchemeSpreadOverTwoHeaderLinesIsRefused()
    {
        var context = new DefaultHttpContext();
        context.Request.Headers["X-Forwarded-Proto"] = new Microsoft.Extensions.Primitives.StringValues(["https", "https"]);

        var reachedTheApp = false;
        var builder = new ApplicationBuilder(serviceProvider: null!);
        builder.UseForwardedSchemeGuard();
        builder.Run(_ =>
        {
            reachedTheApp = true;
            return Task.CompletedTask;
        });

        await builder.Build()(context);

        Assert.False(reachedTheApp);
        Assert.Equal(StatusCodes.Status421MisdirectedRequest, context.Response.StatusCode);
    }

    [Theory]
    [InlineData("HTTPS")]
    [InlineData("Https")]
    [InlineData("wss")]
    [InlineData("httpsx")]
    [InlineData("https:")]
    [InlineData(" https")]
    public async Task AnythingButTheExactTokenHttpsIsRefused(string forwardedProto)
    {
        // The middleware applies the token exactly as sent, so the guard compares it exactly. A scheme of
        // "HTTPS" would become the request's scheme verbatim.
        Assert.Equal(StatusCodes.Status421MisdirectedRequest, await StatusForForwardedProto(forwardedProto));
    }

    [Fact]
    public async Task TheRefusalCarriesTheSecurityHeadersAndNoBodyOrContentType()
    {
        // The pipeline front Program.cs uses, with the forwarded headers applied as they are in production,
        // which removes X-Forwarded-Proto once applied. The security headers run before the guard, so the
        // refusal is hardened like every other response, and like every other error it says nothing beyond
        // its status.
        var builder = PipelineFront(new TlsOptions { TerminatedByProxy = true });

        var reachedTheApp = false;
        builder.Run(_ =>
        {
            reachedTheApp = true;
            return Task.CompletedTask;
        });

        var context = new DefaultHttpContext();
        context.Request.Headers["X-Forwarded-Proto"] = "http";
        context.Response.Body = new MemoryStream();

        await builder.Build()(context);

        Assert.False(reachedTheApp);
        Assert.Equal(StatusCodes.Status421MisdirectedRequest, context.Response.StatusCode);
        Assert.Equal("nosniff", context.Response.Headers.XContentTypeOptions);
        Assert.Equal("DENY", context.Response.Headers.XFrameOptions);
        Assert.Equal("no-referrer", context.Response.Headers["Referrer-Policy"]);
        Assert.Null(context.Response.ContentType);
        Assert.Equal(0, context.Response.Body.Length);
    }

    // ---------------------------------------------------------------------------------------------
    // Strict-Transport-Security sits at the pipeline front too, so every response any later middleware
    // produces carries it, static files included.
    // ---------------------------------------------------------------------------------------------

    private static ApplicationBuilder PipelineFront(TlsOptions tls)
    {
        var services = new ServiceCollection().AddLogging().AddHsts(SecurityHeaders.ConfigureHsts);
        services.Configure<ForwardedHeadersOptions>(TlsRequirement.ConfigureForwardedHeaders);

        var builder = new ApplicationBuilder(services.BuildServiceProvider());
        builder.UseSecurityHeadersAndSchemeGuard(tls);
        return builder;
    }

    private static async Task<IHeaderDictionary> HeadersAtTheFirstMiddlewareAfterTheFront(
        TlsOptions tls, Action<HttpRequest> request)
    {
        // The headers as the next middleware sees them: what a static file served from there would carry.
        var builder = PipelineFront(tls);
        IHeaderDictionary? seen = null;
        builder.Run(context =>
        {
            seen = context.Response.Headers;
            return Task.CompletedTask;
        });

        var context = new DefaultHttpContext();
        context.Request.Host = new HostString("attest.example.org");
        request(context.Request);
        await builder.Build()(context);

        return seen!;
    }

    [Fact]
    public async Task BehindAProxyHstsIsSetBeforeTheNextMiddlewareRuns()
    {
        var headers = await HeadersAtTheFirstMiddlewareAfterTheFront(
            new TlsOptions { TerminatedByProxy = true },
            request => request.Headers["X-Forwarded-Proto"] = "https");

        Assert.Equal("max-age=31536000", headers.StrictTransportSecurity);
    }

    [Fact]
    public async Task ServingTlsItselfHstsIsSetBeforeTheNextMiddlewareRuns()
    {
        var headers = await HeadersAtTheFirstMiddlewareAfterTheFront(
            new TlsOptions(),
            request => request.Scheme = "https");

        Assert.Equal("max-age=31536000", headers.StrictTransportSecurity);
    }

    [Fact]
    public async Task HstsTurnedOffIsAbsentAtTheFrontToo()
    {
        var headers = await HeadersAtTheFirstMiddlewareAfterTheFront(
            new TlsOptions { HstsEnabled = false },
            request => request.Scheme = "https");

        Assert.False(headers.ContainsKey("Strict-Transport-Security"));
    }
}
