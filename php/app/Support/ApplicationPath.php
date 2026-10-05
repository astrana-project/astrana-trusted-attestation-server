<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Resolves a path from the settings file: an absolute path is used as it is, and anything else is read from
 * the application folder.
 *
 * Absolute covers Windows as well as Linux, because the application runs under IIS on Windows hosting:
 * a path that starts with a slash or a backslash, which includes a network share, or with a drive letter
 * and a separator, such as C:\keys\sp.key.
 */
final class ApplicationPath
{
    public static function resolve(string $path, string $applicationRoot): string
    {
        if (self::isAbsolute($path)) {
            return $path;
        }

        return rtrim($applicationRoot, '/\\').DIRECTORY_SEPARATOR.ltrim($path, '/\\');
    }

    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }
}
