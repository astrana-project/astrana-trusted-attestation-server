package org.astrana.trustedattestation.security;

import org.springframework.boot.tomcat.servlet.TomcatServletWebServerFactory;
import org.springframework.boot.web.server.Ssl;
import org.springframework.boot.web.server.WebServerFactoryCustomizer;
import org.springframework.core.Ordered;
import org.springframework.stereotype.Component;

/**
 * When Tomcat terminates TLS itself, serve TLS 1.2 or later only.
 *
 * <p>Tomcat's own default already refuses TLS 1.0 and 1.1 on the JDKs this app runs on, but a default is a
 * property of the runtime, not of this app, and it is one a JVM security policy or a future connector
 * default could move. Pinning the floor here keeps this stack consistent with the .NET implementation,
 * which pins the same two versions in Kestrel, and independent of host configuration.
 *
 * <p>Applied only when SSL is configured on the server at all, so it has no effect in the proxy-terminated
 * shape, where Tomcat serves plain HTTP behind the proxy that holds the certificate. It is a default, not an
 * override: an operator who sets {@code server.ssl.enabled-protocols} explicitly keeps their value. Setting
 * the same thing in {@code application.yml} would not do: any {@code server.ssl.*} key switches SSL on, and
 * a proxy-terminated instance with no key material would then refuse to start.
 */
@Component
public class TlsProtocolFloor implements WebServerFactoryCustomizer<TomcatServletWebServerFactory>, Ordered {

    static final String[] PROTOCOLS = {"TLSv1.2", "TLSv1.3"};

    @Override
    public void customize(TomcatServletWebServerFactory factory) {
        Ssl ssl = factory.getSsl();
        if (!Ssl.isEnabled(ssl)) {
            return;
        }

        if (ssl.getEnabledProtocols() == null || ssl.getEnabledProtocols().length == 0) {
            ssl.setEnabledProtocols(PROTOCOLS.clone());
        }
    }

    /**
     * After Boot's own customizer, which is what copies {@code server.ssl.*} onto the factory. Running
     * before it would read an SSL configuration that is not there yet.
     */
    @Override
    public int getOrder() {
        return Ordered.LOWEST_PRECEDENCE;
    }
}
