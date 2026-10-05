package org.astrana.trustedattestation.security;

import static org.assertj.core.api.Assertions.assertThat;

import java.util.Base64;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.NullAndEmptySource;
import org.junit.jupiter.params.provider.ValueSource;

class PublicKeysTest {

    private static byte[] bytes(int length) {
        byte[] value = new byte[length];
        for (int i = 0; i < length; i++) {
            value[i] = (byte) (i + 1);
        }

        return value;
    }

    @Test
    void acceptsA32ByteKey() {
        byte[] key = bytes(32);

        assertThat(PublicKeys.parse(Base64.getEncoder().encodeToString(key)))
                .hasValueSatisfying(parsed -> assertThat(parsed).isEqualTo(key));
    }

    @ParameterizedTest
    @ValueSource(ints = {0, 16, 31, 33, 64})
    void rejectsAnyLengthBut32(int length) {
        assertThat(PublicKeys.parse(Base64.getEncoder().encodeToString(bytes(length))))
                .isEmpty();
    }

    @ParameterizedTest
    @NullAndEmptySource
    @ValueSource(strings = {"   ", "not base64 at all!!", "AAAA%%%%"})
    void rejectsAnythingThatIsNotBase64(String value) {
        assertThat(PublicKeys.parse(value)).isEmpty();
    }

    @ParameterizedTest
    @ValueSource(strings = {"", " ", "\t", "\r\n", " \t\r\n "})
    void onlySpacesTabsAndLineBreaksClearAKey(String value) {
        assertThat(PublicKeys.isClearing(value)).isTrue();
    }

    @ParameterizedTest
    @ValueSource(strings = {" ", "\f", "\u000b", " \f ", " ", "AAAA"})
    void anyOtherCharacterDoesNotClearAKey(String value) {
        // String.isBlank would call the form feed, the vertical tab and the em space blank. The rule the
        // three implementations share does not.
        assertThat(PublicKeys.isClearing(value)).isFalse();
    }

    @Test
    void rejectsTheAllZeroKey() {
        // Not a valid Ed25519 point, and the likeliest artefact of a client that "successfully" produced
        // an empty key.
        assertThat(PublicKeys.parse(Base64.getEncoder().encodeToString(new byte[32])))
                .isEmpty();
    }

    @Test
    void roundTripsThroughBase64() {
        byte[] key = bytes(32);

        assertThat(PublicKeys.parse(PublicKeys.toBase64(key)))
                .hasValueSatisfying(parsed -> assertThat(parsed).isEqualTo(key));
    }

    @Test
    void onlyTheCanonicalEncodingOfAKeyIsAccepted() {
        // One key, several encodings a decoder might tolerate. Only the padded, standard-alphabet form
        // the system emits is a key here -- the same rule .NET and PHP enforce, so a key accepted by one
        // is accepted by all three. The decoder would otherwise accept the unpadded form, letting a key
        // in that the other two refuse.
        String canonical = "+/A/ECAwQFBgcICQoLDA0ODwAQIDBAUGBwgJCgsMDf8=";
        assertThat(PublicKeys.parse(canonical)).isPresent();

        assertThat(PublicKeys.parse(canonical.substring(0, canonical.length() - 1)))
                .isEmpty();
        assertThat(PublicKeys.parse(canonical.replace('+', '-').replace('/', '_')))
                .isEmpty();
        assertThat(PublicKeys.parse(canonical.substring(0, 4) + " " + canonical.substring(4)))
                .isEmpty();
        assertThat(PublicKeys.parse(" " + canonical)).isEmpty();
    }
}
