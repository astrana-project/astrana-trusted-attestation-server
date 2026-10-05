<?php

declare(strict_types=1);

namespace App\Security;

/**
 * A post-login redirect target that cannot leave this origin.
 *
 * The only safe destination is a path on this site: a leading slash, but never "//", no backslash
 * anywhere, and no control character. A browser resolves "//" and "/\" as a protocol-relative URL and
 * follows it off-site, and some treat a backslash later in the path as a slash too, so a path with any
 * backslash is refused. A character below 0x20 or equal to 0x7F has no place in a path, and a carriage
 * return or line feed inside a Location header is how a response gets a second header it never meant to
 * send, so those are refused as well. The same rule runs on all three implementations for the language
 * switcher's return path. A crafted ?next=//evil.example therefore falls back to the local default rather
 * than redirecting away after a genuine sign-in. The other two implementations pin post-sign-in to /me
 * outright. This keeps the path feature but refuses anything that is not local, so all three land
 * somewhere on this origin.
 *
 * Pure and framework-free, because the open-redirect rule is the security-critical part, so it lives where
 * it can be exercised exhaustively rather than only through a live sign-in.
 */
final class SafeReturnPath
{
    public static function sanitize(mixed $candidate, string $fallback = '/me'): string
    {
        $path = is_string($candidate) ? $candidate : '';

        if ($path === ''
            || $path[0] !== '/'
            || str_starts_with($path, '//')
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return $fallback;
        }

        return $path;
    }
}
