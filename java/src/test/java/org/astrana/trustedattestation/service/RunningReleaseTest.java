package org.astrana.trustedattestation.service;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import java.io.IOException;
import java.io.InputStream;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.Properties;
import java.util.regex.Matcher;
import java.util.regex.Pattern;
import org.astrana.trustedattestation.config.TrustedAttestationConfig;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.ObjectProvider;
import org.springframework.beans.factory.support.DefaultListableBeanFactory;
import org.springframework.boot.info.BuildProperties;

/**
 * The version the self-service page shows in its footer (decision record 46 in docs/adr). The build records the
 * version from pom.xml in META-INF/build-info.properties, and it has to be the top heading of CHANGELOG.md, the
 * version the release is tagged and published under, so that the version shown is the release that is running.
 */
class RunningReleaseTest {

    private static final Pattern CHANGELOG_HEADING = Pattern.compile("^## \\[(\\d+\\.\\d+\\.\\d+)]", Pattern.MULTILINE);

    private static String changelogVersion() throws IOException {
        Matcher heading = CHANGELOG_HEADING.matcher(Files.readString(Path.of("CHANGELOG.md")));
        assertThat(heading.find()).as("CHANGELOG.md has a version heading").isTrue();
        return heading.group(1);
    }

    private static BuildProperties buildInfo() throws IOException {
        Properties recorded = new Properties();
        try (InputStream stream = RunningReleaseTest.class.getResourceAsStream("/META-INF/build-info.properties")) {
            assertThat(stream)
                    .as("the build writes META-INF/build-info.properties")
                    .isNotNull();
            recorded.load(stream);
        }

        Properties entries = new Properties();
        recorded.stringPropertyNames()
                .forEach(name -> entries.put(name.substring("build.".length()), recorded.get(name)));
        return new BuildProperties(entries);
    }

    @Test
    void theRunningVersionIsTheTopHeadingOfTheChangelog() throws IOException {
        assertThat(RunningRelease.of(buildInfo()).version()).isEqualTo(changelogVersion());
    }

    @Test
    void withoutTheVersionTheBuildRecordsTheServerRefusesToStartAndSaysToBuildWithMaven() {
        // Started from an IDE that skipped the Maven build, the record is missing. The server stops at start with
        // a message that says what to do, as the other two implementations refuse to build or to serve the page.
        ObjectProvider<BuildProperties> none = new DefaultListableBeanFactory().getBeanProvider(BuildProperties.class);

        TrustedAttestationConfig config = new TrustedAttestationConfig();

        assertThatThrownBy(() -> config.runningRelease(none))
                .isInstanceOf(IllegalStateException.class)
                .hasMessageContaining("META-INF/build-info.properties")
                .hasMessageContaining("Build the server with Maven");
    }

    @Test
    void theLabelIsVFollowedByTheFullVersion() {
        assertThat(new RunningRelease("1.2.3").label()).isEqualTo("v1.2.3");
    }

    @Test
    void itLinksToTheFixedReleasesPage() {
        assertThat(new RunningRelease("1.2.3").releasesUrl()).isEqualTo("https://www.astrana.org/releases");
    }
}
