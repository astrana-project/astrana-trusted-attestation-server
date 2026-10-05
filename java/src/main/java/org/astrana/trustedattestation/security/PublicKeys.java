package org.astrana.trustedattestation.security;

import java.util.Base64;
import java.util.Optional;

/**
 * Parsing and formatting for Ed25519 public keys.
 *
 * <p>Stored and compared as 32 raw bytes. Base64 only ever appears at the API boundary, because the 32
 * bytes take less index space than the 44 characters of base64 text and keep more of the index in memory
 * at scale.
 */
public final class PublicKeys {

    /** Ed25519 public keys are exactly 32 bytes (RFC 8032). */
    public static final int LENGTH = 32;

    private PublicKeys() {}

    public static Optional<byte[]> parse(String base64) {
        if (base64 == null || base64.isBlank()) {
            return Optional.empty();
        }

        byte[] decoded;
        try {
            decoded = Base64.getDecoder().decode(base64);
        } catch (IllegalArgumentException _) {
            return Optional.empty();
        }

        if (decoded.length != LENGTH) {
            return Optional.empty();
        }

        // Canonical form only: re-encoding has to reproduce the input exactly. The decoder accepts
        // unpadded input and non-canonical trailing bits that another stack's decoder rejects, so
        // without this the three would disagree on which strings are keys. Requiring a round-trip pins
        // all three to the single padded, standard-alphabet encoding the system itself emits.
        if (!Base64.getEncoder().encodeToString(decoded).equals(base64)) {
            return Optional.empty();
        }

        // The all-zero key is not a valid Ed25519 point, and is the likeliest artefact of a client bug
        // that "successfully" produced an empty key. Beyond this, no point validation is done. Any other
        // 32-byte string is syntactically well-formed, and Astrana Trusted Attestation never verifies
        // signatures itself, the verifying Astrana instance does that. A key that is on record but
        // cryptographically useless harms only the member who registered it.
        if (isAllZero(decoded)) {
            return Optional.empty();
        }

        return Optional.of(decoded);
    }

    /**
     * Whether a submitted key clears the key rather than registering one: an empty string, or one made
     * only of spaces, tabs, carriage returns and line feeds. Any other whitespace, a non-breaking space or
     * a form feed included, is a malformed key. All three implementations use exactly this test, which is
     * why it is not {@link String#isBlank()}, whose wider idea of whitespace includes the form feed.
     */
    public static boolean isClearing(String value) {
        return value.chars().allMatch(c -> c == ' ' || c == '\t' || c == '\r' || c == '\n');
    }

    public static String toBase64(byte[] key) {
        return Base64.getEncoder().encodeToString(key);
    }

    private static boolean isAllZero(byte[] key) {
        for (byte b : key) {
            if (b != 0) {
                return false;
            }
        }

        return true;
    }
}
