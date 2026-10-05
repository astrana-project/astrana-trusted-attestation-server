<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConfigurationException;
use App\Support\ApplicationPath;

/**
 * Resolves the configured logo into the data URI the manifest carries.
 *
 * The manifest field is always an embedded data URI, so that rendering an organisation's branding needs no
 * second request to the organisation. Configuration accepts either that, or a path to an image file which
 * is read and encoded at start-up. A .env line cannot sensibly hold 9,000 characters, and an operator
 * faced with that would skip the logo.
 */
final class LogoData
{
    /** The setting the light logo is read from, named in the errors about it. */
    public const LIGHT_SETTING = 'TRUSTED_ATTESTATION_MANIFEST_LOGO';

    /** The setting the dark logo is read from, named in the errors about it. */
    public const DARK_SETTING = 'TRUSTED_ATTESTATION_MANIFEST_LOGO_DARK';

    /**
     * A base64 image data URI. A URL is refused, because a link here would put the request back on the
     * organisation's server, which is exactly what embedding the image avoids.
     */
    public const PATTERN = '#^data:image/(png|jpeg|gif|webp|svg\+xml);base64,[A-Za-z0-9+/]+={0,2}$#';

    private const MEDIA_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
    ];

    /**
     * @param  string|null  $configured  a data URI, a path, or nothing
     * @param  string  $setting  the environment variable the value came from, named in the errors
     */
    public static function resolve(?string $configured, string $setting = self::LIGHT_SETTING): ?string
    {
        if ($configured === null || trim($configured) === '') {
            return null;
        }

        $value = trim($configured);

        if (stripos($value, 'data:') === 0) {
            return $value;
        }

        $path = ApplicationPath::resolve($value, base_path());

        if (! is_file($path)) {
            throw new ConfigurationException(
                self::problem($path, 'does not exist. '.$setting.' takes either an '
                    .'image file path, relative to the application root, or a data URI.')
            );
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mediaType = self::MEDIA_TYPES[$extension] ?? null;

        if ($mediaType === null) {
            throw new ConfigurationException(
                self::problem($path, 'has an unsupported extension "'.$extension.'". '
                    .'Supported: '.implode(', ', array_keys(self::MEDIA_TYPES)).'.')
            );
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new ConfigurationException(self::problem($path, 'could not be read.'));
        }

        return 'data:'.$mediaType.';base64,'.base64_encode($contents);
    }

    /** Prefixes a complaint with the logo it is about, so every one of them names it. */
    private static function problem(string $path, string $detail): string
    {
        return 'The configured logo "'.$path.'" '.$detail;
    }
}
