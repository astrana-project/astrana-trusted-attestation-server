<?php

declare(strict_types=1);

namespace App\Security;

use App\Exceptions\ConfigurationException;

/**
 * Astrana Trusted Attestation refuses to run without TLS, not "recommends", not "works but warns".
 * Sign-in credentials, identity provider tokens and public keys all pass through it, so serving any of
 * that over plain HTTP defeats the point.
 *
 * On shared hosting the panel's own web server almost always holds the certificate, so TLS terminating
 * in front of the application is the normal case here, but it still has to be declared. There is no
 * auto-detection and no default that lets an unconfigured instance serve plain HTTP.
 *
 * A class of its own, matching the other two implementations, rather than a private method on the
 * service provider. The rule is one of the few this system refuses to start without, and it was the only
 * one of the three that could not be tested without booting an HTTP request through a provider.
 */
final class TlsRequirement
{
    /**
     * Checks the deployment as configured.
     *
     * @throws ConfigurationException when nothing in the configuration says this app will be reached over TLS
     */
    public static function enforce(bool $terminatedByProxy, string $appUrl): void
    {
        if ($terminatedByProxy) {
            return;
        }

        if (! str_starts_with(strtolower($appUrl), 'https://')) {
            throw new ConfigurationException(
                'Refusing to serve without TLS. Set APP_URL to an https:// URL and serve the app over '
                .'HTTPS, or set TRUSTED_ATTESTATION_TLS_TERMINATED_BY_PROXY=true if a reverse proxy '
                .'terminates TLS in front of it. There is no plain-HTTP fallback.'
            );
        }
    }

    /**
     * Whether a request has to be refused because it did not arrive over TLS, with no proxy declared.
     *
     * The configuration check above only says the instance is meant to be reached over HTTPS. A web server
     * that also answers plain HTTP on the same application would still hand it requests that travelled
     * unencrypted, and nothing about them can be trusted, so each one is refused. Behind a declared proxy
     * the application cannot see the connection, and the forwarded header is judged instead (see
     * refusesForwardedRequest).
     */
    public static function refusesPlainRequest(bool $terminatedByProxy, bool $arrivedOverTls): bool
    {
        return ! $terminatedByProxy && ! $arrivedOverTls;
    }

    /**
     * Whether a request a proxy forwarded has to be refused despite the configuration being acceptable.
     *
     * With TLS at a proxy the app cannot see the scheme itself, so it trusts the forwarded header -- and
     * must then refuse anything the proxy did not positively mark as HTTPS. Without this, "terminated by
     * proxy" would be a way to turn the requirement off.
     *
     * The header is judged as the framework will apply it: Symfony takes the first comma-separated token
     * of the first X-Forwarded-Proto line, trimmed, as the scheme. That token has to be exactly "https".
     * A header carrying more than one value, as more than one token on a line or more than one line, is
     * refused whatever the values are: one proxy sets one scheme, so a second value is a client's or an
     * unexpected hop's, and which one the framework would pick is not something to be right about by
     * luck. All three implementations judge the header this way.
     *
     * A missing header is not a refusal: not every proxy sets one, and the app cannot tell "no proxy"
     * from "a proxy that says nothing". Refusing there would break correctly configured deployments.
     *
     * @param  list<string>  $forwardedProtoLines  every X-Forwarded-Proto header line on the request
     */
    public static function refusesForwardedRequest(bool $terminatedByProxy, array $forwardedProtoLines): bool
    {
        if (! $terminatedByProxy || $forwardedProtoLines === []) {
            return false;
        }

        if (count($forwardedProtoLines) !== 1) {
            return true;
        }

        $tokens = explode(',', $forwardedProtoLines[0]);

        return count($tokens) !== 1 || trim($tokens[0]) !== 'https';
    }
}
