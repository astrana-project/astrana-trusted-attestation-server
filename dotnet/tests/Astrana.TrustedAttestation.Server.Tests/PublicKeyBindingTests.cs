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
    private static DefaultHttpContext RequestWith(byte[] body, string? contentType = null)
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
        byte[] body = [.. Encoding.UTF8.GetBytes("{\"public_key\":\""), 0xC3, 0x28, .. Encoding.UTF8.GetBytes("\"}")];

        Assert.Null(await ReadPublicKey(body));
    }

    [Fact]
    public async Task A_key_holding_an_escaped_lone_surrogate_is_malformed_not_a_server_fault()
    {
        // The reader reports a string that does not decode to text with a different exception from a
        // parse error. Either way it is a malformed body.
        Assert.Null(await ReadPublicKey("""{"public_key":"\ud800"}"""));
    }

    [Fact]
    public async Task Invalid_utf8_outside_the_string_is_malformed()
    {
        byte[] body = [.. Encoding.UTF8.GetBytes("{\"public_key\":\"abc\""), 0xFF, .. Encoding.UTF8.GetBytes("}")];

        Assert.Null(await ReadPublicKey(body));
    }

    private static string KeyBeside(string member) => $$"""{"public_key":"abc","n":{{member}}}""";

    /// <summary>Arrays nested inside the body's object, so the body is one deeper than this.</summary>
    private static string Arrays(int depth) => new string('[', depth) + new string(']', depth);

    private static string Digits(int count) => new('9', count);

    [Theory]
    [InlineData(64)]
    [InlineData(1000)]
    public async Task A_body_nested_more_than_64_deep_is_malformed(int arrays)
    {
        Assert.Null(await ReadPublicKey(KeyBeside(Arrays(arrays))));
    }

    [Fact]
    public async Task A_body_nested_64_deep_counting_its_own_object_is_read()
    {
        Assert.Equal("abc", await ReadPublicKey(KeyBeside(Arrays(63))));
    }

    public static TheoryData<string> NumbersOfMoreThan1000Digits => new()
    {
        Digits(1001),
        "-" + Digits(1001),
        Digits(1000) + "e5",   // the exponent's digits count
        "1." + Digits(1000),   // and the fraction's
    };

    public static TheoryData<string> NumbersOf1000Digits => new()
    {
        Digits(1000),
        "-" + Digits(1000),    // a sign is not a digit
        Digits(998) + "e+2",
        "1." + Digits(998) + "E-1",
    };

    [Theory]
    [MemberData(nameof(NumbersOfMoreThan1000Digits))]
    public async Task A_body_with_a_number_of_more_than_1000_digits_is_malformed(string number)
    {
        Assert.Null(await ReadPublicKey(KeyBeside(number)));
    }

    [Theory]
    [MemberData(nameof(NumbersOf1000Digits))]
    public async Task A_body_with_a_number_of_1000_digits_is_read(string number)
    {
        Assert.Equal("abc", await ReadPublicKey(KeyBeside(number)));
    }

    [Theory]
    [InlineData(""" "\ud800" """)]                 // a high surrogate alone
    [InlineData(""" "\udc00" """)]                 // a low surrogate alone
    [InlineData(""" "\udc00\ud800" """)]           // a pair the wrong way round
    [InlineData(""" "\ud800A" """)]           // a high surrogate before something else
    [InlineData(""" ["x", {"y": "\ud800"}] """)]   // nested
    [InlineData(""" {"\ud800": 1} """)]            // in a name
    public async Task A_body_with_an_escaped_lone_surrogate_anywhere_is_malformed(string member)
    {
        Assert.Null(await ReadPublicKey(KeyBeside(member)));
    }

    [Fact]
    public async Task An_escaped_lone_surrogate_under_a_name_used_twice_is_malformed()
    {
        Assert.Null(await ReadPublicKey("""{"n":"\ud800","n":"x","public_key":"abc"}"""));
    }

    [Fact]
    public async Task A_body_with_an_escaped_surrogate_pair_is_read()
    {
        Assert.Equal("abc", await ReadPublicKey(KeyBeside(""" "\ud83d\ude00" """)));
    }

    [Theory]
    [InlineData("{\"other\":\"", "\",\"public_key\":\"abc\"}")] // in another member's value
    [InlineData("{\"", "\":1,\"public_key\":\"abc\"}")]          // in another member's name
    [InlineData("{\"public_key\":\"abc\",\"other\":[\"", "\"]}")] // in a string nested in an array
    public async Task Invalid_utf8_in_a_string_the_key_is_not_read_from_is_malformed(string before, string after)
    {
        // The other two implementations check the whole body is UTF-8 before they parse it, so a body
        // that is not text holds no key, wherever the bad byte is.
        byte[] body = [.. Encoding.UTF8.GetBytes(before), 0xFF, .. Encoding.UTF8.GetBytes(after)];

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
    public async Task A_declared_length_over_the_cap_is_refused_with_413_before_a_byte_is_read()
    {
        var context = RequestWith([]);
        context.Request.Body = new UnreadableStream();
        context.Request.ContentLength = PublicKeyBody.MaxBytes + 1;

        var refusal = await Assert.ThrowsAsync<BadHttpRequestException>(
            async () => await AttestRequest.BindAsync(context));

        Assert.Equal(StatusCodes.Status413PayloadTooLarge, refusal.StatusCode);
    }

    [Fact]
    public void The_cap_is_64_kilobytes_in_all_three_implementations()
    {
        Assert.Equal(65_536, PublicKeyBody.MaxBytes);
    }

    [Fact]
    public void The_server_discards_up_to_2_megabytes_of_a_body_it_does_not_read_as_tomcat_does()
    {
        Assert.Equal(2_097_152, ApiBodyLimit.Instance.MaxRequestBodySize);
    }

    private sealed class UnreadableStream : MemoryStream
    {
        public override ValueTask<int> ReadAsync(Memory<byte> buffer, CancellationToken cancellationToken = default) =>
            throw new InvalidOperationException("The body was read.");
    }
}
