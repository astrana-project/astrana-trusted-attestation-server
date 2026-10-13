using System.Text.RegularExpressions;
using Astrana.TrustedAttestation.Server.Services;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The version the self-service page shows in its footer (decision record 47 in docs/adr). The build takes it from the
/// top heading of this implementation's CHANGELOG.md, the version the release is tagged and published under, so that
/// the version shown is the release that is running.
/// </summary>
public partial class RunningReleaseTests
{
    [GeneratedRegex(@"^## \[(\d+\.\d+\.\d+)\]", RegexOptions.Multiline)]
    private static partial Regex ChangelogHeading();

    private static string ChangelogVersion()
    {
        var folder = new DirectoryInfo(AppContext.BaseDirectory);
        while (folder is not null && !File.Exists(Path.Combine(folder.FullName, "Astrana.TrustedAttestation.slnx")))
        {
            folder = folder.Parent;
        }

        Assert.NotNull(folder);
        var changelog = File.ReadAllText(Path.Combine(folder.FullName, "CHANGELOG.md"));
        return ChangelogHeading().Match(changelog).Groups[1].Value;
    }

    [Fact]
    public void The_running_version_is_the_top_heading_of_the_changelog()
    {
        Assert.Equal(ChangelogVersion(), RunningRelease.OfThisBuild().Version);
    }

    [Fact]
    public void The_label_is_v_followed_by_the_full_version()
    {
        Assert.Equal("v1.2.3", new RunningRelease("1.2.3").Label);
    }

    [Fact]
    public void It_links_to_the_fixed_releases_page()
    {
        Assert.Equal("https://www.astrana.org/releases", RunningRelease.ReleasesUrl);
    }
}
