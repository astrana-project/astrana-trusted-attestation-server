<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * How a moment is written on the API and the page: ISO 8601 in UTC with a trailing Z, the fraction of a
 * second omitted when it is zero and otherwise trimmed of trailing zeros. 2030-06-01T12:00:00Z,
 * 2030-06-01T12:00:00.5Z, 2030-06-01T12:00:00.123456Z.
 *
 * The three implementations write the same instant the same way, so a consumer comparing answers across
 * organisations on different stacks sees one format, and the page shows the same string the API returns.
 */
final class Timestamps
{
    public static function iso8601Utc(CarbonInterface $moment): string
    {
        $utc = $moment->toImmutable()->utc();
        $fraction = rtrim($utc->format('u'), '0');

        return $utc->format('Y-m-d\TH:i:s').($fraction === '' ? '' : '.'.$fraction).'Z';
    }
}
