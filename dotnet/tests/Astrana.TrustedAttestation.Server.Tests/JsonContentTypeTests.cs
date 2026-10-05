using Astrana.TrustedAttestation.Server.Endpoints;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Http.Features;
using Microsoft.Extensions.DependencyInjection;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// JSON goes out as a bare <c>application/json</c>, as the other two implementations send it, so the manifest
/// and every API answer are byte-identical across all three. Every other content type is left alone.
/// </summary>
public class JsonContentTypeTests
{
    /// <summary>A response feature that keeps the callbacks registered to run as the response starts.</summary>
    private sealed class StartingResponseFeature : HttpResponseFeature
    {
        private readonly List<(Func<object, Task> Callback, object State)> _starting = [];

        public override void OnStarting(Func<object, Task> callback, object state) => _starting.Add((callback, state));

        public async Task StartAsync()
        {
            foreach (var (callback, state) in _starting)
            {
                await callback(state);
            }
        }
    }

    [Theory]
    [InlineData("application/json; charset=utf-8", "application/json")]
    [InlineData("Application/JSON; Charset=UTF-8", "application/json")]
    [InlineData("application/json", "application/json")]
    [InlineData("application/problem+json; charset=utf-8", "application/problem+json; charset=utf-8")]
    [InlineData("text/html; charset=utf-8", "text/html; charset=utf-8")]
    [InlineData(null, null)]
    public void Only_a_json_content_type_with_a_charset_is_trimmed(string? contentType, string? expected)
    {
        var response = new DefaultHttpContext().Response;
        response.ContentType = contentType;

        JsonContentType.DropCharset(response);

        Assert.Equal(expected, response.ContentType);
    }

    [Fact]
    public async Task The_middleware_trims_the_content_type_the_endpoint_set_as_the_response_starts()
    {
        var feature = new StartingResponseFeature();
        var http = new DefaultHttpContext();
        http.Features.Set<IHttpResponseFeature>(feature);

        var app = new ApplicationBuilder(new ServiceCollection().BuildServiceProvider());
        app.UseBareJsonContentType();
        app.Run(context =>
        {
            context.Response.ContentType = "application/json; charset=utf-8";
            return Task.CompletedTask;
        });

        await app.Build()(http);
        Assert.Equal("application/json; charset=utf-8", http.Response.ContentType);

        await feature.StartAsync();
        Assert.Equal("application/json", http.Response.ContentType);
    }
}
