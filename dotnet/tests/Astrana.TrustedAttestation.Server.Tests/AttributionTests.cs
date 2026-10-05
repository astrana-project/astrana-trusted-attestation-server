using Astrana.TrustedAttestation.Server.Contract;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The fixed footer every deployment shows, loaded from the embedded copy of the contract's
/// <c>attribution.json</c>.
///
/// It is deliberately not organisation-configurable: decision record 33 in docs/adr requires the same notice on every
/// deployment, and all three implementations import the one file so it reads identically wherever it
/// appears. What this pins is that the loader actually reads that embedded file and enforces the fields
/// the footer in decision record 33 depends on -- if the resource stopped being embedded, or the build embedded an
/// empty or nameless one, the footer would silently go blank rather than fail, and only this catches that.
/// </summary>
public class AttributionTests
{
    [Fact]
    public void Load_reads_the_project_identity_from_the_embedded_contract_file()
    {
        // The values come from contract/attribution.json, embedded at build time. Asserting the actual
        // strings ties this to the contract rather than to whatever the loader happened to return: if the
        // resource were missing the loader throws, and if it were embedded but wrong these would move.
        var attribution = Attribution.Load();

        Assert.Equal("Astrana Trusted Attestation Server", attribution.ProjectName);
        Assert.Equal("https://astrana.org", attribution.ProjectUrl);
    }

    [Fact]
    public void Load_carries_the_licence_notice_label_and_link_from_the_contract_file()
    {
        // The footer links a short label to the software's own licence page (/license), where the fuller
        // notice is shown. The loader reads all three from the embedded contract file rather than inventing
        // any of them; the project is MIT-licensed, so editing that one file is what changes the wording
        // and, if it ever moves off-site, the link.
        var attribution = Attribution.Load();

        Assert.Equal(
            "Copyright (c) 2026 Darin Morris and contributors. Free and open source software, licensed under the MIT License.",
            attribution.LicenseNotice);
        Assert.Equal("Licence", attribution.LicenseLabel);
        Assert.Equal("/license", attribution.LicenseUrl);
    }

    [Fact]
    public void Load_carries_the_licence_text_the_trademark_notice_and_the_source_address()
    {
        // The licence page shows the MIT text in full, the trademark notice and where the source lives, all
        // from the same contract file, so the three implementations render the same page. The text is the
        // three paragraphs of LICENSE.md.
        var attribution = Attribution.Load();

        Assert.Equal(3, attribution.LicenseText.Count);
        Assert.StartsWith("Permission is hereby granted", attribution.LicenseText[0]);
        Assert.StartsWith("THE SOFTWARE IS PROVIDED \"AS IS\"", attribution.LicenseText[2]);
        Assert.StartsWith("The name Astrana and the Astrana logos and other brand features are trademarks of Darin Morris", attribution.TrademarkNotice);
        Assert.Equal("https://github.com/astrana-project/astrana-trusted-attestation-server", attribution.SourceUrl);
    }
}
