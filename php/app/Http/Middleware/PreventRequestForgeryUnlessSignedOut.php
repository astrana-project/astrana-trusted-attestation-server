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
 * Two plain POSTs need the token when a member is signed in: the sign-out, so another site cannot sign a
 * member out, and the self-revoke, so a form on another site, a sibling subdomain included, cannot revoke
 * a member's relationship. The token travels in the X-CSRF-TOKEN header or the form's _token field. The
 * rest of the API stays exempt (bootstrap/app.php says why).
 *
 * With no member signed in, the session expired or never started, neither asks for the token. The
 * sign-out has nothing to end and answers its redirect to /signed-out (decision record 42 in docs/adr), and the
 * revoke answers 401, as on the other two implementations.
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
    /** The self-revoke under both API prefixes (routes/web.php). */
    private const SELF_REVOKE = ['api/me/relationships/*/revoke', 'api/v1/me/relationships/*/revoke'];

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
        if ($request->is('signout') || $request->is(...self::SELF_REVOKE)) {
            return app(MemberIdentityResolver::class)->subject($request) === null;
        }

        return parent::inExceptArray($request);
    }

    /** @param  Request  $request */
    protected function hasValidOrigin($request): bool
    {
        return false;
    }
}
