using Astrana.TrustedAttestation.Server.Security;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Server.Kestrel.Core;
using Microsoft.Extensions.DependencyInjection;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The hardening headers every response carries.
///
/// Their whole justification is that the three implementations must not differ by accident -- Spring
/// sends these by default, ASP.NET Core and Laravel send nothing -- and that a mistyped header name is a
/// header that silently does nothing: the response still goes out, nothing downstream fails, and the
/// protection is simply absent. That is exactly the kind of regression a test has to be watching for,
/// because middleware ordering can drop it and no request will fail. The conformance suite checks these
/// from outside; this pins them from within by running the middleware against a bare context, mirroring
/// PHP's SecurityHeadersTest.
/// </summary>
public class SecurityHeadersTests
{
    private static async Task<IHeaderDictionary> HeadersAfterMiddleware()
    {
        var builder = new ApplicationBuilder(new ServiceCollection().BuildServiceProvider());
        builder.UseSecurityHeaders();
        builder.Run(_ => Task.CompletedTask);

        var context = new DefaultHttpContext();
        await builder.Build().Invoke(context);

        return context.Response.Headers;
    }

    [Fact]
    public async Task Every_response_is_told_not_to_sniff_the_content_type_or_be_framed()
    {
        var headers = await HeadersAfterMiddleware();

        // Content-type sniffing turns a response the server labelled as data into one the browser may
        // decide to execute, and the self-service page is precisely what a clickjacking overlay would sit on.
        Assert.Equal("nosniff", headers.XContentTypeOptions);
        Assert.Equal("DENY", headers.XFrameOptions);
    }

    [Fact]
    public async Task The_referrer_is_withheld_so_another_site_cannot_learn_where_a_member_came_from()
    {
        // A member's session pages are private to them, so a link they follow to another site should not
        // tell that site, in a Referer header, where they came from.
        Assert.Equal("no-referrer", (await HeadersAfterMiddleware())["Referrer-Policy"]);
    }

    [Fact]
    public async Task The_legacy_xss_auditor_is_switched_off_rather_than_left_to_the_browser_default()
    {
        // Deliberately 0, not "1; mode=block": the legacy auditor is a source of vulnerabilities of its
        // own, and all three implementations disable it explicitly rather than leaving it to the browser.
        Assert.Equal("0", (await HeadersAfterMiddleware()).XXSSProtection);
    }

    [Fact]
    public async Task Nothing_this_app_serves_may_be_cached()
    {
        // The self-service page is per-member and the API answers are point-in-time. no-store is what keeps
        // that page out of a shared cache.
        var cacheControl = (await HeadersAfterMiddleware()).CacheControl.ToString();

        Assert.Contains("no-store", cacheControl);
    }

    /// <summary>
    /// A response from a real Kestrel listening on a free loopback port, because the Server header is added
    /// by the server itself, after every middleware has run. The empty builder reads no settings file and no
    /// environment, so nothing outside the test can change what it listens on.
    /// </summary>
    private static async Task<HttpResponseMessage> ResponseFromKestrel(Action<KestrelServerOptions> configure)
    {
        var builder = WebApplication.CreateEmptyBuilder(new WebApplicationOptions());
        builder.WebHost.UseKestrelCore().UseUrls("http://127.0.0.1:0").ConfigureKestrel(configure);

        await using var app = builder.Build();
        app.Run(context =>
        {
            context.Response.StatusCode = StatusCodes.Status204NoContent;
            return Task.CompletedTask;
        });
        await app.StartAsync();

        using var client = new HttpClient { BaseAddress = new Uri(app.Urls.Single()) };
        var response = await client.GetAsync(new Uri("/", UriKind.Relative));
        await app.StopAsync();
        return response;
    }

    [Fact]
    public async Task No_response_names_the_runtime_in_a_server_header()
    {
        using var response = await ResponseFromKestrel(SecurityHeaders.ConfigureServerHeader);

        Assert.Empty(response.Headers.Server);
    }

    [Fact]
    public async Task Kestrel_names_itself_unless_told_not_to()
    {
        // The control for the test above, which would otherwise pass on a server that never sends the header.
        using var response = await ResponseFromKestrel(_ => { });

        Assert.Contains(response.Headers.Server, server => server.Product?.Name == "Kestrel");
    }

    private static async Task<IHeaderDictionary> HeadersAfterHsts(bool enabled, string host)
    {
        var services = new ServiceCollection().AddLogging().AddHsts(SecurityHeaders.ConfigureHsts);
        var builder = new ApplicationBuilder(services.BuildServiceProvider());
        builder.UseHstsUnlessDisabled(enabled);
        builder.Run(_ => Task.CompletedTask);

        var context = new DefaultHttpContext();
        context.Request.Scheme = "https";
        context.Request.Host = new HostString(host);
        await builder.Build().Invoke(context);

        return context.Response.Headers;
    }

    [Fact]
    public async Task Hsts_is_one_year_with_no_subdomains_and_no_preload_the_value_java_sends()
    {
        // Decision record 35. Subdomains and preload are the organisation's choice, made at the proxy, because the
        // application cannot see what lives under its own address.
        var headers = await HeadersAfterHsts(enabled: true, host: "attest.example.org");

        Assert.Equal("max-age=31536000", headers.StrictTransportSecurity);
    }

    [Fact]
    public async Task Hsts_is_not_sent_when_turned_off_so_a_proxy_can_send_its_own()
    {
        var headers = await HeadersAfterHsts(enabled: false, host: "attest.example.org");

        Assert.False(headers.ContainsKey("Strict-Transport-Security"));
    }

    [Fact]
    public async Task Hsts_is_never_sent_to_localhost_so_a_development_run_pins_nothing()
    {
        var headers = await HeadersAfterHsts(enabled: true, host: "localhost");

        Assert.False(headers.ContainsKey("Strict-Transport-Security"));
    }
}
