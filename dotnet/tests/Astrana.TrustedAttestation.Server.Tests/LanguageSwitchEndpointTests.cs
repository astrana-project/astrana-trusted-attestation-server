using System.Text;
using Astrana.TrustedAttestation.Server.Endpoints;
using Astrana.TrustedAttestation.Server.Security;
using Astrana.TrustedAttestation.Server.Services;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Http.HttpResults;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The language switcher's post. It stores an offered locale in a plain cookie, in the shipped spelling, and
/// returns to a path on this server. Anything it cannot use sets nothing and lands on the landing page.
/// </summary>
public class LanguageSwitchEndpointTests
{
    private static readonly LocalizationSettings Offered =
        new(configured: [], available: ["en", "fr", "zh-Hans"], defaultLocale: "en",
            endonyms: new Dictionary<string, string>());

    private static DefaultHttpContext Posting(string body, string contentType = "application/x-www-form-urlencoded")
    {
        var http = new DefaultHttpContext();
        http.Request.Method = HttpMethods.Post;
        http.Request.ContentType = contentType;
        http.Request.Body = new MemoryStream(Encoding.UTF8.GetBytes(body));
        return http;
    }

    private static string? LocaleCookie(HttpContext http) =>
        http.Response.Headers.SetCookie.FirstOrDefault(
            cookie => cookie!.StartsWith(LanguageSwitchEndpoint.CookieName + "=", StringComparison.Ordinal));

    [Fact]
    public async Task An_offered_locale_is_stored_in_the_shipped_spelling_and_the_member_returns_to_their_page()
    {
        var http = Posting("locale=ZH-hans&next=%2Fme");

        var result = await LanguageSwitchEndpoint.SetLanguageAsync(http, Offered);

        Assert.Equal("/me", Assert.IsType<RedirectHttpResult>(result).Url);
        var cookie = LocaleCookie(http);
        Assert.NotNull(cookie);
        Assert.StartsWith("ata_locale=zh-Hans;", cookie);
        Assert.Contains("max-age=31536000", cookie, StringComparison.OrdinalIgnoreCase);
        Assert.Contains("path=/", cookie, StringComparison.OrdinalIgnoreCase);
        Assert.Contains("secure", cookie, StringComparison.OrdinalIgnoreCase);
        Assert.Contains("samesite=lax", cookie, StringComparison.OrdinalIgnoreCase);
        Assert.Contains("httponly", cookie, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public async Task A_locale_this_instance_does_not_offer_sets_nothing()
    {
        var http = Posting("locale=de&next=%2Fme");

        var result = await LanguageSwitchEndpoint.SetLanguageAsync(http, Offered);

        Assert.Equal("/me", Assert.IsType<RedirectHttpResult>(result).Url);
        Assert.Null(LocaleCookie(http));
    }

    [Fact]
    public async Task A_return_path_off_this_server_lands_on_the_landing_page()
    {
        var result = await LanguageSwitchEndpoint.SetLanguageAsync(
            Posting("locale=fr&next=%2F%2Fevil.example"), Offered);

        Assert.Equal(ReturnPath.Fallback, Assert.IsType<RedirectHttpResult>(result).Url);
    }

    [Theory]
    [InlineData("{\"locale\":\"fr\"}", "application/json")]
    [InlineData("", "multipart/form-data")]
    public async Task A_request_with_no_readable_form_sets_nothing_and_lands_on_the_landing_page(
        string body, string contentType)
    {
        // A JSON body is no form at all, and a multipart type with no boundary is a form that cannot be read.
        var http = Posting(body, contentType);

        var result = await LanguageSwitchEndpoint.SetLanguageAsync(http, Offered);

        Assert.Equal(ReturnPath.Fallback, Assert.IsType<RedirectHttpResult>(result).Url);
        Assert.Null(LocaleCookie(http));
    }
}
