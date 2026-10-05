package org.astrana.trustedattestation.security;

import static org.assertj.core.api.Assertions.assertThat;

import org.junit.jupiter.api.Test;
import org.springframework.boot.tomcat.servlet.TomcatServletWebServerFactory;
import org.springframework.boot.web.server.Ssl;

/**
 * TLS 1.2 or later when Tomcat terminates TLS itself, and nothing at all when a proxy does.
 *
 * <p>The second half is the one that bites: a floor expressed as a {@code server.ssl.*} property would
 * switch SSL on, and a proxy-terminated instance with no key material would refuse to start. So the floor
 * is applied only to an SSL configuration that already exists, and an operator's own protocol list is left
 * alone.
 */
class TlsProtocolFloorTest {

    private final TlsProtocolFloor floor = new TlsProtocolFloor();

    @Test
    void anSslConfigurationWithNoProtocolListGetsTheFloor() {
        TomcatServletWebServerFactory factory = new TomcatServletWebServerFactory();
        Ssl ssl = new Ssl();
        ssl.setKeyStore("file:dev/dev-keystore.p12");
        factory.setSsl(ssl);

        floor.customize(factory);

        assertThat(factory.getSsl().getEnabledProtocols()).containsExactly("TLSv1.2", "TLSv1.3");
    }

    @Test
    void anOperatorsOwnProtocolListIsKept() {
        TomcatServletWebServerFactory factory = new TomcatServletWebServerFactory();
        Ssl ssl = new Ssl();
        ssl.setEnabledProtocols(new String[] {"TLSv1.3"});
        factory.setSsl(ssl);

        floor.customize(factory);

        assertThat(factory.getSsl().getEnabledProtocols()).containsExactly("TLSv1.3");
    }

    @Test
    void noSslConfigurationIsLeftAsNoSslConfiguration() {
        // The proxy-terminated shape: Tomcat serves plain HTTP behind the proxy that holds the certificate.
        // Creating an SSL configuration here would make Tomcat look for key material that does not exist.
        TomcatServletWebServerFactory factory = new TomcatServletWebServerFactory();

        floor.customize(factory);

        assertThat(factory.getSsl()).isNull();
    }

    @Test
    void sslSwitchedOffIsNotSwitchedBackOn() {
        TomcatServletWebServerFactory factory = new TomcatServletWebServerFactory();
        Ssl ssl = new Ssl();
        ssl.setEnabled(false);
        factory.setSsl(ssl);

        floor.customize(factory);

        assertThat(factory.getSsl().isEnabled()).isFalse();
        assertThat(factory.getSsl().getEnabledProtocols()).isNull();
    }
}
