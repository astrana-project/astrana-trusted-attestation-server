using System.Globalization;
using System.Reflection;
using System.Text.Json;

namespace Astrana.TrustedAttestation.Server.Services;

/// <summary>
/// Text for the landing page, the self-service page and the licence page, in the member's own language.
///
/// Backed by a JSON data file rather than .resx, for the same reason the governed enum is a data file. The
/// set is small, three pages and a handful of controls, and keeping it as data means a translator can add
/// a locale without touching the build. Relationship-type labels deliberately do not live here; those come from the
/// contract's own file so the same relationship reads the same way whichever org issued it.
/// </summary>
public sealed class UiStrings
{
    private const string ResourceName = "Astrana.TrustedAttestation.Server.Resources.ui-strings.json";

    private readonly IReadOnlyDictionary<string, IReadOnlyDictionary<string, string>> _byLocale;
    private readonly IReadOnlyCollection<string> _supportedLocales;

    private UiStrings(IReadOnlyDictionary<string, IReadOnlyDictionary<string, string>> byLocale)
    {
        _byLocale = byLocale;

        // Materialised once here rather than on each read of SupportedLocales: the set is fixed after
        // load, so a fresh array per access would allocate for nothing.
        _supportedLocales = byLocale.Keys.ToArray();
    }

    public static UiStrings Load()
    {
        using var stream = typeof(UiStrings).GetTypeInfo().Assembly.GetManifestResourceStream(ResourceName)
            ?? throw new InvalidOperationException($"Embedded resource '{ResourceName}' is missing.");

        var raw = JsonSerializer.Deserialize<Dictionary<string, JsonElement>>(stream)
            ?? throw new InvalidOperationException($"Embedded resource '{ResourceName}' is empty.");

        var byLocale = new Dictionary<string, IReadOnlyDictionary<string, string>>(StringComparer.OrdinalIgnoreCase);

        foreach (var (locale, value) in raw)
        {
            // Keys beginning with "_" are notes to whoever edits the file, not a locale.
            if (locale.StartsWith('_') || value.ValueKind != JsonValueKind.Object)
            {
                continue;
            }

            var strings = new Dictionary<string, string>(StringComparer.Ordinal);
            foreach (var property in value.EnumerateObject())
            {
                strings[property.Name] = property.Value.GetString() ?? string.Empty;
            }

            byLocale[locale] = strings;
        }

        if (!byLocale.ContainsKey("en"))
        {
            throw new InvalidOperationException(
                $"Embedded resource '{ResourceName}' has no 'en' block. English is the fallback and must be complete.");
        }

        return new UiStrings(byLocale);
    }

    /// <summary>
    /// The string for the current UI culture, falling back to the more general language (<c>fr-CA</c> to
    /// <c>fr</c>), then to English. Never throws for a missing key: a page with one untranslated label is
    /// better than a page that will not render.
    /// </summary>
    public string this[string key] => Get(key, CultureInfo.CurrentUICulture.Name);

    public string Get(string key, string? locale)
    {
        foreach (var candidate in Fallbacks(locale))
        {
            if (_byLocale.TryGetValue(candidate, out var strings) && strings.TryGetValue(key, out var value))
            {
                return value;
            }
        }

        return _byLocale["en"].TryGetValue(key, out var english) ? english : key;
    }

    /// <summary>
    /// The locales this instance can serve, read from the shared strings file rather than a list repeated
    /// in startup configuration -- so a translator adding a locale to <c>ui-strings.json</c> has it
    /// offered here too, and the same source backs the supported set in all three implementations.
    /// </summary>
    public IReadOnlyCollection<string> SupportedLocales => _supportedLocales;

    /// <summary>Every string for one locale, English-backfilled, for handing to the page's script.</summary>
    public IReadOnlyDictionary<string, string> All(string? locale)
    {
        var result = new Dictionary<string, string>(_byLocale["en"], StringComparer.Ordinal);

        foreach (var candidate in Fallbacks(locale).Reverse())
        {
            if (_byLocale.TryGetValue(candidate, out var strings))
            {
                foreach (var (key, value) in strings)
                {
                    result[key] = value;
                }
            }
        }

        return result;
    }

    private static IEnumerable<string> Fallbacks(string? locale)
    {
        if (string.IsNullOrWhiteSpace(locale))
        {
            yield break;
        }

        yield return locale;

        var separator = locale.IndexOf('-');
        if (separator > 0)
        {
            yield return locale[..separator];
        }
    }
}
