using System.Globalization;

namespace Astrana.TrustedAttestation.Server.Services;

/// <summary>
/// The writing direction for a resolved culture: <c>"rtl"</c> or <c>"ltr"</c>.
///
/// <para>Keyed on the same short, explicit set the other two implementations use
/// (<c>ar</c>, <c>he</c>, <c>fa</c>, <c>ur</c>, <c>ps</c>) rather than on <see cref="TextInfo.IsRightToLeft"/>.
/// The framework flag is broader. It reports right-to-left for scripts outside that set, and its answer
/// can shift with the runtime's ICU data, so keying on it would have this stack emit <c>dir="rtl"</c>
/// for a locale where Java and PHP emit <c>dir="ltr"</c>. A consumer must not be able to tell the stacks
/// apart. All five of these languages ship in the shared strings file, so every one of them is laid out
/// right to left by all three implementations alike.</para>
/// </summary>
public static class TextDirection
{
    private static readonly HashSet<string> RightToLeft = new(StringComparer.Ordinal) { "ar", "he", "fa", "ur", "ps" };

    public static string Of(CultureInfo culture) => Of(culture.TwoLetterISOLanguageName);

    public static string Of(string? tag)
    {
        var language = string.IsNullOrEmpty(tag)
            ? string.Empty
            : tag.Replace('_', '-').Split('-', 2)[0].ToLowerInvariant();

        return RightToLeft.Contains(language) ? "rtl" : "ltr";
    }
}
