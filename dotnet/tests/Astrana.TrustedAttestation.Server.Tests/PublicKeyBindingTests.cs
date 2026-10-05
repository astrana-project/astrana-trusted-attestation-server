using System.Text;
using Astrana.TrustedAttestation.Server.Endpoints;
using Microsoft.AspNetCore.Http;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// How the request body's <c>public_key</c> is read for /attest and the key PUT.
///
/// Bound by hand rather than by the framework's model binding, so that a public_key which is a number,
/// an object or an array -- or a body that is not JSON at all -- reaches the handler as "no key" instead
/// of being rejected with a 400 before it runs. /attest must answer 200 whatever arrives, and the key
/// PUT must answer its own 400 with no body rather than a framework error page. Each framework's default
/// binding reads this differently, so it is pinned here rather than only exercised by a live login.
///
/// A byte order mark, anything after the JSON value, and invalid UTF-8 inside or outside the string are
/// malformed, so read as no key, in all three implementations. A body over 64 kilobytes is refused with
/// 413 before it is parsed.
/// </summary>
public class PublicKeyBindingTests
{
    private static HttpContext RequestWith(byte[] body, string? contentType = null)
    {
        var context = new DefaultHttpContext();
        context.Request.Body = new MemoryStream(body);
        context.Request.ContentType = contentType;
        return context;
    }

    private static async Task<string?> ReadPublicKey(byte[] body, string? contentType = null)
    {
        var request = await AttestRequest.BindAsync(RequestWith(body, contentType));

        return request!.PublicKey;
    }

    private static Task<string?> ReadPublicKey(string body) => ReadPublicKey(Encoding.UTF8.GetBytes(body));

    [Fact]
    public async Task A_json_string_public_key_is_read()
    {
        Assert.Equal("a-key-value", await ReadPublicKey("""{"public_key":"a-key-value"}"""));
    }

    [Theory]
    [InlineData("""{"public_key":1234}""")]          // a number
    [InlineData("""{"public_key":true}""")]          // a boolean
    [InlineData("""{"public_key":{}}""")]            // an object
    [InlineData("""{"public_key":["a","b"]}""")]     // an array, which each framework binds differently by default
    [InlineData("""{"public_key":null}""")]          // an explicit null
    [InlineData("""{"other":"x"}""")]                // absent
    [InlineData("""["a","b"]""")]                    // not even an object
    [InlineData("not json at all")]                  // not JSON
    [InlineData("")]                                 // an empty body
    [InlineData("""{"public_key":"abc"} trailing""")] // something after the value
    [InlineData("""{"public_key":"abc"}{}""")]        // a second value
    public async Task A_public_key_that_is_not_a_json_string_reads_as_absent(string body)
    {
        Assert.Null(await ReadPublicKey(body));
    }

    [Fact]
    public async Task A_body_starting_with_a_byte_order_mark_is_malformed()
    {
        // The stream parser would skip it silently and read the key. JSON text must not begin with one,
        // and the other two implementations refuse it, so this one does too.
        byte[] body = [0xEF, 0xBB, 0xBF, .. Encoding.UTF8.GetBytes("""{"public_key":"abc"}""")];

        Assert.Null(await ReadPublicKey(body));
    }

    [Fact]
    public async Task Invalid_utf8_inside_the_string_is_malformed_not_a_server_fault()
    {
        // The reader validates a string's bytes only when it decodes them, and reports a bad sequence
        // with a different exception from a parse error. Either way it is a malformed body.
        byte[] body = [.. Encoding.UTF8.GetBytes("{\"public_key\":\""), 0xC3, 0x28, .. Encoding.UTF8.GetBytes("\"}")];

        Assert.Null(await ReadPublicKey(body));
    }

    [Fact]
    public async Task Invalid_utf8_outside_the_string_is_malformed()
    {
        byte[] body = [.. Encoding.UTF8.GetBytes("{\"public_key\":\"abc\""), 0xFF, .. Encoding.UTF8.GetBytes("}")];

        Assert.Null(await ReadPublicKey(body));
    }

    [Theory]
    [InlineData("application/x-www-form-urlencoded")]
    [InlineData("text/plain")]
    [InlineData(null)]
    public async Task The_body_is_read_whatever_the_content_type_says(string? contentType)
    {
        var body = Encoding.UTF8.GetBytes("""{"public_key":"abc"}""");

        Assert.Equal("abc", await ReadPublicKey(body, contentType));
    }

    [Fact]
    public async Task A_body_of_exactly_the_cap_is_read()
    {
        var json = """{"public_key":"abc"}""";
        var body = Encoding.UTF8.GetBytes(json + new string(' ', PublicKeyBody.MaxBytes - json.Length));

        Assert.Equal(PublicKeyBody.MaxBytes, body.Length);
        Assert.Equal("abc", await ReadPublicKey(body));
    }

    [Fact]
    public async Task A_body_over_the_cap_is_refused_with_413_before_it_is_parsed()
    {
        var body = Encoding.UTF8.GetBytes(new string(' ', PublicKeyBody.MaxBytes + 1));

        var refusal = await Assert.ThrowsAsync<BadHttpRequestException>(() => ReadPublicKey(body));

        Assert.Equal(StatusCodes.Status413PayloadTooLarge, refusal.StatusCode);
    }

    [Fact]
    public void The_cap_is_64_kilobytes_in_all_three_implementations()
    {
        Assert.Equal(65_536, PublicKeyBody.MaxBytes);
        Assert.Equal(65_536, ApiBodyLimit.Instance.MaxRequestBodySize);
    }

    [Fact]
    public async Task The_key_put_binds_the_same_body_the_same_way()
    {
        var request = await SetKeyRequest.BindAsync(RequestWith(Encoding.UTF8.GetBytes("""{"public_key":"xyz"}""")));

        Assert.Equal("xyz", request!.PublicKey);
    }
}
