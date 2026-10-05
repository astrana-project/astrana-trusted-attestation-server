package org.astrana.trustedattestation.web;

import static org.assertj.core.api.Assertions.assertThat;

import org.astrana.trustedattestation.contract.Attribution;
import org.junit.jupiter.api.Test;
import org.springframework.ui.ExtendedModelMap;
import org.springframework.ui.Model;

/**
 * The public licence page.
 *
 * <p>It has none of the landing page's moving parts -- no locale resolution, no org branding, no
 * sign-out marker -- because it is a statement about the software, not the organisation: fixed English,
 * fixed direction, and the same attribution the footer already carries. So what there is to pin is that
 * the page is served (the "license" view) and that it is handed the attribution the template renders into
 * the heading, the notice, and the footer -- the real contract values, not a stand-in, so this moves if
 * the packaged {@code attribution.json} ever stopped being read.
 *
 * <p>Rendered end to end and asserted byte-for-byte against the other implementations by the shared
 * conformance suite; a unit test cannot see the response body without a servlet, so it pins the wiring
 * the template depends on instead.
 */
class LicenseControllerTest {

    private final Attribution attribution = new Attribution();

    private final LicenseController controller = new LicenseController(attribution);

    @Test
    void theLicensePageIsServedWithTheAttributionItRenders() {
        Model model = new ExtendedModelMap();

        String view = controller.license(model);

        assertThat(view).isEqualTo("license");
        assertThat(model.getAttribute("attribution")).isSameAs(attribution);
    }

    @Test
    void theAttributionCarriesTheProjectNameAndLicenceNoticeThePageShows() {
        // The heading is the project name and the body is the licence notice; both come from the packaged
        // contract file rather than any wording invented here. Asserting the real values ties the page to
        // the contract -- the same values the .NET and PHP implementations render, so all three match.
        Model model = new ExtendedModelMap();

        controller.license(model);

        Attribution rendered = (Attribution) model.getAttribute("attribution");
        assertThat(rendered).isNotNull();
        assertThat(rendered.projectName()).isEqualTo("Astrana Trusted Attestation Server");
        assertThat(rendered.licenseNotice())
                .isEqualTo(
                        "Copyright (c) 2026 Darin Morris and contributors. Free and open source software, licensed under the MIT License.");
    }
}
