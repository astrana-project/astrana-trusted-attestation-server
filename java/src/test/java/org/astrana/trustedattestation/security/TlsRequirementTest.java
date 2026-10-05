package org.astrana.trustedattestation.security;

import static org.assertj.core.api.Assertions.assertThatCode;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;
import org.springframework.boot.context.event.ApplicationPreparedEvent;
import org.springframework.context.ConfigurableApplicationContext;
import org.springframework.mock.env.MockEnvironment;

/**
 * The rule this enforces is that there is no way to end up serving plain HTTP by accident, so the tests
 * that matter are the ones where something looks configured but is not.
 */
class TlsRequirementTest {

    private final TlsRequirement requirement = new TlsRequirement();

    private void check(String... properties) {
        MockEnvironment environment = new MockEnvironment();
        for (int i = 0; i < properties.length; i += 2) {
            environment.setProperty(properties[i], properties[i + 1]);
        }

        ConfigurableApplicationContext context = org.mockito.Mockito.mock(ConfigurableApplicationContext.class);
        org.mockito.Mockito.when(context.getEnvironment()).thenReturn(environment);

        requirement.onApplicationEvent(
                new ApplicationPreparedEvent(new org.springframework.boot.SpringApplication(), new String[0], context));
    }

    @Test
    @DisplayName("a keystore with SSL switched off is refused, not accepted")
    void explicitlyDisabledSslIsRefusedEvenWithAKeystore() {
        // The regression this exists for: the dev profile configures a keystore, so a check that looked
        // only for the presence of server.ssl.* passed while server.ssl.enabled=false made Tomcat bind
        // plain HTTP. An operator switching SSL off got a working HTTP server and no complaint.
        assertThatThrownBy(() -> check(
                        "server.ssl.enabled", "false",
                        "server.ssl.key-store", "file:dev/dev-keystore.p12"))
                .isInstanceOf(IllegalStateException.class)
                .hasMessageContaining("server.ssl.enabled is false");
    }

    @Test
    @DisplayName("the message blames server.ssl.enabled only when that is what is wrong")
    void theMessageDistinguishesTheTwoWaysOfHavingNoTls() {
        // Static analysis claims the ternary that adds this sentence always evaluates to true, which
        // would mean an operator who simply has not configured TLS yet is told that a setting they never
        // touched is the cause. Settled here rather than by argument: there are two distinct ways to
        // arrive at the refusal and the message has to tell them apart.
        assertThatThrownBy(this::check)
                .hasMessageContaining("Refusing to start without TLS")
                .hasMessageNotContaining("server.ssl.enabled is false");
    }

    @Test
    @DisplayName("nothing configured at all is refused")
    void noTlsAtAllIsRefused() {
        assertThatThrownBy(this::check)
                .isInstanceOf(IllegalStateException.class)
                .hasMessageContaining("Refusing to start without TLS");
    }

    @Test
    @DisplayName("SSL switched off is refused even when a proxy is not claimed")
    void disabledWithoutMaterialIsRefused() {
        assertThatThrownBy(() -> check("server.ssl.enabled", "false")).isInstanceOf(IllegalStateException.class);
    }

    @Test
    @DisplayName("a keystore alone is accepted, because Spring enables SSL once material exists")
    void aKeystoreAloneIsAccepted() {
        assertThatCode(() -> check("server.ssl.key-store", "file:dev/dev-keystore.p12"))
                .doesNotThrowAnyException();
    }

    @Test
    @DisplayName("an SSL bundle is accepted")
    void anSslBundleIsAccepted() {
        assertThatCode(() -> check("server.ssl.bundle", "server")).doesNotThrowAnyException();
    }

    @Test
    @DisplayName("a proxy terminating TLS satisfies the requirement, but only when said explicitly")
    void aProxyTerminatingTlsIsAccepted() {
        assertThatCode(() -> check("trusted-attestation.tls.terminated-by-proxy", "true"))
                .doesNotThrowAnyException();
    }

    @Test
    @DisplayName("a proxy claim overrides even an explicit SSL opt-out, since the proxy is the TLS hop")
    void aProxyClaimWinsOverDisabledSsl() {
        assertThatCode(() -> check(
                        "trusted-attestation.tls.terminated-by-proxy", "true",
                        "server.ssl.enabled", "false"))
                .doesNotThrowAnyException();
    }
}
