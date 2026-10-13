<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ConfigurationException;

/**
 * The release this server is running, which the self-service page shows in its footer and no other page does
 * (decision record 46 in docs/adr).
 *
 * Read from the top heading of CHANGELOG.md, the version the release is tagged and published under, so the version is
 * written in one place. The changelog ships with the application, in the image and in every copy of the php folder.
 */
final class RunningRelease
{
    /** Where the version links to. Fixed, so that no operator can point members somewhere else. */
    public const RELEASES_URL = 'https://www.astrana.org/releases';

    /** The full version, such as 1.0.1. */
    public readonly string $version;

    public function __construct(?string $changelogPath = null)
    {
        $changelogPath ??= base_path('CHANGELOG.md');

        $changelog = @file_get_contents($changelogPath);
        if ($changelog === false || preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', $changelog, $heading) !== 1) {
            throw new ConfigurationException(
                "\"$changelogPath\" has no version heading such as ## [1.0.0] - 2026-10-05. The server reads its "
                .'version from there, so the file has to ship with the application.'
            );
        }

        $this->version = $heading[1];
    }

    /** The version as the footer writes it, such as v1.0.1. */
    public function label(): string
    {
        return 'v'.$this->version;
    }
}
