using System.Security.Claims;
using Astrana.TrustedAttestation.Server.Services;
using Microsoft.AspNetCore.Http;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// Rendering in the member's language is a requirement, not optional polish, and the org's own directory
/// is the better source for it: an org knows which language it holds a member's record in, where
/// Accept-Language only says what the browser was installed with.
///
/// <para>This provider is what puts the org ahead of the browser, so what matters is that it answers
/// only when the IdP actually said something this instance can serve. Returning a result for an absent,
/// blank or unserved claim would pin every member to the default and silently discard the browser's
/// preference underneath.</para>
/// </summary>
public class ClaimsRequestCultureProviderTests
{
    private static readonly LocalizationSettings Offered = new(
        configured: [],
        available: ["en", "fr", "de", "zh-Hans", "zh-Hant"],
        defaultLocale: "en",
        endonyms: new Dictionary<string, string>());

    private static async Task<string?> CultureFor(string? locale)
    {
        var claims = locale is null ? [] : new[] { new Claim("locale", locale) };
        var context = new DefaultHttpContext
        {
            User = new ClaimsPrincipal(new ClaimsIdentity(claims, "test")),
        };

        var result = await new ClaimsRequestCultureProvider(Offered)
            .DetermineProviderCultureResult(context);

        return result?.Cultures.FirstOrDefault().Value;
    }

    [Fact]
    public async Task AnIamProvidedLocaleIsUsed()
    {
        Assert.Equal("fr", await CultureFor("fr"));
    }

    [Fact]
    public async Task NoLocaleClaimDefersToWhateverComesNext()
    {
        // Returning null is what lets Accept-Language be consulted behind this. A result here would
        // stop that happening.
        Assert.Null(await CultureFor(null));
    }

    [Fact]
    public async Task ABlankLocaleClaimDefersRatherThanPinningTheDefault()
    {
        // An IdP that sends the claim but leaves it empty has said nothing, and should be treated as
        // having said nothing.
        Assert.Null(await CultureFor("   "));
    }

    [Fact]
    public async Task ARegionalLocaleResolvesToItsLanguage()
    {
        // The same matching rule as the cookie and Accept-Language, so fr-CA finds fr here rather than
        // leaving request localization to fall back on its own terms.
        Assert.Equal("fr", await CultureFor("fr-CA"));
    }

    [Fact]
    public async Task AClaimThisInstanceCannotServeDefers()
    {
        // A directory that holds a language the organisation does not offer must not pin the member to
        // the default ahead of a browser preference that could still be served.
        Assert.Null(await CultureFor("zz"));
    }
}
