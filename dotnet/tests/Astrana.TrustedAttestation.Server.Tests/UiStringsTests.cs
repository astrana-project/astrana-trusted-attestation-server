using Astrana.TrustedAttestation.Server.Services;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The one member-facing page, in the member's own language.
///
/// Rendering in the member's language is a requirement rather than polish, and the failure modes here
/// are all quiet ones: a missing key shows an untranslated label, a broken fallback shows English to
/// someone who asked for French, and neither throws or logs anything. The page renders either way, so
/// nothing downstream notices.
///
/// These run against the real embedded resource rather than a fixture, because the shipped file being
/// complete is part of what is under test. A translation added for `fr` and forgotten for `de` is
/// exactly the sort of thing that survives every other check in this project.
/// </summary>
public class UiStringsTests
{
    private static readonly UiStrings Strings = UiStrings.Load();

    // -------------------------------------------------------------------------------------------
    // Falling back
    // -------------------------------------------------------------------------------------------

    [Fact]
    public void An_exact_locale_is_used_when_it_exists()
    {
        Assert.Equal("Se déconnecter", Strings.Get("sign_out", "fr"));
    }

    [Fact]
    public void A_region_falls_back_to_its_language()
    {
        // fr-CA is not shipped; fr is. A Canadian member gets French rather than English, which is the
        // whole point of the fallback and the case a lookup written as an exact match would miss.
        Assert.Equal(Strings.Get("sign_out", "fr"), Strings.Get("sign_out", "fr-CA"));
    }

    [Fact]
    public void An_unknown_language_falls_back_to_english()
    {
        Assert.Equal(Strings.Get("sign_out", "en"), Strings.Get("sign_out", "cy-GB"));
    }

    [Theory]
    [InlineData(null)]
    [InlineData("")]
    [InlineData("   ")]
    public void No_locale_at_all_is_english_rather_than_an_exception(string? locale)
    {
        Assert.Equal(Strings.Get("sign_out", "en"), Strings.Get("sign_out", locale));
    }

    [Fact]
    public void An_unknown_key_returns_itself_rather_than_throwing()
    {
        // A page with one untranslated label is better than a page that will not render. The key comes
        // back so that whoever sees it can find it.
        Assert.Equal("no_such_key", Strings.Get("no_such_key", "en"));
    }

    // -------------------------------------------------------------------------------------------
    // The whole set, as the page's script receives it
    // -------------------------------------------------------------------------------------------

    [Fact]
    public void Every_key_is_present_for_a_locale_that_only_partly_translates()
    {
        // All() backfills from English, because the script indexes into this map directly and a missing
        // key would render "undefined" in the member's browser.
        var english = Strings.All("en");
        var french = Strings.All("fr");

        Assert.Equal(english.Keys.OrderBy(k => k), french.Keys.OrderBy(k => k));
    }

    [Fact]
    public void A_translated_value_wins_over_the_english_it_was_backfilled_from()
    {
        // The ordering that makes backfill safe: English first, then the more specific locale over it.
        // Reversed, every member would see English no matter what they asked for.
        Assert.Equal("Se déconnecter", Strings.All("fr")["sign_out"]);
    }

    [Fact]
    public void A_region_gets_its_languages_translations_through_the_backfill_too()
    {
        Assert.Equal(Strings.All("fr")["sign_out"], Strings.All("fr-CA")["sign_out"]);
    }

    [Fact]
    public void An_unknown_locale_still_gets_a_complete_set()
    {
        var unknown = Strings.All("cy-GB");

        Assert.Equal(Strings.All("en").Keys.OrderBy(k => k), unknown.Keys.OrderBy(k => k));
        Assert.All(unknown.Values, value => Assert.False(string.IsNullOrWhiteSpace(value)));
    }

    // -------------------------------------------------------------------------------------------
    // The shipped file itself
    // -------------------------------------------------------------------------------------------

    [Fact]
    public void Every_shipped_locale_translates_every_key()
    {
        // Read from the shipped resource rather than through the lookup, because the lookup cannot tell
        // "not translated" from "translates to the same word". French for status_active really is
        // "Active", and comparing values reported that as a missing translation.
        //
        // This is the check that catches a string added in one language and forgotten in the others.
        // Nothing else would: the page renders, the member simply reads English in the middle of their
        // own language, and only they can see it.
        var shipped = ShippedLocales();
        var english = shipped["en"];

        foreach (var (locale, keys) in shipped.Where(entry => entry.Key != "en"))
        {
            var missing = english.Except(keys).OrderBy(k => k).ToList();

            Assert.True(missing.Count == 0,
                $"{locale} is missing: {string.Join(", ", missing)}");
        }
    }

    [Fact]
    public void No_shipped_locale_has_a_key_english_does_not()
    {
        // The other direction. A key only present in French is dead weight the page never asks for, and
        // usually the residue of a rename applied to one locale and not the rest.
        var shipped = ShippedLocales();

        foreach (var (locale, keys) in shipped.Where(entry => entry.Key != "en"))
        {
            var extra = keys.Except(shipped["en"]).OrderBy(k => k).ToList();

            Assert.True(extra.Count == 0, $"{locale} has keys English does not: {string.Join(", ", extra)}");
        }
    }

    /// <summary>The locale blocks exactly as the shipped file declares them, with no fallback applied.</summary>
    private static Dictionary<string, HashSet<string>> ShippedLocales()
    {
        using var stream = typeof(UiStrings).Assembly
            .GetManifestResourceStream("Astrana.TrustedAttestation.Server.Resources.ui-strings.json");

        Assert.NotNull(stream);

        using var document = System.Text.Json.JsonDocument.Parse(stream);

        return document.RootElement.EnumerateObject()
            // Keys beginning with "_" are notes to whoever edits the file, not a locale.
            .Where(property => !property.Name.StartsWith('_')
                               && property.Value.ValueKind == System.Text.Json.JsonValueKind.Object)
            .ToDictionary(
                property => property.Name,
                property => property.Value.EnumerateObject().Select(k => k.Name).ToHashSet());
    }
}
