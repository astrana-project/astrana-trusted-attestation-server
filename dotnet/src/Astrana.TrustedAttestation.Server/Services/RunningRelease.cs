namespace Astrana.TrustedAttestation.Server.Services;

/// <summary>
/// The release this server is running, which the self-service page shows in its footer and no other page does
/// (decision record 46 in docs/adr). The build sets the assembly's version from the top heading of CHANGELOG.md, the
/// version the release is tagged and published under, so this is read from the assembly rather than stated again.
/// </summary>
public sealed class RunningRelease(string version)
{
    /// <summary>Where the version links to. Fixed, so that no operator can point members somewhere else.</summary>
    public const string ReleasesUrl = "https://www.astrana.org/releases";

    /// <summary>The full version, such as 1.0.1.</summary>
    public string Version { get; } = version;

    /// <summary>The version as the footer writes it, such as v1.0.1.</summary>
    public string Label => "v" + Version;

    public static RunningRelease OfThisBuild() =>
        new(typeof(RunningRelease).Assembly.GetName().Version?.ToString(3)
            ?? throw new InvalidOperationException(
                "The assembly carries no version. The build sets it from CHANGELOG.md."));
}
