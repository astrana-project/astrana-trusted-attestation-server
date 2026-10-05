<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The writing direction for a resolved locale: "rtl" or "ltr".
 *
 * Keyed on the same short, explicit set the other two implementations use (ar, he, fa, ur, ps) rather than
 * on any runtime culture data, so all three emit the same dir for the same language and a consumer cannot
 * tell the stacks apart. Only the primary language subtag decides: a region or script suffix, with either
 * separator, resolves on the language alone.
 */
final class TextDirection
{
    private const RIGHT_TO_LEFT = ['ar', 'he', 'fa', 'ur', 'ps'];

    public static function of(?string $tag): string
    {
        $language = $tag === null || $tag === ''
            ? ''
            : strtolower(explode('-', str_replace('_', '-', $tag), 2)[0]);

        return in_array($language, self::RIGHT_TO_LEFT, true) ? 'rtl' : 'ltr';
    }
}
