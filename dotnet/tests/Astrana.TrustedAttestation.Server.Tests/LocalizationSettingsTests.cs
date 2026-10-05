using Astrana.TrustedAttestation.Server.Services;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The one rule that maps a requested locale to an offered one, shared by the language cookie, the IAM
/// claim and every Accept-Language entry, and pinned identically in the Java and PHP implementations. The
/// case that earns the rule is Chinese, the only language shipped in two scripts: a browser in Taiwan asks
/// for zh-TW and must get the traditional script, where matching by language prefix alone would hand it
/// whichever script happens to be listed first.
/// </summary>
public class LocalizationSettingsTests
{
    private static LocalizationSettings Offering(params string[] locales) =>
        new(configured: [], available: locales, defaultLocale: locales[0], endonyms: new Dictionary<string, string>());

    private static readonly LocalizationSettings Both = Offering("en", "fr", "zh-Hans", "zh-Hant");

    [Theory]
    [InlineData("zh-TW", "zh-Hant")]
    [InlineData("zh-HK", "zh-Hant")]
    [InlineData("zh-MO", "zh-Hant")]
    [InlineData("zh-CN", "zh-Hans")]
    [InlineData("zh-SG", "zh-Hans")]
    [InlineData("zh", "zh-Hans")]
    public void A_Chinese_tag_without_a_script_resolves_by_region(string requested, string expected)
    {
        Assert.Equal(expected, Both.Match(requested));
    }

    [Theory]
    [InlineData("zh-Hant-TW", "zh-Hant")]
    [InlineData("zh-Hans-TW", "zh-Hans")]
    [InlineData("zh-hant", "zh-Hant")]
    public void A_script_in_the_tag_wins_over_the_region(string requested, string expected)
    {
        Assert.Equal(expected, Both.Match(requested));
    }

    [Fact]
    public void When_only_one_script_is_offered_the_language_still_matches()
    {
        Assert.Equal("zh-Hans", Offering("en", "zh-Hans").Match("zh-TW"));
    }

    [Theory]
    [InlineData("fr-CA", "fr")]
    [InlineData("FR", "fr")]
    [InlineData("fr_CA", "fr")]
    [InlineData("en", "en")]
    public void Other_languages_match_exactly_or_by_language(string requested, string expected)
    {
        Assert.Equal(expected, Both.Match(requested));
    }

    [Theory]
    [InlineData(null)]
    [InlineData("")]
    [InlineData("   ")]
    [InlineData("zz")]
    [InlineData("*")]
    public void Nothing_offered_is_no_match(string? requested)
    {
        Assert.Null(Both.Match(requested));
    }

    // -- The configured list ------------------------------------------------------------------------

    private static LocalizationSettings Configured(params string[] configured) =>
        new(configured, ["en", "fr", "de", "zh-Hans", "zh-Hant"], "en", new Dictionary<string, string>());

    [Fact]
    public void A_configured_locale_is_offered_in_its_shipped_spelling_and_trimmed()
    {
        // The strings file, the cookie and the other two implementations all use the shipped spelling. A
        // value an operator typed as " ZH-hans" has to offer zh-Hans, not a spelling nothing else knows.
        var settings = Configured(" ZH-hans", "FR ", "en");

        Assert.Equal(["zh-Hans", "fr", "en"], settings.Locales);
    }

    [Fact]
    public void A_locale_listed_twice_is_offered_once_in_its_first_position()
    {
        var settings = Configured("fr", "en", "FR", "de", "fr");

        Assert.Equal(["fr", "en", "de"], settings.Locales);
    }

    [Fact]
    public void The_configured_order_is_kept_and_unshipped_values_dropped()
    {
        var settings = Configured("de", "xx", "fr");

        Assert.Equal(["de", "fr"], settings.Locales);
        Assert.Equal("en", settings.DefaultLocale);
    }

    [Fact]
    public void A_configured_list_that_ships_nothing_falls_back_to_everything_shipped()
    {
        var settings = Configured("xx", "yy");

        Assert.Equal(5, settings.Locales.Count);
    }
}
