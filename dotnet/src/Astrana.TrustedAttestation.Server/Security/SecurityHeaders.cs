using Microsoft.AspNetCore.Server.Kestrel.Core;

namespace Astrana.TrustedAttestation.Server.Security;

/// <summary>
/// Response headers that harden how a browser treats this app's pages.
///
/// <para>Set explicitly rather than left to the framework, because the three implementations
/// otherwise disagree by accident: Spring Security sends these by default, ASP.NET Core and Laravel send
/// nothing. Three implementations held to parity (decision record 5 in docs/adr) should not differ on how a browser
/// is told to treat the pages, so decision record 35 fixes the set.</para>
///
/// <para>HSTS goes through the framework's own middleware, configured by <see cref="ConfigureHsts"/> and
/// switched by <see cref="UseHstsUnlessDisabled"/>. The framework skips loopback, so a development run
/// cannot pin HTTPS for every other application a developer runs on localhost.</para>
///
/// <para>There is no Content-Security-Policy. The self-service page carries an inline script, so a policy
/// strict enough to be worth having needs per-response nonces threaded through the view. A policy with
/// <c>unsafe-inline</c> would look like protection while permitting exactly what a Content-Security-Policy
/// exists to stop, which is worse than being honest about not having one.</para>
/// </summary>
public static class SecurityHeaders
{
    /// <summary>
    /// One year, with no subdomains and no preload, the same value Java sends (decision record 35). The application
    /// cannot see what lives under its own address, so covering subdomains or asking for preload is left to
    /// the organisation, at the proxy. The framework's default of 30 days is replaced because this server
    /// refuses plain HTTP from the first request, so a year commits a browser to nothing that works today.
    /// </summary>
    public static void ConfigureHsts(Microsoft.AspNetCore.HttpsPolicy.HstsOptions options)
    {
        options.MaxAge = TimeSpan.FromDays(365);
        options.IncludeSubDomains = false;
        options.Preload = false;
    }

    /// <summary>
    /// Switches off Kestrel's Server header, so no response names the runtime, as in the other two
    /// implementations. Naming it tells an attacker which vulnerabilities to try and tells a member nothing.
    /// </summary>
    public static void ConfigureServerHeader(KestrelServerOptions kestrel)
    {
        kestrel.AddServerHeader = false;
    }

    public static void UseHstsUnlessDisabled(this IApplicationBuilder app, bool enabled)
    {
        if (enabled)
        {
            app.UseHsts();
        }
    }

    public static void UseSecurityHeaders(this IApplicationBuilder app)
    {
        app.Use(async (context, next) =>
        {
            Apply(context.Response.Headers);
            await next();
        });
    }

    /// <summary>
    /// Sets the fixed headers on a response. Called by the middleware for every response, and again by the
    /// status-only error handler after it has cleared a half-built response, so a 500 is hardened like
    /// everything else.
    /// </summary>
    public static void Apply(IHeaderDictionary headers)
    {
        // Typed properties rather than string keys wherever ASP.NET Core offers them. A mistyped
        // header name is a header that silently does nothing: the response still goes out, nothing
        // downstream fails, and the protection is simply absent.
        //
        // Content-type sniffing turns a response the server labelled as data into one the browser
        // may decide to execute.
        headers.XContentTypeOptions = "nosniff";

        // Nothing here is meant to be framed. The self-service page shows who someone is and lets them
        // register a key, which is precisely what a clickjacking overlay would want to sit on top of.
        headers.XFrameOptions = "DENY";

        // No typed property exists for this one, so the name is spelled out.
        //
        // A member's session pages are private to them, so a link they follow to another site should not
        // tell that site, in a Referer header, where they came from.
        headers["Referrer-Policy"] = "no-referrer";

        // Explicitly off, matching the other two implementations. The legacy XSS auditor this header
        // once switched on is a source of vulnerabilities of its own in the browsers that still have
        // it; 0 disables it rather than leaving the browser to its default.
        headers.XXSSProtection = "0";

        // Nothing this application serves should be cached. The self-service page is per-member and
        // the API answers are point-in-time. All three send no-store so nothing is ever cached. The
        // exact directive string still differs between the three HTTP stacks, which format and order
        // the header their own way.
        headers.CacheControl = "no-cache, no-store, max-age=0, must-revalidate";
    }
}
