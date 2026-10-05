package org.astrana.trustedattestation.config;

import static org.assertj.core.api.Assertions.assertThat;

import java.io.IOException;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import org.junit.jupiter.api.Test;

/**
 * The packaged defaults the other two implementations are held to as well.
 *
 * <p>Like {@code IamStartupConfigurationTest}, these assert the configuration file rather than the behaviour:
 * standing up a context to watch a session expire eight hours later is not a test anyone would run, and the
 * value is a single line that a tidy-up could lose without any other test noticing.
 */
class DefaultConfigurationTest {

    private static String defaults() throws IOException {
        try (InputStream stream =
                DefaultConfigurationTest.class.getClassLoader().getResourceAsStream("application.yml")) {
            assertThat(stream).as("application.yml is missing").isNotNull();

            return new String(stream.readAllBytes(), StandardCharsets.UTF_8);
        }
    }

    @Test
    void anIdleSessionEndsAfterEightHoursOnEveryImplementation() throws IOException {
        // Boot's default is 30 minutes. A member idle for half an hour would be signed out here but still
        // signed in on the other two, so the value is set explicitly and pinned.
        assertThat(defaults()).contains("timeout: 8h");
    }

    @Test
    void theSupportedLocalesSettingIsPresentAndEmptyByDefault() throws IOException {
        // Empty means every shipped locale, which is what an organisation that has not chosen gets, and
        // the key being in the reference file is how an operator finds out it exists.
        assertThat(defaults()).contains("supported-locales: []");
    }

    @Test
    void theDefaultsSetNoServerHeader() throws IOException {
        // Tomcat sends a Server header only when server.server-header gives it one. No implementation
        // names its runtime in a response, so the setting stays out of the defaults. RuntimeNameTest reads
        // the headers off a running Tomcat.
        assertThat(defaults()).doesNotContain("server-header");
    }

    @Test
    void theTlsFloorIsNotASslPropertyInTheDefaults() throws IOException {
        // Any server.ssl.* key in the defaults would switch SSL on for the proxy-terminated shape too, and
        // an instance with no key material would then refuse to start. The floor lives in TlsProtocolFloor.
        assertThat(defaults()).doesNotContain("enabled-protocols");
    }
}
