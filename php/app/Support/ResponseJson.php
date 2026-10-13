<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * How every JSON answer is written, the API's and the manifest's, so that slashes, characters outside ASCII
 * and control characters come out the way the .NET and Java implementations write them.
 *
 * Those two write a slash and any character outside ASCII as it is, and escape a control character with
 * upper-case hex digits. PHP's json_encode would write "R&D \/ Lab", write "é" as é and write the
 * control character U+001F as \u001f, so its flags keep slashes and Unicode as they are, and the hex
 * digits of the escapes that remain are raised to upper case.
 */
final class ResponseJson
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public static function response(mixed $data): JsonResponse
    {
        return JsonResponse::fromJsonString(self::encode($data));
    }

    public static function encode(mixed $data): string
    {
        // Every backslash in the output starts an escape, a \u with four hex digits or a backslash and one
        // character. Matching them from the left in pairs keeps an escaped backslash from being read as
        // the start of a \u escape in the text after it.
        return (string) preg_replace_callback(
            '/\\\\(?:u[0-9a-f]{4}|.)/',
            static fn (array $escape): string => str_starts_with($escape[0], '\\u')
                ? '\\u'.strtoupper(substr($escape[0], 2))
                : $escape[0],
            json_encode($data, self::FLAGS),
        );
    }
}
