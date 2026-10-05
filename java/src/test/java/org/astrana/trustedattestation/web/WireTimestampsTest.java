package org.astrana.trustedattestation.web;

import static org.assertj.core.api.Assertions.assertThat;

import java.time.Instant;
import org.junit.jupiter.api.Test;

/**
 * The one spelling of a timestamp on the API and the page: ISO 8601, UTC with a trailing Z, no fraction
 * when it is zero, and otherwise only the digits that carry a value. The other two implementations write
 * the same string, so a verifying instance sees one shape whichever serves it.
 */
class WireTimestampsTest {

    @Test
    void aWholeSecondCarriesNoFraction() {
        assertThat(WireTimestamps.format(Instant.parse("2026-01-01T12:00:00Z"))).isEqualTo("2026-01-01T12:00:00Z");
    }

    @Test
    void aFractionIsTrimmedOfTrailingZeros() {
        // Java's own ISO_INSTANT would write .500Z and .000100Z here.
        assertThat(WireTimestamps.format(Instant.parse("2026-01-01T12:00:00.500Z")))
                .isEqualTo("2026-01-01T12:00:00.5Z");
        assertThat(WireTimestamps.format(Instant.parse("2026-01-01T12:00:00.000100Z")))
                .isEqualTo("2026-01-01T12:00:00.0001Z");
        assertThat(WireTimestamps.format(Instant.parse("2026-01-01T12:00:00.123456789Z")))
                .isEqualTo("2026-01-01T12:00:00.123456789Z");
    }

    @Test
    void nullStaysNull() {
        assertThat(WireTimestamps.format(null)).isNull();
    }
}
