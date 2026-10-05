<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Parsing and formatting for Ed25519 public keys.
 *
 * Stored and compared as 32 raw bytes, and base64 only ever appears at the API boundary, because 32 bytes
 * against 44 characters of base64 text keeps the index smaller and more of it in memory at scale (record
 * 29 in docs/adr).
 */
final class PublicKeys
{
    /** Ed25519 public keys are exactly 32 bytes (RFC 8032). */
    public const LENGTH = 32;

    /**
     * Takes mixed rather than ?string deliberately. The only callers hand this straight through from
     * request JSON, where public_key can be any JSON type at all, such as a number, an array or an
     * object, and a typed parameter turns that into a TypeError and a 500 rather than the "not a
     * well-formed key" answer this method exists to give. So the type is checked here, at the boundary,
     * along with everything else about the key.
     *
     * @return string|null the raw 32 bytes, or null when the input is not a well-formed key
     */
    public static function parse(mixed $base64): ?string
    {
        if (! is_string($base64)) {
            return null;
        }

        // Strict rejects characters outside the alphabet; it still skips whitespace and tolerates
        // missing padding, so the length check alone is not enough.
        $decoded = base64_decode($base64, true);

        if ($decoded === false || strlen($decoded) !== self::LENGTH) {
            return null;
        }

        // Canonical form only: re-encoding has to reproduce the input exactly. base64_decode skips
        // whitespace and accepts unpadded input, both of which another stack's decoder rejects, so
        // without this the three would disagree on which strings are keys. Requiring a round-trip pins
        // all three to the single padded, standard-alphabet encoding the system itself emits.
        if (base64_encode($decoded) !== $base64) {
            return null;
        }

        // The all-zero key is not a valid Ed25519 point, and is the likeliest artefact of a client bug
        // that "successfully" produced an empty key. Beyond this, no point validation is done. Any other
        // 32-byte string is syntactically well-formed, and Astrana Trusted Attestation never verifies
        // signatures itself, the verifying Astrana instance does. A key that is on record but
        // cryptographically useless harms only the member who registered it.
        if ($decoded === str_repeat("\0", self::LENGTH)) {
            return null;
        }

        return $decoded;
    }

    public static function toBase64(string $key): string
    {
        return base64_encode($key);
    }
}
