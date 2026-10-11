using System.Net;
using System.Net.Http.Headers;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Server.Kestrel.Core;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// Refusing a body the server does not read, over HTTP/2, which Kestrel offers when it terminates TLS itself.
/// HTTP/2 has no Connection header and carries many requests on one connection, so a refusal ends only its own
/// stream: the answer still arrives, and the connection stays usable for the next request.
/// </summary>
public sealed class ApiBodyOverHttp2Tests : IAsyncLifetime
{
    private WebApplication app = null!;
    private HttpClient client = null!;

    public async Task InitializeAsync()
    {
        int port;
        (app, port) = await ApiBodyOverKestrelTests.StartApiAsync(HttpProtocols.Http2);
        client = new HttpClient
        {
            BaseAddress = new Uri($"http://127.0.0.1:{port}"),
            DefaultRequestVersion = HttpVersion.Version20,
            DefaultVersionPolicy = HttpVersionPolicy.RequestVersionExact,
            Timeout = TimeSpan.FromSeconds(20),
        };
    }

    public async Task DisposeAsync()
    {
        client.Dispose();
        await app.DisposeAsync();
    }

    private static ByteArrayContent Json(int length)
    {
        var body = new byte[length];
        Array.Fill(body, (byte)' ');
        var content = new ByteArrayContent(body);
        content.Headers.ContentType = new MediaTypeHeaderValue("application/json");
        return content;
    }

    [Theory]
    [InlineData(80 * 1024)]
    [InlineData(3 * 1024 * 1024)]
    public async Task A_body_over_the_cap_is_answered_413_and_the_connection_stays_usable(int length)
    {
        var refused = await client.PostAsync("/api/v1/attest", Json(length));
        var next = await client.PostAsync("/api/v1/attest", Json(16));

        Assert.Equal(HttpStatusCode.RequestEntityTooLarge, refused.StatusCode);
        Assert.Equal(HttpStatusCode.OK, next.StatusCode);
    }

    [Theory]
    [InlineData(80 * 1024)]
    [InlineData(3 * 1024 * 1024)]
    public async Task A_body_without_a_session_is_answered_401_and_the_connection_stays_usable(int length)
    {
        var refused = await client.PutAsync("/api/v1/me/relationships/employee/key", Json(length));
        var next = await client.PostAsync("/api/v1/attest", Json(16));

        Assert.Equal(HttpStatusCode.Unauthorized, refused.StatusCode);
        Assert.Equal(HttpStatusCode.OK, next.StatusCode);
    }
}
