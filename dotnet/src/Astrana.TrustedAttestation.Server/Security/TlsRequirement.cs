using Astrana.TrustedAttestation.Server.Configuration;
using Microsoft.AspNetCore.HttpOverrides;

namespace Astrana.TrustedAttestation.Server.Security;

/// <summary>
/// Astrana Trusted Attestation refuses to run without TLS. It does not recommend it or warn without it.
/// Sign-in credentials, identity system tokens and public keys all pass through it, so serving any of that
/// over plain HTTP defeats the point. If TLS is not configured the application fails to start rather than
/// starting insecurely.
///
/// Kestrel behind IIS or Nginx is the normal deployment shape for this stack, so TLS terminating at a
/// proxy satisfies the requirement, but only when the operator says so explicitly. There is no
/// auto-detection and no default that lets an unconfigured instance serve plain HTTP.
/// </summary>
internal static class TlsRequirement
{
    /// <summary>One endpoint Kestrel will listen on, and whether it serves TLS.</summary>
    internal sealed record Endpoint(string Description, bool ServesTls);

    public static void Enforce(IConfiguration configuration, TlsOptions tls, ILogger logger)
    {
        if (tls.TerminatedByProxy)
        {
            logger.LogInformation(
                "TLS is terminated by a reverse proxy (TrustedAttestation:Tls:TerminatedByProxy). Requests arriving with " +
                "X-Forwarded-Proto other than https will be rejected.");
            return;
        }

        var endpoints = ConfiguredEndpoints(configuration);

        // Every endpoint has to serve TLS, not merely one of them. There is no redirect from a plain port
        // here, so a plain listener beside an https one would serve the same pages and the same API in the
        // clear to anyone who found it.
        var plain = endpoints.Where(endpoint => !endpoint.ServesTls).Select(endpoint => endpoint.Description).ToList();
        if (plain.Count > 0)
        {
            throw new InvalidOperationException(
                "Refusing to start with a plain-HTTP endpoint: " + string.Join(", ", plain) + ". Every configured " +
                "endpoint must be https://, or set TrustedAttestation:Tls:TerminatedByProxy to true if a reverse proxy " +
                "terminates TLS in front of this app. There is no plain-HTTP fallback.");
        }

        if (endpoints.Count > 0)
        {
            return;
        }

        throw new InvalidOperationException(
            "Refusing to start without TLS. Configure an https:// endpoint (ASPNETCORE_URLS, the 'urls' " +
            "setting, Kestrel:Endpoints, or ASPNETCORE_HTTPS_PORTS with a Kestrel:Certificates:Default certificate), " +
            "or set TrustedAttestation:Tls:TerminatedByProxy to true if a reverse proxy terminates TLS in front of " +
            "this app. There is no plain-HTTP fallback.");
    }

    /// <summary>
    /// The endpoints Kestrel will actually bind, read with Kestrel's own precedence: endpoints declared
    /// under <c>Kestrel:Endpoints</c> win outright, and when there are any, <c>urls</c> is ignored. Otherwise
    /// <c>urls</c> (which <c>ASPNETCORE_URLS</c> feeds) decides, and only when that is absent do the port
    /// variables <c>ASPNETCORE_HTTPS_PORTS</c> and <c>ASPNETCORE_HTTP_PORTS</c> apply. Judging a source Kestrel
    /// would ignore would let an https address in <c>ASPNETCORE_URLS</c> approve a deployment that listens
    /// on a plain Kestrel endpoint.
    /// </summary>
    internal static IReadOnlyList<Endpoint> ConfiguredEndpoints(IConfiguration configuration)
    {
        var kestrel = configuration.GetSection("Kestrel:Endpoints").GetChildren()
            .Select(endpoint => (Name: endpoint.Key, Url: endpoint["Url"]))
            .Where(endpoint => !string.IsNullOrWhiteSpace(endpoint.Url))
            .Select(endpoint => new Endpoint($"Kestrel:Endpoints:{endpoint.Name} ({endpoint.Url})", IsHttps(endpoint.Url!)))
            .ToList();
        if (kestrel.Count > 0)
        {
            return kestrel;
        }

        var urls = configuration["urls"] ?? configuration["ASPNETCORE_URLS"];
        if (!string.IsNullOrWhiteSpace(urls))
        {
            return [.. SplitList(urls).Select(url => new Endpoint(url, IsHttps(url)))];
        }

        var endpoints = new List<Endpoint>();

        // HTTPS_PORTS is only a TLS endpoint when Kestrel has a certificate to serve it with. Without one
        // Kestrel fails to bind, so a configuration naming the port alone proves nothing.
        var httpsPorts = configuration["HTTPS_PORTS"] ?? configuration["ASPNETCORE_HTTPS_PORTS"];
        if (!string.IsNullOrWhiteSpace(httpsPorts))
        {
            var certificate = configuration.GetSection("Kestrel:Certificates:Default");
            var hasCertificate = !string.IsNullOrWhiteSpace(certificate["Path"])
                                 || !string.IsNullOrWhiteSpace(certificate["Subject"]);

            endpoints.AddRange(SplitList(httpsPorts).Select(port => new Endpoint(
                hasCertificate
                    ? $"ASPNETCORE_HTTPS_PORTS={port}"
                    : $"ASPNETCORE_HTTPS_PORTS={port} without a Kestrel:Certificates:Default certificate",
                hasCertificate)));
        }

        var httpPorts = configuration["HTTP_PORTS"] ?? configuration["ASPNETCORE_HTTP_PORTS"];
        if (!string.IsNullOrWhiteSpace(httpPorts))
        {
            endpoints.AddRange(SplitList(httpPorts).Select(port => new Endpoint($"ASPNETCORE_HTTP_PORTS={port}", false)));
        }

        return endpoints;
    }

    private static bool IsHttps(string url) => url.StartsWith("https://", StringComparison.OrdinalIgnoreCase);

    private static IEnumerable<string> SplitList(string value) =>
        value.Split(';', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries);

    /// <summary>
    /// Which forwarded headers are applied behind a proxy, and from whom.
    /// </summary>
    public static void ConfigureForwardedHeaders(ForwardedHeadersOptions options)
    {
        options.ForwardedHeaders = ForwardedHeaders.XForwardedProto | ForwardedHeaders.XForwardedHost;

        // Both allowlists are cleared. In ASP.NET Core that does not mean "trust no proxy" but "trust every
        // immediate peer". With KnownProxies and KnownIPNetworks both empty the middleware stops checking
        // the source and applies X-Forwarded-* from whoever connected. This matches the other two
        // implementations (Java's ForwardedHeaderFilter, PHP's trustProxies('*')). In the proxy-terminated
        // shape the application's plain-HTTP port is reachable only by the proxy in front of it, so the
        // immediate peer is always that proxy. It is safe on that assumption and no other, because a client
        // that reaches the port directly can forge the scheme. To narrow it, populate KnownProxies or
        // KnownIPNetworks with the proxy's address. Unlike PHP there is no setting for that, which is the
        // one place the three differ here.
        options.KnownIPNetworks.Clear();
        options.KnownProxies.Clear();
    }

    /// <summary>
    /// The front of the pipeline. The security headers come first, so every response carries them, the
    /// HTTP 421 refusal from the forwarded-scheme guard included. Behind a proxy the guard runs next, and
    /// the forwarded headers are applied after it, because applying them removes the X-Forwarded-Proto
    /// header the guard reads. Strict-Transport-Security comes last of these, once the request's scheme is
    /// known: the framework sends it only on an https request, which behind a proxy the request becomes
    /// when the forwarded headers are applied. From here on every response any later middleware produces,
    /// a static file included, carries it. The 421 cannot carry it, because that response is to a request
    /// the proxy says arrived over plain HTTP, and a browser ignores the header on an insecure response.
    /// </summary>
    public static void UseSecurityHeadersAndSchemeGuard(this IApplicationBuilder app, TlsOptions tls)
    {
        app.UseSecurityHeaders();

        if (tls.TerminatedByProxy)
        {
            app.UseForwardedSchemeGuard();
            app.UseForwardedHeaders();
        }

        app.UseHstsUnlessDisabled(tls.HstsEnabled);
    }

    /// <summary>
    /// With TLS at a proxy, the application can no longer see the scheme itself, so it trusts the forwarded
    /// header, and must then refuse anything the proxy says arrived over plain HTTP. Without this,
    /// "terminated by proxy" would be a way to turn the TLS requirement off.
    /// </summary>
    public static void UseForwardedSchemeGuard(this IApplicationBuilder app)
    {
        app.Use(async (context, next) =>
        {
            if (RefusesForwardedScheme(context.Request.Headers))
            {
                context.Response.StatusCode = StatusCodes.Status421MisdirectedRequest;
                return;
            }

            await next(context);
        });
    }

    /// <summary>
    /// Whether the forwarded scheme is anything but exactly one value of exactly <c>https</c>. The one
    /// declared proxy in front of this application sends one value. A list, whether comma-separated or
    /// spread over several header lines, means a client sent a value of its own that the proxy appended to,
    /// and the frameworks disagree on which entry counts (ASP.NET Core applies the last, Spring and Laravel
    /// the first), so all three refuse a list rather than pick an entry. The value is compared exactly,
    /// because the middleware applies it exactly. A request with no forwarded scheme header passes, because
    /// not every proxy sets it. A header that is present but empty is refused, because a proxy that sets
    /// the header always gives it a value, and the three frameworks read an empty one differently.
    /// </summary>
    internal static bool RefusesForwardedScheme(IHeaderDictionary headers)
    {
        var values = headers[ForwardedHeadersDefaults.XForwardedProtoHeaderName];

        if (values.Count == 0)
        {
            return false;
        }

        return values.Count > 1 || !string.Equals(values[0], "https", StringComparison.Ordinal);
    }
}
