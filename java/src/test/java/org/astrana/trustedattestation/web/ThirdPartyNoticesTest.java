package org.astrana.trustedattestation.web;

import static org.assertj.core.api.Assertions.assertThat;

import java.io.IOException;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.util.List;
import java.util.Map;
import org.astrana.trustedattestation.contract.Attribution;
import org.astrana.trustedattestation.service.LocalizationSettings;
import org.junit.jupiter.api.Test;

/**
 * The third-party notices the build writes into the jar's static resources, and the licence page's link to them.
 *
 * <p>The notices are generated from the dependencies the build resolved, so what is pinned here is that the file is
 * packaged where the server serves it from, and that it carries the texts the licences require: the MySQL driver's
 * whole licence with the GNU General Public License and the Universal FOSS Exception, the licence texts and copyright
 * lines of components that ship none, and Bootstrap's notice for the compiled stylesheet.
 */
class ThirdPartyNoticesTest {

    private static final String NOTICES = "static/THIRD-PARTY-NOTICES.txt";

    @Test
    void theNoticesOpenWithTheProjectsOwnCopyrightAndLicence() throws IOException {
        assertThat(notices()).contains(new Attribution().licenseNotice());
    }

    @Test
    void theMySqlDriversLicenceIsReproducedInFullWithWhereToGetTheSource() throws IOException {
        assertThat(notices())
                .contains("mysql-connector-j 9.7.0")
                .contains("Licence: GPL-2.0-only WITH Universal-FOSS-exception-1.0")
                .contains("GNU General Public License Version 2.0, June 1991")
                .contains("The Universal FOSS Exception, Version 1.0")
                .contains("Source: https://github.com/mysql/mysql-connector-j");
    }

    @Test
    void aLicenceNamedWithoutItsTextIsGivenInFull() throws IOException {
        // Logback ships no licence text and is offered under the Eclipse Public License 2.0 or the LGPL, so the
        // notice names the first and reproduces it from the repository's own copy. ASM ships neither its licence
        // nor its copyright line, so both come from the repository.
        assertThat(notices())
                .contains("logback-classic 1.5.38")
                .contains("Licence: EPL-2.0, chosen from EPL-2.0, LGPL-2.1-only")
                .contains("Eclipse Public License - v 2.0")
                .contains(
                        "asm 9.7.1 (org.ow2.asm:asm)\nLicence: BSD-3-Clause\nCopyright (c) 2000-2011 INRIA, France Telecom")
                .contains("Neither the name of the copyright holder");
    }

    @Test
    void theCompiledStylesheetCarriesBootstrapsNotice() throws IOException {
        assertThat(notices()).contains("Bootstrap 5.3.8").contains("Copyright (c) 2011-2025 The Bootstrap Authors");
    }

    @Test
    void theLicencePageLinksToTheNotices() {
        LocalizationSettings english = new LocalizationSettings(
                List.of("en"), TemplateRendering.STRINGS.supportedLocales(), "en", Map.of("en", "English"));

        String page = TemplateRendering.render("license", "/license", english, "en", Map.of());

        assertThat(page).contains("href=\"/THIRD-PARTY-NOTICES.txt\"");
    }

    private static String notices() throws IOException {
        try (InputStream in = ThirdPartyNoticesTest.class.getClassLoader().getResourceAsStream(NOTICES)) {
            assertThat(in).as(NOTICES + " is packaged").isNotNull();
            return new String(in.readAllBytes(), StandardCharsets.UTF_8);
        }
    }
}
