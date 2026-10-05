package org.astrana.trustedattestation.config;

import static org.assertj.core.api.Assertions.assertThat;

import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.List;
import org.junit.jupiter.api.Test;

/**
 * The identity provider is read at startup, and this is what makes that true.
 *
 * <p>Record 31 in docs/adr requires a detectable misconfiguration of the identity and access management
 * system to stop the application starting rather than surfacing confusingly at the first member's sign-in.
 * This implementation satisfies that, verified by pointing it at a dead port, where it refuses to come up
 * at all, but it satisfies it by accident rather than by anything written here.
 *
 * <p>The accident is {@code issuer-uri}. Spring resolves a provider configured that way by fetching its
 * discovery document while the context is starting, so an unreachable identity provider fails the context.
 * Spring equally accepts the endpoints listed individually ({@code authorization-uri}, {@code token-uri},
 * {@code jwk-set-uri}), and configured that way it fetches nothing at startup. The application would then
 * come up cleanly against an identity provider that does not exist.
 *
 * <p>So the property lives entirely in a configuration file, is invisible from the code, and would be
 * removed by a change that looks like a harmless expansion of the same settings. This test is the only
 * thing that would notice. It asserts the configuration rather than the behaviour, because asserting the
 * behaviour would mean standing up a context against a dead host and waiting for it to fail, which is
 * slow, and would still not say why it broke when someone changed these lines.
 */
class IamStartupConfigurationTest {

    /** The keys that, if present, mean Spring stops fetching discovery at startup. */
    private static final List<String> ENDPOINTS_THAT_REPLACE_DISCOVERY =
            List.of("authorization-uri", "token-uri", "jwk-set-uri", "user-info-uri");

    /**
     * Read from the source folder, not the classpath, because the dev profiles are left out of the build
     * output so the jar carries no development configuration. Maven runs the tests from the java folder.
     */
    private static String profile(String name) throws IOException {
        Path file = Path.of("src", "main", "resources", name);
        assertThat(file).as("%s is missing", name).isRegularFile();

        return Files.readString(file, StandardCharsets.UTF_8);
    }

    @Test
    void theOidcProviderIsConfiguredByIssuerSoDiscoveryHappensAtStartup() throws IOException {
        assertThat(profile("application-dev.yml"))
                .as("the OIDC provider is no longer configured by issuer-uri, so Spring no longer reads "
                        + "the discovery document while starting -- the application would come up "
                        + "against an IdP that does not exist and fail at the first login instead")
                .contains("issuer-uri:");
    }

    @Test
    void noEndpointIsListedIndividually() throws IOException {
        // Listing them is not an error in itself, but it is what makes issuer-uri redundant: Spring uses
        // what it was given and stops asking the IdP anything at startup. The two together look like
        // belt and braces and are the opposite.
        String configuration = profile("application-dev.yml");

        for (String endpoint : ENDPOINTS_THAT_REPLACE_DISCOVERY) {
            assertThat(configuration)
                    .as(
                            "%s is configured explicitly, which stops the startup discovery fetch that the "
                                    + "fail-fast requirement depends on",
                            endpoint)
                    .doesNotContain(endpoint + ":");
        }
    }
}
