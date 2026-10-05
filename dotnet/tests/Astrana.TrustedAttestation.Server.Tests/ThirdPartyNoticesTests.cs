using System.Text.Json;
using Astrana.TrustedAttestation.Server.Contract;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The third-party notices the build writes into the web root, and the native files the build leaves out.
///
/// The notices are generated from the packages the build restored, so what is pinned here is that the file is
/// a static web asset of the server, that every package whose files ship is in it, and that it carries the
/// texts the licences require: the MySQL drivers' whole licence with the GNU General Public License and the
/// Universal FOSS Exception, and Bootstrap's notice for the compiled stylesheet. The build output beside the
/// tests is the server's own, so the dependency manifest read here is the one the server runs with.
/// </summary>
public class ThirdPartyNoticesTests
{
    private const string NoticesFile = "THIRD-PARTY-NOTICES.txt";

    private static readonly string BuildOutput = AppContext.BaseDirectory;

    /// <summary>The asset groups in a dependency manifest target that copy a file into the output.</summary>
    private static readonly string[] ShippedAssetKinds = ["runtime", "native", "runtimeTargets", "resources"];

    [Fact]
    public void The_notices_are_a_static_web_asset_of_the_server()
    {
        Assert.True(File.Exists(NoticesPath()), $"{NoticesPath()} was not written by the build");
    }

    [Fact]
    public void The_notices_open_with_the_projects_own_copyright_and_licence()
    {
        Assert.Contains(Attribution.Load().LicenseNotice, Notices());
    }

    [Fact]
    public void Every_package_whose_files_ship_is_in_the_notices()
    {
        var notices = Notices();

        var missing = ShippedPackages().Where(package => !notices.Contains(package, StringComparison.Ordinal));

        Assert.Empty(missing);
    }

    [Fact]
    public void The_MySQL_drivers_licence_is_reproduced_in_full_with_where_to_get_the_source()
    {
        var notices = Notices();

        Assert.Contains("MySql.Data 26.7.0", notices);
        Assert.Contains("GPL-2.0-only WITH Universal-FOSS-exception-1.0", notices);
        Assert.Contains("GNU General Public License Version 2.0, June 1991", notices);
        Assert.Contains("The Universal FOSS Exception, Version 1.0", notices);
        Assert.Contains("Source: https://github.com/mysql/mysql-connector-net", notices);
    }

    [Fact]
    public void The_compiled_stylesheet_carries_Bootstraps_notice()
    {
        var notices = Notices();

        Assert.Contains("Bootstrap 5.3.8", notices);
        Assert.Contains("Copyright (c) 2011-2025 The Bootstrap Authors", notices);
    }

    [Fact]
    public void A_licence_named_without_its_text_is_given_in_full()
    {
        // Npgsql names the PostgreSQL licence and ships no copy of it, and the K4os packages state only a
        // licence address and their author, so the texts and the copyright line come from the repository.
        var notices = Notices();

        Assert.Contains("Npgsql 10.0.3", notices);
        Assert.Contains("Licence: PostgreSQL", notices);
        Assert.Contains("IN NO EVENT SHALL NPGSQL BE LIABLE", notices);
        Assert.Contains("K4os.Compression.LZ4 1.3.8\nLicence: MIT\nCopyright (c) 2017 Milosz Krajewski", notices);
        Assert.Contains("Permission is hereby granted, free of charge", notices);
    }

    [Fact]
    public void The_MSAL_broker_native_runtime_is_left_out()
    {
        // Microsoft's licence for libmsalruntime does not allow it to be distributed, and the server never
        // signs anybody in through the broker, so the native assets of its interop package are excluded.
        var manifest = File.ReadAllText(Path.Combine(BuildOutput, "Astrana.TrustedAttestation.Server.deps.json"));

        Assert.DoesNotContain("msalruntime", manifest, StringComparison.OrdinalIgnoreCase);
    }

    private static string Notices() => File.ReadAllText(NoticesPath());

    /// <summary>
    /// Where the server's web root is, read from the static web assets manifest the build writes beside the
    /// tests, so the path is the one the server itself serves from.
    /// </summary>
    private static string NoticesPath()
    {
        using var manifest = JsonDocument.Parse(
            File.ReadAllText(Path.Combine(BuildOutput, "Astrana.TrustedAttestation.Server.staticwebassets.runtime.json")));
        var root = manifest.RootElement;
        var asset = root.GetProperty("Root").GetProperty("Children").GetProperty(NoticesFile).GetProperty("Asset");
        var contentRoot = root.GetProperty("ContentRoots")[asset.GetProperty("ContentRootIndex").GetInt32()].GetString()!;

        return Path.Combine(contentRoot, asset.GetProperty("SubPath").GetString()!);
    }

    /// <summary>
    /// "Id Version" for every package the server's dependency manifest copies a file from.
    /// </summary>
    private static List<string> ShippedPackages()
    {
        using var manifest = JsonDocument.Parse(
            File.ReadAllText(Path.Combine(BuildOutput, "Astrana.TrustedAttestation.Server.deps.json")));
        var root = manifest.RootElement;
        var libraries = root.GetProperty("libraries");

        return root.GetProperty("targets").EnumerateObject().First().Value.EnumerateObject()
            .Where(target => IsPackage(libraries, target.Name) && ShipsAFile(target.Value))
            .Select(target => target.Name.Replace('/', ' '))
            .ToList();
    }

    private static bool IsPackage(JsonElement libraries, string name) =>
        libraries.GetProperty(name).GetProperty("type").GetString() == "package";

    private static bool ShipsAFile(JsonElement target) =>
        ShippedAssetKinds.Any(kind => target.TryGetProperty(kind, out _));
}
