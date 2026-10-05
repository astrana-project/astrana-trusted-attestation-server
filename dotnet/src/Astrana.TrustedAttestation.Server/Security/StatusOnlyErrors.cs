using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.Extensions.Primitives;

namespace Astrana.TrustedAttestation.Server.Security;

/// <summary>
/// Turns an unhandled exception into a status code and nothing else.
///
/// <para>Every error this server emits is status-only: no body, no content type. The framework's own
/// handling breaks that in two ways. In the Development environment the developer exception page writes a
/// stack trace into the body, and in every other environment an unhandled exception produces a bare 500
/// that has lost the security headers the response had already been given. This middleware is registered
/// first, so it wraps everything, and answers the same way in every environment: the status, the security
/// headers, and an empty body.</para>
///
/// <para>A <see cref="BadHttpRequestException"/> keeps its own status, because it is the server saying the
/// request itself was unacceptable (a body over the limit is 413, a malformed one is 400) and that is the
/// answer the caller should get, not a 500 that blames the server.</para>
/// </summary>
public static class StatusOnlyErrors
{
    public static void UseStatusOnlyErrors(this IApplicationBuilder app)
    {
        app.Use(async (context, next) =>
        {
            try
            {
                await next(context);
            }
            catch (Exception exception)
            {
                var logger = (context.RequestServices?.GetService<ILoggerFactory>() ?? NullLoggerFactory.Instance)
                    .CreateLogger(typeof(StatusOnlyErrors).FullName!);

                if (context.RequestAborted.IsCancellationRequested)
                {
                    // The client has gone. There is nobody to answer, and an abort is not a fault.
                    logger.LogDebug(exception, "The request was aborted before it completed.");
                    return;
                }

                var status = StatusFor(exception);
                if (status == StatusCodes.Status500InternalServerError)
                {
                    logger.LogError(exception, "An unhandled exception was answered with HTTP 500.");
                }
                else
                {
                    logger.LogWarning(exception, "A bad request was answered with HTTP {Status}.", status);
                }

                if (context.Response.HasStarted)
                {
                    // Too late to change the answer. Rethrowing lets the server close the connection, which
                    // is the only honest thing left to do with a response that is half-written.
                    throw;
                }

                Answer(context.Response, status);
            }
        });
    }

    /// <summary>
    /// The status an exception maps to: a request the server refused keeps the status it was refused
    /// with, anything else is a server fault.
    /// </summary>
    internal static int StatusFor(Exception exception) => exception switch
    {
        BadHttpRequestException bad => bad.StatusCode,
        _ => StatusCodes.Status500InternalServerError,
    };

    /// <summary>
    /// Resets the response to the status alone. Whatever a handler had set is cleared, then the security
    /// headers are put back, and Strict-Transport-Security is kept if the HSTS middleware had set it, so
    /// the error carries exactly the headers every other response does.
    /// </summary>
    private static void Answer(HttpResponse response, int status)
    {
        var hsts = response.Headers.StrictTransportSecurity;

        response.Clear();
        response.StatusCode = status;
        SecurityHeaders.Apply(response.Headers);

        if (!StringValues.IsNullOrEmpty(hsts))
        {
            response.Headers.StrictTransportSecurity = hsts;
        }
    }
}
