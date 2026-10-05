using Astrana.TrustedAttestation.Server.Endpoints;
using Astrana.TrustedAttestation.Server.Services;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Localization;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// How a request's language is resolved: the language switcher's cookie first, then the identity system's
/// locale claim, then every Accept-Language entry in quality order, each matched by the one rule all three
/// implementations share. A query string never sets the language.
/// </summary>
public class RequestLocalizationTests
{
    private static readonly LocalizationSettings Offered =
        new(configured: [], available: ["en", "fr", "zh-Hans", "zh-Hant"], defaultLocale: "en",
            endonyms: new Dictionary<string, string>());

    private static RequestLocalizationOptions Configured()
    {
        var options = new RequestLocalizationOptions();
        RequestLocalization.Configure(options, Offered);
        return options;
    }

    private static DefaultHttpContext WithAcceptLanguage(string value)
    {
        var http = new DefaultHttpContext();
        http.Request.Headers.AcceptLanguage = value;
        return http;
    }

    private static DefaultHttpContext WithLocaleCookie(string value)
    {
        var http = new DefaultHttpContext();
        http.Request.Headers.Cookie = $"{LanguageSwitchEndpoint.CookieName}={value}";
        return http;
    }

    [Fact]
    public void The_offered_locales_are_the_supported_cultures_and_the_default_is_the_fallback()
    {
        var options = Configured();

        Assert.Equal(["en", "fr", "zh-Hans", "zh-Hant"], options.SupportedCultures!.Select(c => c.Name));
        Assert.Equal(["en", "fr", "zh-Hans", "zh-Hant"], options.SupportedUICultures!.Select(c => c.Name));
        Assert.Equal("en", options.DefaultRequestCulture.UICulture.Name);
    }

    [Fact]
    public void The_cookie_comes_first_then_the_claim_then_accept_language_and_nothing_else()
    {
        // The stock query string, cookie and Accept-Language providers are all gone, so a crafted link
        // cannot set the language and the cookie is the plain one the switcher writes.
        var providers = Configured().RequestCultureProviders;

        Assert.Collection(providers,
            first => Assert.IsType<CustomRequestCultureProvider>(first),
            second => Assert.IsType<ClaimsRequestCultureProvider>(second),
            third => Assert.IsType<CustomRequestCultureProvider>(third));
    }

    [Fact]
    public async Task The_configured_providers_read_the_cookie_ahead_of_accept_language()
    {
        var http = WithLocaleCookie("fr");
        http.Request.Headers.AcceptLanguage = "zh-TW";
        var providers = Configured().RequestCultureProviders;

        var first = await providers[0].DetermineProviderCultureResult(http);
        var last = await providers[2].DetermineProviderCultureResult(http);

        Assert.Equal("fr", first!.UICultures.Single().Value);
        Assert.Equal("zh-Hant", last!.UICultures.Single().Value);
    }

    [Theory]
    [InlineData("zh-TW", "zh-Hant")]
    [InlineData("de;q=0.9, fr;q=0.2, en;q=0.5", "en")]
    [InlineData("de, it, es, pt, fr", "fr")]
    [InlineData("fr;q=0, en;q=0.1", "en")]
    public void Accept_language_takes_the_best_entry_this_instance_offers(string header, string expected)
    {
        // Every entry is walked, not just the first three, and a quality of zero means "not this one".
        var result = RequestLocalization.FromAcceptLanguage(WithAcceptLanguage(header), Offered);

        Assert.Equal(expected, result!.UICultures.Single().Value);
    }

    [Theory]
    [InlineData("de, it")]
    [InlineData("fr;q=0")]
    [InlineData("")]
    public void Accept_language_with_nothing_offered_resolves_to_nothing(string header)
    {
        Assert.Null(RequestLocalization.FromAcceptLanguage(WithAcceptLanguage(header), Offered));
    }

    [Fact]
    public void Accept_language_that_does_not_parse_resolves_to_nothing()
    {
        Assert.Null(RequestLocalization.FromAcceptLanguage(WithAcceptLanguage("fr;q=x;;"), Offered));
    }

    [Theory]
    [InlineData("fr", "fr")]
    [InlineData("ZH-hant", "zh-Hant")]
    public void The_cookie_resolves_to_the_offered_locale(string cookie, string expected)
    {
        var result = RequestLocalization.FromLocaleCookie(WithLocaleCookie(cookie), Offered);

        Assert.Equal(expected, result!.UICultures.Single().Value);
    }

    [Fact]
    public void A_cookie_naming_a_locale_not_offered_or_no_cookie_resolves_to_nothing()
    {
        Assert.Null(RequestLocalization.FromLocaleCookie(WithLocaleCookie("de"), Offered));
        Assert.Null(RequestLocalization.FromLocaleCookie(new DefaultHttpContext(), Offered));
    }
}
