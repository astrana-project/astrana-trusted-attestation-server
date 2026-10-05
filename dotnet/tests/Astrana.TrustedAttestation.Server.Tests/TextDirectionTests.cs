using System.Globalization;
using Astrana.TrustedAttestation.Server.Services;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The writing direction for a resolved locale.
///
/// Keyed on the same explicit right-to-left set the Java and PHP implementations use (ar, he, fa, ur, ps)
/// rather than the framework's broader, ICU-dependent <c>TextInfo.IsRightToLeft</c>, so all three emit the
/// same <c>dir</c> for the same language, and a consumer cannot tell the stacks apart.
/// </summary>
public class TextDirectionTests
{
    [Theory]
    [InlineData("ar")]
    [InlineData("he")]
    [InlineData("fa")]
    [InlineData("ur")]
    [InlineData("ps")]
    public void RightToLeftLanguagesAreRtl(string tag)
    {
        Assert.Equal("rtl", TextDirection.Of(tag));
    }

    [Theory]
    [InlineData("en")]
    [InlineData("fr")]
    [InlineData("de")]
    [InlineData("ja")]
    [InlineData("")]
    [InlineData(null)]
    public void EverythingElseIsLtr(string? tag)
    {
        Assert.Equal("ltr", TextDirection.Of(tag));
    }

    [Theory]
    [InlineData("ar-EG", "rtl")]
    [InlineData("fa_IR", "rtl")]
    [InlineData("en-GB", "ltr")]
    public void OnlyThePrimaryLanguageSubtagDecides(string tag, string expected)
    {
        // A region or script suffix, and either separator, resolve on the language alone -- the same way
        // Java splits on "-"/"_" and PHP takes the first two characters.
        Assert.Equal(expected, TextDirection.Of(tag));
    }

    [Theory]
    [InlineData("AR", "rtl")]
    [InlineData("He", "rtl")]
    public void TheLanguageMatchIsCaseInsensitive(string tag, string expected)
    {
        Assert.Equal(expected, TextDirection.Of(tag));
    }

    [Fact]
    public void ACultureResolvesByItsTwoLetterLanguageName()
    {
        Assert.Equal("rtl", TextDirection.Of(new CultureInfo("ar-SA")));
        Assert.Equal("ltr", TextDirection.Of(new CultureInfo("en-US")));
    }
}
