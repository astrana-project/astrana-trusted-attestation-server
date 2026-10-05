<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which requests the built-in web server's router (server.php) may answer with a file from public/.
 *
 * Only a file that really lives under public/ is served. The request path is resolved with realpath and
 * has to start with public/ itself, so a path that climbs out with ".." reaches nothing whatever the
 * encoding it arrived in. A path with a ".." segment or a null byte names no file at all, and neither do
 * robots.txt and favicon.ico, Laravel skeleton files the other two implementations do not serve. A segment
 * that starts with a dot is never served as a file either, so .htaccess and anything like it stay private.
 * Every one of these goes to the application, as under .htaccess and web.config, which answers 404 for
 * them and serves /.well-known/ata-manifest.json itself.
 *
 * Framework-free, so the rule is pinned without a running server, and so server.php can use it before
 * the application boots.
 */
final class StaticFileRouter
{
    /** Never served as files, whether or not a file of that name exists. */
    private const NOT_SERVED = ['robots.txt', 'favicon.ico'];

    /**
     * The content types of the files this application ships. Explicit, because fileinfo mis-detects .css
     * as text/plain, exactly the content-type confusion nosniff exists to stop.
     *
     * @var array<string, string>
     */
    private const CONTENT_TYPES = [
        'css' => 'text/css',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'txt' => 'text/plain',
        'js' => 'text/javascript',
        'png' => 'image/png',
        'woff2' => 'font/woff2',
    ];

    /** Whether the path is never served as a file, before anything is looked up. */
    private static function neverServed(string $path): bool
    {
        if (str_contains($path, "\0")) {
            return true;
        }

        $segments = self::segments($path);

        // A segment that starts with a dot covers both ".." and private files such as .htaccess.
        $dotSegments = array_filter($segments, static fn (string $segment): bool => str_starts_with($segment, '.'));

        return $dotSegments !== []
            || in_array(strtolower(end($segments) ?: ''), self::NOT_SERVED, true);
    }

    /**
     * The real path of the file under $publicRoot the request names, or null when the request names no
     * servable file and belongs to the application.
     */
    public static function file(string $publicRoot, string $path): ?string
    {
        if ($path === '/' || self::neverServed($path)) {
            return null;
        }

        $root = realpath($publicRoot);
        $candidate = realpath($publicRoot.'/'.ltrim($path, '/'));

        if ($root === false || $candidate === false || ! is_file($candidate)) {
            return null;
        }

        return str_starts_with($candidate, $root.DIRECTORY_SEPARATOR) ? $candidate : null;
    }

    /** The content type for a file this application ships, or null for an extension it does not. */
    public static function contentType(string $file): ?string
    {
        return self::CONTENT_TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;
    }

    /** @return list<string> the non-empty segments, with a backslash read as a separator too */
    private static function segments(string $path): array
    {
        $segments = explode('/', str_replace('\\', '/', $path));

        return array_values(array_filter($segments, static fn (string $segment): bool => $segment !== ''));
    }
}
