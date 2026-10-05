using Astrana.TrustedAttestation.Server.Security;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The language switcher's return path, a form field anyone can set. Only a path on this server is
/// followed, by one rule in all three implementations: it starts with a slash, not with a second slash or
/// a backslash, holds no backslash and no control character. Anything else lands on the landing page,
/// never anywhere off site and never a 500.
/// </summary>
public class ReturnPathTests
{
    [Theory]
    [InlineData("/")]
    [InlineData("/me")]
    [InlineData("/me?error=login")]
    [InlineData("/licence")]
    [InlineData("/a/b/c?x=1&y=2#frag")]
    public void A_local_path_is_followed_as_given(string next)
    {
        Assert.Equal(next, ReturnPath.Sanitize(next));
    }

    [Theory]
    [InlineData(null)]
    [InlineData("")]
    [InlineData("me")]
    [InlineData("https://evil.example")]
    [InlineData("//evil.example")]
    [InlineData("/\\evil.example")]
    [InlineData("/me\\evil")]
    [InlineData("/me\tx")]
    [InlineData("/me\r\nSet-Cookie: a=b")]
    [InlineData("/me\n")]
    [InlineData("/me\u007f")]
    [InlineData("\u0000/me")]
    public void Anything_else_falls_back_to_the_landing_page(string? next)
    {
        Assert.Equal("/", ReturnPath.Sanitize(next));
    }
}
