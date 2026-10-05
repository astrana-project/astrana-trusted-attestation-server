using Astrana.TrustedAttestation.Server.Manifest;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// How the configured logo becomes the data URI the manifest carries.
///
/// This runs once at startup and its failures stop the application -- deliberately, since a configured
/// logo that does not resolve is a misconfiguration, and the PHP repository has the same tests for the
/// same reasons. The refusal messages matter as much as the behaviour: the app is not running when they
/// appear, so the text is the entire diagnosis.
///
/// The mutation harness also depends on this working: the reload scripts hand the app an absolute logo
/// path, and a silent fallback here would have hidden the MSYS path-mangling bug that cost a whole run.
/// </summary>
public class LogoDataTests : IDisposable
{
    private readonly string _root = Directory.CreateTempSubdirectory("ata-logo-tests").FullName;

    public void Dispose()
    {
        Directory.Delete(_root, recursive: true);
        GC.SuppressFinalize(this);
    }

    // The light logo's setting, which every case but the dark-logo refusal resolves under.
    private static string? Resolve(string? configured, string contentRoot) =>
        LogoData.Resolve(configured, contentRoot, LogoData.LightSetting);

    private string Write(string name, byte[] bytes)
    {
        var path = Path.Combine(_root, name);
        File.WriteAllBytes(path, bytes);
        return path;
    }

    // -------------------------------------------------------------------------------------------
    // The three configured shapes
    // -------------------------------------------------------------------------------------------

    [Theory]
    [InlineData(null)]
    [InlineData("")]
    [InlineData("   ")]
    public void No_logo_configured_is_simply_no_logo(string? configured)
    {
        // Absent is an ordinary state, not an error: the manifest field is optional and the page falls
        // back to the organisation's name.
        Assert.Null(Resolve(configured, _root));
    }

    [Fact]
    public void A_data_uri_passes_through_untouched()
    {
        var uri = "data:image/png;base64,iVBORw0KGgo=";

        Assert.Equal(uri, Resolve(uri, _root));
    }

    [Fact]
    public void A_relative_path_resolves_against_the_content_root()
    {
        Write("logo.png", [1, 2, 3]);

        var resolved = Resolve("logo.png", _root);

        Assert.Equal($"data:image/png;base64,{Convert.ToBase64String([1, 2, 3])}", resolved);
    }

    [Fact]
    public void An_absolute_path_is_used_as_given()
    {
        var absolute = Write("logo.png", [1, 2, 3]);

        var resolved = Resolve(absolute, _root + "-not-the-root");

        Assert.StartsWith("data:image/png;base64,", resolved);
    }

    [Fact]
    public void The_extension_decides_the_media_type_case_insensitively()
    {
        // Windows filesystems hand back whatever casing the file was created with, and a logo exported
        // as LOGO.PNG must not become an unsupported-extension refusal.
        var upper = Write("LOGO.PNG", [9]);

        Assert.StartsWith("data:image/png;", Resolve(upper, _root));
    }

    // -------------------------------------------------------------------------------------------
    // The refusals, and what they tell the operator
    // -------------------------------------------------------------------------------------------

    [Fact]
    public void A_path_that_does_not_exist_refuses_and_names_the_path_it_tried()
    {
        // The resolved path, not the configured value: the difference between the two is usually the
        // entire bug, as the mutation harness's reload script proved when MSYS mangled the path on the
        // way in and the message was the only evidence of what the app had actually been handed.
        var refusal = Assert.Throws<InvalidOperationException>(
            () => Resolve("missing.png", _root));

        Assert.Contains(Path.Combine(_root, "missing.png"), refusal.Message);
        Assert.Contains(LogoData.LightSetting, refusal.Message);
    }

    [Fact]
    public void A_dark_logo_that_does_not_exist_names_the_dark_setting()
    {
        // The refusal names the setting the value came from, so an operator who set both logos is sent to
        // the one that is wrong.
        var refusal = Assert.Throws<InvalidOperationException>(
            () => LogoData.Resolve("missing-dark.png", _root, LogoData.DarkSetting));

        Assert.Contains(LogoData.DarkSetting, refusal.Message);
    }

    [Fact]
    public void An_unsupported_extension_refuses_and_lists_what_is_supported()
    {
        var bmp = Write("logo.bmp", [1]);

        var refusal = Assert.Throws<InvalidOperationException>(() => Resolve(bmp, _root));

        Assert.Contains(".bmp", refusal.Message);
        Assert.Contains(".png", refusal.Message);
        Assert.Contains(".svg", refusal.Message);
    }

    // -------------------------------------------------------------------------------------------
    // The pattern the validator holds manifests to
    // -------------------------------------------------------------------------------------------

    [Theory]
    [InlineData("data:image/png;base64,iVBORw0KGgo=")]
    [InlineData("data:image/svg+xml;base64,PHN2Zy8+")]
    public void The_pattern_accepts_an_embedded_image(string uri)
    {
        Assert.Matches(LogoData.Pattern(), uri);
    }

    [Theory]
    [InlineData("https://example.org/logo.png")]
    [InlineData("data:text/html;base64,PHNjcmlwdC8+")]
    [InlineData("data:image/png;base64,not!!base64")]
    public void The_pattern_refuses_a_url_a_non_image_and_garbage(string uri)
    {
        // The URL case is the point of the field existing: a link would put every consumer's request
        // back on the organisation's server, which is the side-channel embedding avoids. The text/html
        // case is what stops a manifest smuggling something a careless consumer might render.
        Assert.DoesNotMatch(LogoData.Pattern(), uri);
    }

    [Fact]
    public void What_resolve_produces_is_what_the_pattern_accepts()
    {
        // The two halves of this class are used apart -- Resolve at startup, Pattern in the manifest
        // validator -- and nothing else guarantees they agree. A media type added to one and not the
        // other would mean the app builds a manifest its own validator rejects.
        var resolved = Resolve(Write("logo.webp", [5, 6]), _root);

        Assert.NotNull(resolved);
        Assert.Matches(LogoData.Pattern(), resolved);
    }
}
