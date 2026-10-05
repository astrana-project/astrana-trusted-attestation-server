using System.Text.RegularExpressions;

namespace Astrana.TrustedAttestation.Server.Manifest;

/// <summary>
/// Resolves the configured logo into the data URI the manifest carries.
///
/// The manifest field is always an embedded data URI -- that is the point of it, so that rendering an
/// org's branding needs no second request to the org. Configuration accepts either that, or a path to an
/// image file which is read and encoded at startup. Pasting a base64 blob into appsettings, a YAML file
/// or an .env line is unpleasant enough that operators would be tempted to skip the logo entirely.
/// </summary>
public static partial class LogoData
{
    private static readonly Dictionary<string, string> MediaTypes = new(StringComparer.OrdinalIgnoreCase)
    {
        [".png"] = "image/png",
        [".jpg"] = "image/jpeg",
        [".jpeg"] = "image/jpeg",
        [".gif"] = "image/gif",
        [".webp"] = "image/webp",
        [".svg"] = "image/svg+xml",
    };

    /// <summary>The setting that holds the light-mode and default logo.</summary>
    public const string LightSetting = "TrustedAttestation:Manifest:LogoData";

    /// <summary>The setting that holds the dark-mode logo.</summary>
    public const string DarkSetting = "TrustedAttestation:Manifest:LogoDataDark";

    /// <param name="configured">A data URI, a path, or nothing.</param>
    /// <param name="contentRoot">Relative paths resolve against this, as the SAML certificate does.</param>
    /// <param name="settingName">The setting the value came from, named in a refusal.</param>
    public static string? Resolve(string? configured, string contentRoot, string settingName)
    {
        if (string.IsNullOrWhiteSpace(configured))
        {
            return null;
        }

        var value = configured.Trim();

        if (value.StartsWith("data:", StringComparison.OrdinalIgnoreCase))
        {
            return value;
        }

        var path = Path.IsPathRooted(value) ? value : Path.Combine(contentRoot, value);

        if (!File.Exists(path))
        {
            throw new InvalidOperationException(
                $"The configured logo '{path}' does not exist. {settingName} takes either an " +
                "image file path, resolved against the content root unless absolute, or a data URI.");
        }

        var extension = Path.GetExtension(path);
        if (!MediaTypes.TryGetValue(extension, out var mediaType))
        {
            throw new InvalidOperationException(
                $"The configured logo '{path}' in {settingName} has an unsupported extension '{extension}'. " +
                $"Supported: {string.Join(", ", MediaTypes.Keys)}.");
        }

        // path is built from a logo setting, an operator-set configuration value resolved once at
        // startup and never from request input, so it is not an attacker-controlled path. The taint finding
        // is a false positive for this threat model, suppressed with the reason rather than refactored.
        // nosemgrep: csharp.lang.security.filesystem.unsafe-path-combine.unsafe-path-combine
        return $"data:{mediaType};base64,{Convert.ToBase64String(File.ReadAllBytes(path))}";
    }

    /// <summary>
    /// A base64 image data URI. Deliberately refuses a URL: a link here would put the request back on the
    /// org's server, which is exactly what embedding the image avoids.
    /// </summary>
    [GeneratedRegex(@"^data:image/(png|jpeg|gif|webp|svg\+xml);base64,[A-Za-z0-9+/]+={0,2}$")]
    public static partial Regex Pattern();
}
