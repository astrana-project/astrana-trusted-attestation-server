<?php

declare(strict_types=1);

namespace App\Support;

use JsonException;

/**
 * Reads public_key from a request body, the one field the key endpoint and attestation take.
 *
 * The body is read as JSON whatever its Content-Type says. It is malformed, so read as carrying no key, when
 * it is not one JSON value in valid UTF-8 and nothing else, or when anywhere in it arrays and objects are
 * nested more than MAX_DEPTH deep, a number has more than MAX_NUMBER_DIGITS digits, or a string or a name
 * holds an escaped lone surrogate. The contract sets each of those, so the same body is a 400 on the key
 * endpoint and {"valid":false} on attestation whichever implementation receives it. PHP's decoder refuses
 * all of them but the long number, which is looked for in the text.
 */
final class PublicKeyBody
{
    /** The most arrays and objects a body may have open at once, its own object included. */
    public const MAX_DEPTH = 64;

    /** The most digits a number in a body may have, those of its fraction and exponent included. */
    public const MAX_NUMBER_DIGITS = 1000;

    /** A JSON string, escapes included. */
    private const STRING = '/"(?:[^"\\\\]++|\\\\.)*+"/s';

    /** A run of the characters a JSON number is written in. */
    private const NUMBER = '/[-+.eE0-9]+/';

    /**
     * The body's public_key as it was sent, which may be any JSON value, or null when the body is malformed
     * or has none.
     */
    public static function publicKey(string $body): mixed
    {
        try {
            // PHP's depth counts one level more than the arrays and objects open, so 1 refuses even [].
            $document = json_decode($body, true, self::MAX_DEPTH + 1, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($document) || self::hasLongNumber($body)) {
            return null;
        }

        return $document['public_key'] ?? null;
    }

    /**
     * Whether a number in the JSON text has more than MAX_NUMBER_DIGITS digits. The text has been decoded, so
     * once its strings are taken out every run of number characters left is one number. A text the strings
     * cannot be taken out of counts as having one.
     */
    private static function hasLongNumber(string $json): bool
    {
        $withoutStrings = preg_replace(self::STRING, '""', $json);
        if ($withoutStrings === null) {
            return true;
        }

        preg_match_all(self::NUMBER, $withoutStrings, $numbers);
        foreach ($numbers[0] as $number) {
            if (preg_match_all('/\d/', $number) > self::MAX_NUMBER_DIGITS) {
                return true;
            }
        }

        return false;
    }
}
