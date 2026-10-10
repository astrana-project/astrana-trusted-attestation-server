<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\MemberIdentityResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Caps the request body at 64 kilobytes, answering HTTP 413 Content Too Large with no body to anything
 * larger, as the other two implementations do.
 *
 * The largest legitimate body is a JSON object holding one base64 public key, well under a kilobyte. A
 * body many times that is not a key, and reading and decoding it would spend memory and time on the
 * anonymous attestation endpoint for nobody's benefit. The declared Content-Length is checked first, so an
 * oversize body is refused before it is read, and the body's real length second, for a request that
 * declares none. The request carries no more of the body than one byte past the cap
 * (App\Support\RequestCapture), so a body sent in chunks costs no more than that to measure, however large.
 *
 * Only on the two operations that read a body, setting a key and the attestation lookup (routes/web.php).
 * The other two implementations read no body anywhere else, so a large one changes nothing there. On a
 * member's own operation, named with the "member" parameter, a caller with no member signed in is passed
 * on unread, so the controller answers 401 first, as the other two implementations do.
 */
final class LimitRequestBody
{
    public const MAX_BYTES = 64 * 1024;

    public function __construct(private readonly MemberIdentityResolver $resolver) {}

    public function handle(Request $request, Closure $next, string $caller = 'anyone'): Response
    {
        if ($caller === 'member' && $this->resolver->subject($request) === null) {
            return $next($request);
        }

        $declared = $request->headers->get('Content-Length');

        if (($declared !== null && (int) $declared > self::MAX_BYTES) || strlen($request->getContent()) > self::MAX_BYTES) {
            return response('', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        return $next($request);
    }
}
