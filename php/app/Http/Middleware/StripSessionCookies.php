<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Removes the session and CSRF cookies from the responses of the anonymous, stateless surfaces, which are
 * the landing page, the post-sign-out page, the licence page, the manifest, /attest, the SAML service
 * provider metadata, and the language switcher's POST.
 *
 * Laravel starts a session on every web request and returns its cookie, plus a CSRF cookie, whether or
 * not the caller has any use for one. A verifying Astrana instance calling /attest, or anything reading
 * the manifest or the metadata, is not a browser building up a session. Issuing it one on every call is
 * waste the other two implementations do not produce, because .NET and Java send no cookie here. The
 * self-service page and its API keep their cookies untouched, because their whole authentication is that
 * session cookie.
 *
 * Registered as a global middleware ahead of the session middleware, not on the routes themselves,
 * because the cookies are attached as the response passes back out through the session and cookie
 * middleware -- inside any route middleware. Only something wrapping those from the outside sees the
 * cookies in place to remove them. The paths are matched here for the same reason. The session stays
 * available to code during the request; this only stops the cookie being written back, which for an
 * anonymous request persists nothing anyway.
 */
final class StripSessionCookies
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // The stateless surfaces, plus any 401: an unauthenticated response has no session to persist,
        // so it should not plant one -- which is what .NET already does and Java and Laravel did not.
        // A 401 is safe to catch here where the browser login redirect (a 302, carrying the OIDC state
        // in the session it legitimately needs) is not.
        if (! $this->isStateless($request) && $response->getStatusCode() !== 401) {
            return $response;
        }

        $sessionCookie = $request->hasSession() ? $request->session()->getName() : null;
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $sessionCookie || $cookie->getName() === 'XSRF-TOKEN') {
                $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            }
        }

        return $response;
    }

    private function isStateless(Request $request): bool
    {
        // /set-language answers with exactly one cookie, the locale, as the other two implementations do.
        // A signed-in member who switches language keeps the session cookie they already hold.
        return $request->path() === '/'
            || $request->is('signed-out')
            || $request->is('license')
            || $request->is('set-language')
            || $request->is('.well-known/*')
            || $request->is('auth/saml/metadata')
            || $request->is('api/v1/attest')
            || $request->is('api/attest');
    }
}
