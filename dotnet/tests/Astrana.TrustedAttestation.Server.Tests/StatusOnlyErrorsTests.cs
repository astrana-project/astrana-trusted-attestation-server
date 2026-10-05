using Astrana.TrustedAttestation.Server.Security;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Http.Features;
using Microsoft.Extensions.DependencyInjection;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// What an unhandled exception becomes: the status, the security headers, and nothing else, in every
/// environment. The framework's own answers either write a stack trace into the body (the developer
/// exception page) or drop the headers the response already had (the bare 500), and both break the rule
/// that every error this server emits is status-only.
/// </summary>
public class StatusOnlyErrorsTests
{
    /// <summary>A response whose headers have already gone to the client.</summary>
    private sealed class StartedResponse : IHttpResponseFeature
    {
        public int StatusCode { get; set; } = StatusCodes.Status200OK;
        public string? ReasonPhrase { get; set; }
        public IHeaderDictionary Headers { get; set; } = new HeaderDictionary();
        public Stream Body { get; set; } = Stream.Null;
        public bool HasStarted => true;
        public void OnStarting(Func<object, Task> callback, object state) { }
        public void OnCompleted(Func<object, Task> callback, object state) { }
    }

    private static async Task<HttpContext> RunWith(Exception thrown, Action<HttpResponse>? beforeThrowing = null)
    {
        var services = new ServiceCollection().AddLogging().BuildServiceProvider();
        var builder = new ApplicationBuilder(services);
        builder.UseStatusOnlyErrors();
        builder.UseSecurityHeaders();
        builder.Run(context =>
        {
            beforeThrowing?.Invoke(context.Response);
            throw thrown;
        });

        var context = new DefaultHttpContext { RequestServices = services };
        context.Response.Body = new MemoryStream();
        await builder.Build()(context);
        return context;
    }

    [Fact]
    public async Task An_unhandled_exception_is_a_500_with_the_security_headers_and_no_body()
    {
        var context = await RunWith(new InvalidOperationException("the database is unreachable"));

        Assert.Equal(StatusCodes.Status500InternalServerError, context.Response.StatusCode);
        Assert.Equal("nosniff", context.Response.Headers.XContentTypeOptions);
        Assert.Equal("DENY", context.Response.Headers.XFrameOptions);
        Assert.Equal("no-referrer", context.Response.Headers["Referrer-Policy"]);
        Assert.Equal("0", context.Response.Headers.XXSSProtection);
        Assert.Contains("no-store", context.Response.Headers.CacheControl.ToString());
        Assert.Null(context.Response.ContentType);
        Assert.Equal(0, context.Response.Body.Length);
    }

    [Fact]
    public async Task Whatever_the_handler_had_set_before_failing_is_cleared()
    {
        // A handler that set a content type and a status and then threw must not leak either: the error
        // says nothing beyond its status.
        var context = await RunWith(new InvalidOperationException("mid-way"), response =>
        {
            response.StatusCode = StatusCodes.Status201Created;
            response.ContentType = "text/html";
            response.Headers.Location = "/somewhere";
        });

        Assert.Equal(StatusCodes.Status500InternalServerError, context.Response.StatusCode);
        Assert.Null(context.Response.ContentType);
        Assert.False(context.Response.Headers.ContainsKey("Location"));
    }

    [Fact]
    public async Task Strict_transport_security_set_further_out_is_kept()
    {
        // The HSTS middleware sets its header before the request reaches the handler. Clearing the
        // response must not lose it, or a 500 would be the one response without it.
        var context = await RunWith(new InvalidOperationException("mid-way"),
            response => response.Headers.StrictTransportSecurity = "max-age=31536000");

        Assert.Equal("max-age=31536000", context.Response.Headers.StrictTransportSecurity);
    }

    [Fact]
    public async Task A_bad_request_keeps_its_own_status()
    {
        // The server refusing a body over the cap says 413, and that is the caller's answer, not a 500
        // blaming the server for the caller's request.
        var context = await RunWith(new BadHttpRequestException("too large", StatusCodes.Status413PayloadTooLarge));

        Assert.Equal(StatusCodes.Status413PayloadTooLarge, context.Response.StatusCode);
        Assert.Equal("nosniff", context.Response.Headers.XContentTypeOptions);
        Assert.Null(context.Response.ContentType);
        Assert.Equal(0, context.Response.Body.Length);
    }

    [Fact]
    public void The_status_mapping_is_the_exceptions_own_for_a_bad_request_and_500_otherwise()
    {
        Assert.Equal(413, StatusOnlyErrors.StatusFor(new BadHttpRequestException("x", 413)));
        Assert.Equal(400, StatusOnlyErrors.StatusFor(new BadHttpRequestException("x")));
        Assert.Equal(500, StatusOnlyErrors.StatusFor(new NullReferenceException()));
        Assert.Equal(500, StatusOnlyErrors.StatusFor(new FormatException()));
    }

    [Fact]
    public async Task A_response_that_has_started_cannot_be_rewritten_and_the_exception_propagates()
    {
        var services = new ServiceCollection().AddLogging().BuildServiceProvider();
        var builder = new ApplicationBuilder(services);
        builder.UseStatusOnlyErrors();
        builder.Run(context =>
        {
            // A bare context never marks itself started, so a feature says so directly: what a server
            // reports once the headers have been flushed.
            context.Features.Set<IHttpResponseFeature>(new StartedResponse());
            throw new InvalidOperationException("after the headers went out");
        });

        var context = new DefaultHttpContext { RequestServices = services };
        context.Response.Body = new MemoryStream();

        await Assert.ThrowsAsync<InvalidOperationException>(() => builder.Build()(context));
    }
}
