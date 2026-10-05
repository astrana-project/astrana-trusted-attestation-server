using System.Globalization;

namespace Astrana.TrustedAttestation.Server.Services;

/// <summary>
/// The locales this instance actually offers, resolved once at startup and shared by the request pipeline
/// (which locale a request may resolve to) and the pages (which options the language switcher shows, and in
/// what order).
///
/// The effective set is the organisation's configured <c>SupportedLocales</c> intersected with the locales
/// the app ships strings for. If the organisation lists them explicitly, that order is honoured verbatim --
/// it lets an organisation put its primary languages first. If it does not (the list is empty, meaning
/// "every locale the app has"), the languages are ordered alphabetically by their own native name (endonym),
/// so a speaker finds their language by how they themselves write it, with the organisation's default locale
/// pinned to the front as the most likely choice. A configured value with no shipped strings is dropped
/// rather than offered as an empty language.
/// </summary>
public sealed class LocalizationSettings
{
    public LocalizationSettings(
        IEnumerable<string> configured,
        IEnumerable<string> available,
        string defaultLocale,
        IReadOnlyDictionary<string, string> endonyms)
    {
        var shipped = available.Where(locale => !string.IsNullOrWhiteSpace(locale)).ToList();
        var have = new HashSet<string>(shipped, StringComparer.OrdinalIgnoreCase);

        // Each configured value is trimmed and read in the shipped spelling, so " ZH-hans" offers zh-Hans
        // and matches the strings file, the cookie and the other two implementations. A locale listed twice
        // is offered once, in its first position.
        var wanted = configured
            .Where(c => !string.IsNullOrWhiteSpace(c))
            .Select(c => shipped.FirstOrDefault(locale => string.Equals(locale, c.Trim(), StringComparison.OrdinalIgnoreCase)))
            .OfType<string>()
            .Distinct(StringComparer.Ordinal)
            .ToArray();

        string[] effective;
        if (configured.Any(c => !string.IsNullOrWhiteSpace(c)))
        {
            // The organisation chose the order; honour it, keeping only locales the app actually ships.
            effective = wanted;
        }
        else
        {
            // No explicit order: alphabetical by native name, then the default locale pinned to the front.
            var sorted = have
                .OrderBy(locale => endonyms.TryGetValue(locale, out var name) ? name : locale,
                    StringComparer.Create(CultureInfo.InvariantCulture, ignoreCase: true))
                .ToList();

            var pin = sorted.FirstOrDefault(l => string.Equals(l, defaultLocale, StringComparison.OrdinalIgnoreCase));
            if (pin is not null)
            {
                sorted.Remove(pin);
                sorted.Insert(0, pin);
            }

            effective = [.. sorted];
        }

        // A misconfiguration that leaves nothing (every configured locale unshipped) must not blank the UI:
        // fall back to what the app has rather than serve no language at all.
        Locales = effective.Length == 0 ? [.. have] : effective;

        DefaultLocale = have.Contains(defaultLocale) ? defaultLocale : Locales[0];
    }

    /// <summary>The offered locales, in display order. Always at least one.</summary>
    public IReadOnlyList<string> Locales { get; }

    /// <summary>The fallback locale when a request resolves to nothing offered.</summary>
    public string DefaultLocale { get; }

    /// <summary>The switcher is shown only when there is a genuine choice.</summary>
    public bool ShowSwitcher => Locales.Count > 1;

    /// <summary>
    /// The offered locale a requested tag resolves to, or null when nothing offered fits. Pinned identically
    /// in all three implementations. An exact match first, ignoring case and reading an underscore as a
    /// hyphen. Then, for a language that ships in more than one script (Chinese, as <c>zh-Hans</c> and
    /// <c>zh-Hant</c>), the script decides: one named in the tag wins, otherwise the region, where Taiwan,
    /// Hong Kong and Macao mean the traditional script and any other region or none means the simplified
    /// one. Then the first offered locale with the same language, so <c>fr-CA</c> finds <c>fr</c>.
    /// </summary>
    public string? Match(string? tag)
    {
        if (string.IsNullOrWhiteSpace(tag))
        {
            return null;
        }

        var requested = tag.Trim().Replace('_', '-');
        var exact = Locales.FirstOrDefault(l => string.Equals(l, requested, StringComparison.OrdinalIgnoreCase));
        if (exact is not null)
        {
            return exact;
        }

        var parts = requested.Split('-');
        var language = parts[0];
        if (string.Equals(language, "zh", StringComparison.OrdinalIgnoreCase))
        {
            var script = parts.Skip(1).FirstOrDefault(p => p.Length == 4);
            var region = parts.Skip(1).FirstOrDefault(p => p.Length is 2 or 3);
            var traditional = script is not null
                ? string.Equals(script, "Hant", StringComparison.OrdinalIgnoreCase)
                : region is not null && TraditionalScriptRegions.Contains(region);
            var preferred = traditional ? "zh-Hant" : "zh-Hans";
            var byScript = Locales.FirstOrDefault(l => string.Equals(l, preferred, StringComparison.OrdinalIgnoreCase));
            if (byScript is not null)
            {
                return byScript;
            }
        }

        return Locales.FirstOrDefault(l => string.Equals(l.Split('-')[0], language, StringComparison.OrdinalIgnoreCase));
    }

    /// <summary>The regions whose Chinese is written in the traditional script when the tag names no script.</summary>
    private static readonly HashSet<string> TraditionalScriptRegions =
        new(StringComparer.OrdinalIgnoreCase) { "TW", "HK", "MO" };
}
