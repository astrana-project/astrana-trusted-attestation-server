using Astrana.TrustedAttestation.Server.Endpoints;
using Microsoft.AspNetCore.Http.Features;

namespace Astrana.TrustedAttestation.Server.Security;

/// <summary>
/// Sends a refusal that leaves the request body unread at once, then reads and throws away up to
/// <see cref="ApiBodyLimit.DiscardBytes"/> of the body, as Tomcat does under the Java implementation. That is
/// HTTP 413 for a body over the cap, and HTTP 401 for a body sent without a session.
///
/// <para>Left to Kestrel, a body declared larger than its limit is not read at all after the answer, and the
/// connection closes at once. A reverse proxy still sending that body then meets a reset connection, and if it
/// sees the reset before the answer it replies HTTP 502 - Bad Gateway instead. Reading on gives the proxy time
/// to read the answer, stop sending and close the connection itself. The bytes are discarded as they arrive,
/// so this costs bandwidth, never memory.</para>
///
/// <para>After 413 the connection closes. After 401 it stays open for the next request, unless the body is
/// declared longer than the bound, as with Tomcat. Reading stops when the body ends or the sender stops. Past the bound, or after <see cref="DiscardTime"/>,
/// the connection is aborted, which also keeps Kestrel from reading the rest of the body itself.</para>
/// </summary>
internal static class RefusedBody
{
    private static readonly TimeSpan DiscardTime = TimeSpan.FromSeconds(5);

    public static Task FinishAsync(HttpContext context) => context.Response.StatusCode switch
    {
        _ when !IsHttp1(context) => Task.CompletedTask,
        StatusCodes.Status413PayloadTooLarge => AnswerThenDiscardAsync(context, closeConnection: true),
        StatusCodes.Status401Unauthorized when HasBody(context) =>
            AnswerThenDiscardAsync(context, closeConnection: DeclaredOverDiscardBound(context)),
        _ => Task.CompletedTask,
    };

    /// <summary>
    /// Only HTTP/1.x sends one body at a time over the whole connection, so only there can closing it reset a
    /// body still arriving. HTTP/2 and HTTP/3 end the one request's stream instead, and Kestrel already tells
    /// the client cleanly to stop sending.
    /// </summary>
    private static bool IsHttp1(HttpContext context) =>
        HttpProtocol.IsHttp11(context.Request.Protocol) || HttpProtocol.IsHttp10(context.Request.Protocol);

    private static bool HasBody(HttpContext context) =>
        context.Features.Get<IHttpRequestBodyDetectionFeature>()?.CanHaveBody ?? false;

    /// <summary>
    /// Whether the body is declared longer than will be discarded, Tomcat's own test for closing the
    /// connection. Told the connection closes, a reverse proxy stops sending. Otherwise it sends the whole
    /// body so the connection can be used again, and would meet a reset once the discarding stopped.
    /// </summary>
    private static bool DeclaredOverDiscardBound(HttpContext context) =>
        context.Request.ContentLength > ApiBodyLimit.DiscardBytes;

    private static async Task AnswerThenDiscardAsync(HttpContext context, bool closeConnection)
    {
        LiftServerLimit(context);
        if (closeConnection)
        {
            context.Response.Headers.Connection = "close";
        }

        await context.Response.CompleteAsync();

        if (!await DiscardAsync(context.Request.Body, context.RequestAborted))
        {
            context.Abort();
        }
    }

    /// <summary>
    /// Lets the rest of the body be read. Kestrel refuses to read any of a body declared larger than its limit,
    /// so the limit is lifted for this one request, which is possible only while nothing has been read yet.
    /// Once reading has begun, Kestrel's own limit stays and ends the discarding at that size.
    /// </summary>
    private static void LiftServerLimit(HttpContext context)
    {
        var limit = context.Features.Get<IHttpMaxRequestBodySizeFeature>();
        if (limit is { IsReadOnly: false })
        {
            limit.MaxRequestBodySize = null;
        }
    }

    /// <summary>Whether the body ended, or the sender stopped, before the bound or the time ran out.</summary>
    private static async Task<bool> DiscardAsync(Stream body, CancellationToken aborted)
    {
        using var deadline = CancellationTokenSource.CreateLinkedTokenSource(aborted);
        deadline.CancelAfter(DiscardTime);
        var buffer = new byte[16 * 1024];
        long discarded = 0;

        try
        {
            while (discarded <= ApiBodyLimit.DiscardBytes)
            {
                var read = await body.ReadAsync(buffer, deadline.Token);
                if (read == 0)
                {
                    return true;
                }

                discarded += read;
            }
        }
        catch (OperationCanceledException)
        {
            return false;
        }
        catch (Exception stopped) when (stopped is IOException or BadHttpRequestException)
        {
            // The sender stopped before the declared end, which is what a proxy does once it has the answer,
            // or Kestrel's own limit ended a body it had already begun reading.
            return stopped is IOException || !IsServerLimit(stopped);
        }

        return false;
    }

    private static bool IsServerLimit(Exception exception) =>
        exception is BadHttpRequestException { StatusCode: StatusCodes.Status413PayloadTooLarge };
}
