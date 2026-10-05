package org.astrana.trustedattestation.contract;

import static org.assertj.core.api.Assertions.assertThat;

import org.junit.jupiter.api.Test;

/**
 * What the fixed attribution footer reads, loaded from the packaged {@code contract/attribution.json}.
 *
 * <p>The same file backs all three implementations, so the notice is identical everywhere. These tests
 * check that each field of that shared file lands in the field the page renders. They check that
 * {@code project_url} is not read where {@code project_name} should be, that the nested
 * {@code license.notice}, label and link are surfaced, and that the licence page gets the licence text, the
 * trademark notice and the source address. A regression in the mapping would compile cleanly and only show
 * as the wrong text in the footer or on the licence page, which nothing else checks.
 */
class AttributionTest {

    private final Attribution attribution = new Attribution();

    @Test
    void surfacesTheProjectIdentityFromTheContractFile() {
        // The exact strings the contract file carries: a mapping that read the wrong key would still be a
        // non-blank value and pass an .isNotEmpty() check, so the values themselves are asserted.
        assertThat(attribution.projectName()).isEqualTo("Astrana Trusted Attestation Server");
        assertThat(attribution.projectUrl()).isEqualTo("https://astrana.org");
    }

    @Test
    void carriesTheLicenceNoticeLabelAndLink() {
        // The footer links a short label to the software's own licence page (/license), where the fuller
        // notice is shown. The loader reads all three from the contract file rather than inventing any of
        // them; the project is MIT-licensed, so editing that one file is what changes the wording and, if
        // it ever moves off-site, the link.
        assertThat(attribution.licenseNotice())
                .isEqualTo(
                        "Copyright (c) 2026 Darin Morris and contributors. Free and open source software, licensed under the MIT License.");
        assertThat(attribution.licenseLabel()).isEqualTo("Licence");
        assertThat(attribution.licenseUrl()).isEqualTo("/license");
    }

    @Test
    void carriesTheLicenceTextTheTrademarkNoticeAndTheSourceAddress() {
        // The licence page shows the MIT text in full, the trademark notice and where the source lives, all
        // from the same contract file, so the three implementations render the same page. The text is the
        // three paragraphs of LICENSE.md.
        assertThat(attribution.licenseText()).hasSize(3);
        assertThat(attribution.licenseText().get(0)).startsWith("Permission is hereby granted");
        assertThat(attribution.licenseText().get(2)).startsWith("THE SOFTWARE IS PROVIDED \"AS IS\"");
        assertThat(attribution.trademarkNotice())
                .startsWith(
                        "The name Astrana and the Astrana logos and other brand features are trademarks of Darin Morris");
        assertThat(attribution.sourceUrl())
                .isEqualTo("https://github.com/astrana-project/astrana-trusted-attestation-server");
    }
}
