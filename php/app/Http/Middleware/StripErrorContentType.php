<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Removes the Content-Type header from a body-less error response.
 *
 * An error carries a status code and nothing else (decision record 26 in docs/adr). The endpoints and the
 * exception handler build these as an empty response, and Symfony's
 * {@see Response::prepare()} then stamps the framework default {@code Content-Type: text/html} on the
 * empty body -- announcing a body type for a body that is not there. ASP.NET Core and Spring send no
 * Content-Type on a body-less error, so without this an unadorned 401/404/405 carries a header on Laravel
 * that the other two never send, and the same error looks different depending on which stack served it.
 *
 * Removing the header from the response object is not enough on its own. When userland sends no
 * Content-Type, PHP's SAPI stamps its default_mimetype (text/html) at the point headers are flushed --
 * after every middleware has run -- so the header reappears. So the current request's default_mimetype is
 * emptied too, which tells the SAPI to add nothing. ini_set is restored automatically at request
 * shutdown, so this touches only the current body-less error response and never leaks to the next request
 * on a reused worker; and that response carries no other Content-Type for it to affect.
 *
 * Registered as a global middleware ahead of the rest (a prepend), for the same reason as
 * {@see StripSessionCookies}: the header is stamped deep in the request's finish, so the removal has to
 * come from the outside. Scoped to status >= 400 with an empty body, so it never touches a HEAD response
 * (whose empty body still describes the GET's real type), a 204, or anything that actually carries data.
 */
final class StripErrorContentType
{
    public function handle(Request $request, Closure $next): Response
    {
        return self::strip($next($request));
    }

    /**
     * Removes the header from a body-less error. Also called by the exception handler in
     * bootstrap/app.php, because a refusal raised while the application boots is answered before any
     * middleware runs.
     */
    public static function strip(Response $response): Response
    {
        $content = $response->getContent();
        if ($response->getStatusCode() >= 400 && ($content === '' || $content === false)) {
            $response->headers->remove('Content-Type');
            ini_set('default_mimetype', '');
        }

        return $response;
    }
}
