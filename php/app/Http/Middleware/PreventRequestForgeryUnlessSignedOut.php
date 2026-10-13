<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\MemberIdentityResolver;
use Closure;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's anti-forgery check, answered and scoped the way the other two implementations answer and
 * scope theirs.
 *
 * Every change a signed-in member makes to a relationship or to their session needs the token: the sign-out,
 * so another site cannot sign a member out, and on the API setting or clearing a key, removing a relationship
 * and the self-revoke. A page on another site, a sibling subdomain included, counts as the same site for the
 * session cookie, so the cookie alone does not show the member asked for the change. On the API the token
 * travels in the X-CSRF-TOKEN header only, and on the sign-out in that header or the form's _token field. The
 * rest of the API, reads and the anonymous attestation lookup, stays exempt (bootstrap/app.php).
 *
 * With no member signed in, the session expired or never started, none of them asks for the token. The
 * sign-out has nothing to end and answers its redirect to /signed-out (decision record 41 in docs/adr), and
 * the API answers 401, as on the other two implementations.
 *
 * A missing or wrong token answers HTTP 403 - Forbidden, which the exception handler sends with no body
 * and no Content-Type, rather than Laravel's own 419. The token is asked for whatever the request says
 * about where it came from, because the other two implementations do not accept a Sec-Fetch-Site header
 * in its place.
 *
 * It replaces the framework's middleware in the web group (bootstrap/app.php) and keeps its list of
 * excluded paths, which is shared through the parent's static state.
 */
final class PreventRequestForgeryUnlessSignedOut extends PreventRequestForgery
{
    /**
     * The member's changes under both API prefixes (routes/web.php): PUT and DELETE on the key, POST on the
     * revoke. Routing answers any other method on these paths before this middleware runs.
     */
    private const MEMBER_CHANGES = [
        'api/me/relationships/*/key',
        'api/v1/me/relationships/*/key',
        'api/me/relationships/*/revoke',
        'api/v1/me/relationships/*/revoke',
    ];

    /** @param  Request  $request */
    public function handle($request, Closure $next): Response
    {
        try {
            return parent::handle($request, $next);
        } catch (TokenMismatchException) {
            abort(Response::HTTP_FORBIDDEN, '');
        }
    }

    /** @param  Request  $request */
    protected function inExceptArray($request): bool
    {
        // The same test the controllers apply before they act.
        if ($request->is('signout') || $request->is(...self::MEMBER_CHANGES)) {
            return app(MemberIdentityResolver::class)->subject($request) === null;
        }

        return parent::inExceptArray($request);
    }

    /**
     * On the API, the X-CSRF-TOKEN header and nothing else, which is where the contract puts the token and
     * the only place the other two implementations look. Elsewhere, the sign-out form among them, the
     * framework's usual places.
     *
     * @param  Request  $request
     */
    protected function getTokenFromRequest($request): ?string
    {
        if ($request->is('api/*')) {
            return $request->header('X-CSRF-TOKEN');
        }

        return parent::getTokenFromRequest($request);
    }

    /** @param  Request  $request */
    protected function hasValidOrigin($request): bool
    {
        return false;
    }
}
