using System.Net;
using System.Net.Sockets;
using System.Text;
using Astrana.TrustedAttestation.Server.Configuration;
using Astrana.TrustedAttestation.Server.Contract;
using Astrana.TrustedAttestation.Server.Data;
using Astrana.TrustedAttestation.Server.Endpoints;
using Astrana.TrustedAttestation.Server.Security;
using Astrana.TrustedAttestation.Server.Services;
using Astrana.TrustedAttestation.Server.Tests.Support;
using Microsoft.AspNetCore.Authentication.Cookies;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Hosting.Server;
using Microsoft.AspNetCore.Hosting.Server.Features;
using Microsoft.AspNetCore.Http.Features;
using Microsoft.AspNetCore.Server.Kestrel.Core;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Logging;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The 64-kilobyte cap on an API body as Kestrel itself applies it, over a real socket.
///
/// The cap counts the bytes of the body, never the chunk sizes and line breaks of chunked transfer coding, so
/// the same body gets the same answer however it is framed, as on the other two implementations. A body over
/// the cap is answered 413 at once, and the rest is read and thrown away, up to 2 megabytes, before the
/// connection closes, so a reverse proxy still sending it reads the answer rather than meeting a reset
/// connection. A body sent without a session is answered 401 at once and the rest is read and thrown away the
/// same way, and the connection stays usable when the body ends within 2 megabytes.
/// </summary>
public sealed class ApiBodyOverKestrelTests : IAsyncLifetime
{
    private const string AttestPath = "/api/v1/attest";
    private const string KeyPath = "/api/v1/me/relationships/employee/key";
    private static readonly byte[] SmallBody = """{"public_key":"abc"}"""u8.ToArray();

    private WebApplication app = null!;
    private int port;

    public async Task InitializeAsync() => (app, port) = await StartApiAsync(HttpProtocols.Http1);

    /// <summary>The API alone, as Program.cs wires it, on a real socket speaking the given protocols.</summary>
    internal static async Task<(WebApplication App, int Port)> StartApiAsync(HttpProtocols protocols)
    {
        var builder = WebApplication.CreateSlimBuilder();
        builder.WebHost.ConfigureKestrel(kestrel =>
            kestrel.Listen(IPAddress.Loopback, 0, listen => listen.Protocols = protocols));
        builder.Logging.ClearProviders();

        var services = builder.Services;
        services.AddSingleton<MemberIdentityResolver>();
        services.AddSingleton(Microsoft.Extensions.Options.Options.Create(new TrustedAttestationOptions()));
        services.AddSingleton<IRelationshipStore, FakeRelationshipStore>();
        services.AddSingleton<ITransactionRunner, ImmediateTransactionRunner>();
        services.AddSingleton<IAuditWriter, RecordingAuditWriter>();
        services.AddSingleton<ISelfRevokeCommand, RecordingSelfRevoke>();
        services.AddSingleton(RelationshipTypeCatalog.Load());
        services.AddSingleton<RelationshipService>();
        services.AddSingleton(TimeProvider.System);
        services.AddAntiforgery();
        services
            .AddAuthentication(CookieAuthenticationDefaults.AuthenticationScheme)
            .AddCookie(options => SessionCookie.Configure(options, new IamOptions()));
        services.AddAuthorizationBuilder().AddPolicy(ApiEndpoints.PolicyName, policy => policy
            .AddAuthenticationSchemes(CookieAuthenticationDefaults.AuthenticationScheme)
            .RequireAuthenticatedUser());

        var api = builder.Build();
        api.UseStatusOnlyErrors();
        api.UseAuthentication();
        api.UseAuthorization();
        api.MapTrustedAttestationApi();

        await api.StartAsync();
        var address = api.Services.GetRequiredService<IServer>().Features.GetRequiredFeature<IServerAddressesFeature>()
            .Addresses.Single();
        return (api, new Uri(address).Port);
    }

    public async Task DisposeAsync() => await app.DisposeAsync();

    private static byte[] JsonPaddedTo(int length)
    {
        const string start = """{"public_key":"abc" """;
        return Encoding.ASCII.GetBytes(start + new string(' ', length - start.Length - 1) + "}");
    }

    private static byte[] Chunked(byte[] body, int chunkSize)
    {
        using var framed = new MemoryStream();
        for (var offset = 0; offset < body.Length; offset += chunkSize)
        {
            var size = Math.Min(chunkSize, body.Length - offset);
            framed.Write(Encoding.ASCII.GetBytes($"{size:x}\r\n"));
            framed.Write(body, offset, size);
            framed.Write("\r\n"u8);
        }

        framed.Write("0\r\n\r\n"u8);
        return framed.ToArray();
    }

    private static byte[] Request(string method, string path, byte[] body, int? chunkSize = null)
    {
        var framing = chunkSize is null ? $"Content-Length: {body.Length}" : "Transfer-Encoding: chunked";
        var head = Encoding.ASCII.GetBytes(
            $"{method} {path} HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\n{framing}\r\n\r\n");

        return [.. head, .. chunkSize is null ? body : Chunked(body, chunkSize.Value)];
    }

    /// <summary>Sends the requests one after another on one connection and reads an answer to each.</summary>
    private async Task<IReadOnlyList<int>> SendOnOneConnection(params byte[][] requests)
    {
        using var client = new TcpClient();
        await client.ConnectAsync(IPAddress.Loopback, port);
        await using var stream = client.GetStream();
        var reader = new RawResponseReader(stream);
        var statuses = new List<int>();

        foreach (var request in requests)
        {
            await stream.WriteAsync(request);
            statuses.Add(await reader.ReadStatusAsync());
        }

        return statuses;
    }

    [Fact]
    public async Task A_body_under_the_cap_in_small_chunks_is_answered_200()
    {
        var statuses = await SendOnOneConnection(Request("POST", AttestPath, JsonPaddedTo(63_000), chunkSize: 100));

        Assert.Equal([200], statuses);
    }

    [Fact]
    public async Task A_body_of_exactly_the_cap_is_answered_200_in_one_chunk_as_with_a_declared_length()
    {
        var body = JsonPaddedTo(PublicKeyBody.MaxBytes);

        var statuses = await SendOnOneConnection(
            Request("POST", AttestPath, body), Request("POST", AttestPath, body, chunkSize: body.Length));

        Assert.Equal([200, 200], statuses);
    }

    [Fact]
    public async Task A_chunked_body_over_the_cap_is_answered_413_and_the_connection_then_closes_cleanly()
    {
        var (status, closedCleanly) = await SendThenWaitForClose(
            Request("POST", AttestPath, JsonPaddedTo(PublicKeyBody.MaxBytes + 1), chunkSize: 4096));

        Assert.Equal(413, status);
        Assert.True(closedCleanly);
    }

    [Fact]
    public async Task A_declared_body_over_the_cap_is_answered_413_and_the_connection_then_closes_cleanly()
    {
        var (status, closedCleanly) = await SendThenWaitForClose(Request("POST", AttestPath, JsonPaddedTo(80 * 1024)));

        Assert.Equal(413, status);
        Assert.True(closedCleanly);
    }

    /// <summary>
    /// Sends one whole request, reads the answer, and reports whether the server then closed the connection
    /// normally rather than resetting it.
    /// </summary>
    private async Task<(int Status, bool ClosedCleanly)> SendThenWaitForClose(byte[] request)
    {
        using var client = new TcpClient();
        await client.ConnectAsync(IPAddress.Loopback, port);
        await using var stream = client.GetStream();

        await stream.WriteAsync(request);
        var status = await new RawResponseReader(stream).ReadStatusAsync();
        var read = await stream.ReadAsync(new byte[1]).AsTask().WaitAsync(TimeSpan.FromSeconds(10));

        return (status, read == 0);
    }

    [Fact]
    public async Task A_body_over_the_cap_without_a_session_is_answered_401_and_the_connection_stays_usable()
    {
        var statuses = await SendOnOneConnection(
            Request("PUT", KeyPath, JsonPaddedTo(80 * 1024)),
            Request("PUT", KeyPath, JsonPaddedTo(80 * 1024), chunkSize: 4096),
            Request("POST", AttestPath, SmallBody));

        Assert.Equal([401, 401, 200], statuses);
    }

    [Fact]
    public async Task A_body_declared_far_over_the_cap_is_answered_413_and_the_rest_is_read_until_the_sender_stops()
    {
        var (status, sentAll, ended) = await SendLikeAProxy("POST", AttestPath);

        Assert.Equal(413, status);
        Assert.True(sentAll);
        Assert.True(ended);
    }

    [Fact]
    public async Task A_body_declared_far_over_the_cap_without_a_session_is_answered_401_and_the_rest_is_read_until_the_sender_stops()
    {
        var (status, sentAll, ended) = await SendLikeAProxy("PUT", KeyPath);

        Assert.Equal(401, status);
        Assert.True(sentAll);
        Assert.True(ended);
    }

    [Fact]
    public async Task A_401_to_a_body_declared_over_the_discard_bound_tells_the_sender_the_connection_closes()
    {
        // A reverse proxy keeps sending the body after an answer that leaves the connection open, so it can be
        // used again. Told the connection closes, it stops. Tomcat says so on the same condition.
        using var client = new TcpClient();
        await client.ConnectAsync(IPAddress.Loopback, port);
        await using var stream = client.GetStream();
        var declared = ApiBodyLimit.DiscardBytes + 1;
        await stream.WriteAsync(Encoding.ASCII.GetBytes(
            $"PUT {KeyPath} HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\n" +
            $"Content-Length: {declared}\r\n\r\n"));
        await stream.WriteAsync(new byte[64 * 1024]);
        var reader = new RawResponseReader(stream);

        Assert.Equal(401, await reader.ReadStatusAsync());
        Assert.Equal("close", reader.LastHeaders.GetValueOrDefault("Connection"));
    }

    /// <summary>
    /// Sends a request the way a reverse proxy does: the body's length declared as 3 megabytes, part of it sent,
    /// the answer read, 1.5 megabytes more sent before the proxy reacts, then no more. Closing under the proxy
    /// while the body is still arriving resets the connection, and the proxy then answers 502 instead of passing
    /// on the server's answer. Reports the answer, whether everything after it could still be sent, and whether
    /// the connection then ended. Kestrel may end it with a reset once it finds the body cut short of its
    /// declared length, which is harmless by then: the sender has the answer and has stopped.
    /// </summary>
    private async Task<(int Status, bool SentAll, bool Ended)> SendLikeAProxy(string method, string path)
    {
        const int declared = 3 * 1024 * 1024;
        using var client = new TcpClient();
        await client.ConnectAsync(IPAddress.Loopback, port);
        await using var stream = client.GetStream();
        var head = Encoding.ASCII.GetBytes(
            $"{method} {path} HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\n" +
            $"Content-Length: {declared}\r\n\r\n");
        var block = new byte[64 * 1024];
        Array.Fill(block, (byte)' ');

        await stream.WriteAsync(head);
        await stream.WriteAsync(block);
        await stream.WriteAsync(block);
        var status = await new RawResponseReader(stream).ReadStatusAsync();

        try
        {
            for (var sent = 0; sent < 1536 * 1024; sent += block.Length)
            {
                await stream.WriteAsync(block);
            }
        }
        catch (IOException)
        {
            return (status, false, true);
        }

        client.Client.Shutdown(SocketShutdown.Send);
        return (status, true, await EndsAsync(stream));
    }

    /// <summary>Whether the server ends the connection within ten seconds, by closing it or resetting it.</summary>
    private static async Task<bool> EndsAsync(Stream stream)
    {
        try
        {
            return await stream.ReadAsync(new byte[4096]).AsTask().WaitAsync(TimeSpan.FromSeconds(10)) == 0;
        }
        catch (IOException ended) when (ended.InnerException is SocketException { SocketErrorCode: SocketError.ConnectionReset })
        {
            return true;
        }
    }

    /// <summary>
    /// Reads HTTP/1.1 responses off a stream, one at a time, keeping whatever arrives past the end of one for
    /// the next. Enough for the answers here: a status line, headers, and a body framed by Content-Length or
    /// chunked transfer coding.
    /// </summary>
    private sealed class RawResponseReader(Stream stream)
    {
        private readonly List<byte> pending = [];

        /// <summary>The headers of the answer <see cref="ReadStatusAsync"/> last read.</summary>
        public IReadOnlyDictionary<string, string> LastHeaders { get; private set; } = new Dictionary<string, string>();

        public async Task<int> ReadStatusAsync()
        {
            var statusLine = await ReadLineAsync();
            var headers = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
            LastHeaders = headers;
            for (var line = await ReadLineAsync(); line.Length > 0; line = await ReadLineAsync())
            {
                var colon = line.IndexOf(':');
                headers[line[..colon]] = line[(colon + 1)..].Trim();
            }

            if (headers.TryGetValue("Content-Length", out var length))
            {
                await TakeAsync(int.Parse(length));
            }
            else if (headers.ContainsKey("Transfer-Encoding"))
            {
                await SkipChunkedBodyAsync();
            }

            return int.Parse(statusLine.Split(' ')[1]);
        }

        private async Task SkipChunkedBodyAsync()
        {
            int size;
            do
            {
                size = Convert.ToInt32(await ReadLineAsync(), 16);
                await TakeAsync(size);
                await ReadLineAsync();
            }
            while (size > 0);
        }

        private async Task<string> ReadLineAsync()
        {
            int end;
            while ((end = IndexOfLineEnd()) < 0)
            {
                await FillAsync();
            }

            var line = Encoding.ASCII.GetString([.. pending.Take(end)]);
            pending.RemoveRange(0, end + 2);
            return line;
        }

        private async Task TakeAsync(int count)
        {
            while (pending.Count < count)
            {
                await FillAsync();
            }

            pending.RemoveRange(0, count);
        }

        private int IndexOfLineEnd()
        {
            for (var i = 0; i + 1 < pending.Count; i++)
            {
                if (pending[i] == '\r' && pending[i + 1] == '\n')
                {
                    return i;
                }
            }

            return -1;
        }

        private async Task FillAsync()
        {
            var buffer = new byte[4096];
            var read = await stream.ReadAsync(buffer).AsTask().WaitAsync(TimeSpan.FromSeconds(10));
            if (read == 0)
            {
                throw new IOException("The server closed the connection.");
            }

            pending.AddRange(buffer.AsSpan(0, read).ToArray());
        }
    }
}
