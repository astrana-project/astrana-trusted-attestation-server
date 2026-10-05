using Microsoft.IdentityModel.Tokens;
using Sustainsys.Saml2.Exceptions;

namespace Astrana.TrustedAttestation.Server.Security;

/// <summary>
/// Turns a rejected SAML assertion into an answer rather than an unhandled exception.
///
/// <para>The assertion consumer service takes unauthenticated input by nature -- the IdP posts to it
/// cross-site, which means anyone can -- so refusing bad input is its main job, and it has to refuse
/// without falling over. Left alone, every rejection escaped as a 500: a replayed assertion, a
/// SAMLResponse that is not XML, an empty POST. That is the wrong status, it records a refusal that
/// worked as a server fault, and it hands any passer-by a way to fill an error log.</para>
///
/// <para>Handled here at the pipeline edge because the library offers nowhere better: Saml2Notifications
/// has hooks for the successful path -- commands created, metadata created, identity provider selected --
/// and none for a validation failure.</para>
///
/// <para>Scoped to the SAML module path, and catches only the exception categories that mean "this
/// assertion was refused". Anything else still propagates: an unhandled exception from a genuine defect
/// should stay unhandled and be seen, and a handler here that swallowed everything would hide exactly the
/// faults worth knowing about.</para>
///
/// <para>The answer is a redirect to the page carrying an error marker, which is what the Laravel
/// implementation already does. A member whose assertion expired mid-login gets somewhere useful, and a
/// script probing the endpoint learns nothing about why it was refused.</para>
/// </summary>
public static class SamlFailureHandling
{
    public static void UseSamlFailureHandling(this IApplicationBuilder app, string modulePath)
    {
        app.Use(async (context, next) =>
        {
            if (!context.Request.Path.StartsWithSegments(modulePath))
            {
                await next();
                return;
            }

            try
            {
                await next();
            }
            catch (Exception exception) when (IsRejection(exception))
            {
                var logger = context.RequestServices
                    .GetRequiredService<ILoggerFactory>()
                    .CreateLogger(typeof(SamlFailureHandling).FullName!);

                // Warning, not error: refusing an invalid assertion is this endpoint working. The reason
                // is logged because an operator debugging a real login failure needs it; it is not sent
                // to the caller, who is not owed an explanation of which check failed. The path is logged in
                // its escaped form, so a line break or any other control character in it arrives as %0D, %0A
                // and the like and cannot forge a log line.
                logger.LogWarning(exception, "A SAML assertion was refused at {Path}.",
                    context.Request.Path.ToUriComponent());

                if (!context.Response.HasStarted)
                {
                    context.Response.Redirect(SignInFailure.Path);
                }
            }
        });
    }

    /// <summary>
    /// The categories that mean the assertion was refused, rather than something being broken.
    /// </summary>
    private static bool IsRejection(Exception exception) => exception switch
    {
        // The library's own validation: bad format, bad signature, nothing posted, or an
        // InResponseTo that does not match a request this browser made.
        Saml2Exception => true,

        // Replay detection and the rest of the token-level checks live in Microsoft's stack, so they
        // surface as this rather than as one of the library's own.
        SecurityTokenException => true,

        // A SAMLResponse that is not valid base64 fails before any of the above gets to look at it.
        FormatException => true,

        // As does one that decodes but is not XML.
        System.Xml.XmlException => true,

        _ => false,
    };
}
