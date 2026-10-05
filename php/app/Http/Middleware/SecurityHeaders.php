<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response headers that harden how a browser treats this app's pages.
 *
 * Set explicitly rather than left to the framework, because the three implementations
 * otherwise disagree by accident: Spring Security sends these by default, Laravel and ASP.NET Core send
 * nothing. Three implementations held to parity (decision record 5 in docs/adr) should not differ on how a browser
 * is told to treat the pages, so decision record 35 fixes the set.
 *
 * HSTS is absent. This stack is the one most likely to run behind someone else's web server, shared
 * hosting or a panel-managed virtual host, where the certificate and the redirect policy belong to that
 * layer, and an application-level HSTS header would be making a promise about a hostname it does not
 * control. The PHP installation page (docs/installation/php.md) shows how to set it at that layer.
 *
 * There is no Content-Security-Policy either. The self-service page carries an inline script, so a policy
 * strict enough to be worth having needs per-response nonces threaded through the view. A policy with
 * unsafe-inline would look like protection while permitting exactly what CSP exists to stop, which is
 * worse than being honest about not having one.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        return self::apply($next($request));
    }

    /**
     * Sets the headers on a response. Also called by the exception handler in bootstrap/app.php, because a
     * refusal raised while the application boots (the HTTP 421 for a request forwarded over plain HTTP, or
     * a configuration error) is answered before any middleware runs.
     */
    public static function apply(Response $response): Response
    {
        // Content-type sniffing turns a response the server labelled as data into one the browser may
        // decide to execute.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Nothing here is meant to be framed. The self-service page shows who someone is and lets them
        // register a key, which is precisely what a clickjacking overlay would want to sit on top of.
        $response->headers->set('X-Frame-Options', 'DENY');

        // A member's session pages are private to them, so a link they follow to another site should not
        // tell that site, in a Referer header, where they came from.
        $response->headers->set('Referrer-Policy', 'no-referrer');

        // Explicitly off, matching the other two implementations. The legacy XSS auditor this header once
        // switched on is a source of vulnerabilities of its own in the browsers that still have it, and 0
        // disables it rather than leaving the browser to its default.
        $response->headers->set('X-XSS-Protection', '0');

        // Nothing this application serves should be cached. The self-service page is per member and the
        // API answers are point-in-time. All three implementations send no-store so nothing is ever
        // cached. The exact directive string still differs, because Symfony sorts the directives and adds
        // its own "private" and the other two frameworks format the header their own way. Byte-matching
        // that across three HTTP stacks would mean bypassing each one's header machinery for no
        // behavioural gain.
        $response->headers->set('Cache-Control', 'no-cache, no-store, max-age=0, must-revalidate');

        // PHP announces itself and its version when expose_php is on, which a hosting account's php.ini
        // usually leaves it. The other two implementations name no runtime.
        header_remove('X-Powered-By');

        return $response;
    }
}
