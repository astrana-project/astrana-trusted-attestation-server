using Microsoft.Extensions.Options;
using Sustainsys.Saml2.AspNetCore2;

namespace Astrana.TrustedAttestation.Server.Security;

/// <summary>
/// Answers a logout request the identity provider sends as if the single logout address were absent, when
/// the server has no signing certificate.
///
/// <para>The answer to a logout request has to be a signed logout response, so without a certificate the
/// server offers no single logout and its metadata advertises none. A provider that sends one anyway gets
/// HTTP 404 with no body and no content type, the member's session is left as it was, and a warning names the
/// cause. Left to the SAML library, the request would end in an unhandled exception and HTTP 500. The other
/// two implementations answer it the same way.</para>
///
/// <para>Runs ahead of the authentication middleware, which is where the SAML handler takes the request. With
/// a certificate it lets every request through untouched.</para>
/// </summary>
public static class SamlLogoutRequestRefusal
{
    private const string SamlRequest = "SAMLRequest";

    public static void UseSamlLogoutRequestRefusal(this IApplicationBuilder app, string modulePath)
    {
        app.Use(async (context, next) =>
        {
            if (!SamlLogoutPath.Matches(context.Request.Path, modulePath)
                || HasSigningCertificate(context)
                || !await CarriesLogoutRequestAsync(context.Request))
            {
                await next();
                return;
            }

            context.RequestServices
                .GetRequiredService<ILoggerFactory>()
                .CreateLogger(typeof(SamlLogoutRequestRefusal).FullName!)
                .LogWarning("A SAML logout request arrived, but no signing certificate is configured to answer it, "
                    + "so single logout is not offered and the request was refused.");

            context.Response.StatusCode = StatusCodes.Status404NotFound;
        });
    }

    private static bool HasSigningCertificate(HttpContext context) => context.RequestServices
        .GetRequiredService<IOptionsMonitor<Saml2Options>>()
        .Get(Saml2Defaults.Scheme)
        .SPOptions.SigningServiceCertificate is not null;

    /// <summary>Whether the request carries a logout request, on the HTTP-Redirect or the HTTP-POST binding.</summary>
    private static async Task<bool> CarriesLogoutRequestAsync(HttpRequest request)
    {
        if (request.Query.ContainsKey(SamlRequest))
        {
            return true;
        }

        return request.HasFormContentType && (await request.ReadFormAsync()).ContainsKey(SamlRequest);
    }
}
