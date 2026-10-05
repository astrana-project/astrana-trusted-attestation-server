package org.astrana.trustedattestation.security;

import org.springframework.boot.context.event.ApplicationPreparedEvent;
import org.springframework.context.ApplicationListener;
import org.springframework.core.env.Environment;

/**
 * Astrana Trusted Attestation refuses to run without TLS, not "recommends", not "works but warns". Sign-in
 * credentials, identity system tokens and public keys all pass through it, so serving any of that over
 * plain HTTP defeats the point. If TLS is not configured the application refuses to start rather than
 * starting insecurely.
 *
 * <p>Checked on {@link ApplicationPreparedEvent}, which fires after the environment is populated but
 * before the context refreshes and the embedded Tomcat binds a port. Failing here means the application
 * never listens at all, rather than listening and then complaining.
 *
 * <p>A reverse proxy terminating TLS satisfies the requirement, the normal shape for a Spring Boot
 * application behind Nginx, or a web archive deployed into an existing Tomcat, but only when the operator
 * says so explicitly. There is no auto-detection and no default that lets an unconfigured instance serve
 * plain HTTP.
 */
public class TlsRequirement implements ApplicationListener<ApplicationPreparedEvent> {

    private static final String SSL_ENABLED = "server.ssl.enabled";

    @Override
    public void onApplicationEvent(ApplicationPreparedEvent event) {
        Environment environment = event.getApplicationContext().getEnvironment();

        if (terminatedByProxy(environment) || servesHttpsItself(environment)) {
            return;
        }

        throw new IllegalStateException(refusal(environment));
    }

    /**
     * A proxy in front satisfies the requirement, but only when the operator says so. There is no
     * auto-detection: an unconfigured instance must not be able to talk its way into serving plain HTTP.
     */
    private static boolean terminatedByProxy(Environment environment) {
        return Boolean.TRUE.equals(
                environment.getProperty("trusted-attestation.tls.terminated-by-proxy", Boolean.class, false));
    }

    private static boolean servesHttpsItself(Environment environment) {
        // An explicit opt-out settles it, whatever else is configured. This is the case that made the
        // original check wrong: server.ssl.enabled=false disables TLS even with a keystore present, so
        // reading "a keystore is configured" as "TLS is on" let the opt-out through and Tomcat bound
        // plain HTTP.
        if (explicitlyDisabled(environment)) {
            return false;
        }

        // Absent means "not overridden", which Spring treats as enabled once key material exists -- so
        // material alone is enough. An explicit true is enough on its own too: if it turns out there is
        // nothing to serve, Tomcat fails to bind, which is also a refusal to start.
        return Boolean.TRUE.equals(environment.getProperty(SSL_ENABLED, Boolean.class)) || hasKeyMaterial(environment);
    }

    private static boolean explicitlyDisabled(Environment environment) {
        return Boolean.FALSE.equals(environment.getProperty(SSL_ENABLED, Boolean.class));
    }

    private static boolean hasKeyMaterial(Environment environment) {
        return environment.getProperty("server.ssl.bundle") != null
                || environment.getProperty("server.ssl.key-store") != null
                || environment.getProperty("server.ssl.certificate") != null;
    }

    /**
     * Names the opt-out only when that is what happened. An operator who has simply not configured TLS
     * yet should not be sent looking at a setting they never touched.
     */
    private static String refusal(Environment environment) {
        String cause =
                explicitlyDisabled(environment) ? SSL_ENABLED + " is false, so this app would serve plain HTTP. " : "";

        return "Refusing to start without TLS. " + cause
                + "Configure server.ssl.* so the app serves HTTPS itself, or "
                + "set trusted-attestation.tls.terminated-by-proxy=true if a reverse proxy terminates TLS in front "
                + "of it. There is no plain-HTTP fallback.";
    }
}
