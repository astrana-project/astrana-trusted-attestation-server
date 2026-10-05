package org.astrana.trustedattestation.web;

import java.time.Instant;
import java.time.ZoneOffset;
import java.time.format.DateTimeFormatter;
import java.time.format.DateTimeFormatterBuilder;
import java.time.temporal.ChronoField;

/**
 * How a timestamp is written on the API and on the page: ISO 8601 in UTC with a trailing {@code Z}, the
 * fraction omitted when it is zero and otherwise trimmed of trailing zeros. {@code 2026-01-01T12:00:00Z},
 * {@code 2026-01-01T12:00:00.5Z}, never {@code 2026-01-01T12:00:00.500Z}.
 *
 * <p>The three implementations serialise {@code expires_at} identically, so a verifying instance reading
 * the API, or a member reading the page, sees the same string whichever implementation serves it. Java's
 * own {@link DateTimeFormatter#ISO_INSTANT} writes the fraction in groups of three digits, which the other
 * two do not, so the formatter is spelt out here.
 */
final class WireTimestamps {

    private static final DateTimeFormatter ISO_UTC_TRIMMED = new DateTimeFormatterBuilder()
            .appendPattern("uuuu-MM-dd'T'HH:mm:ss")
            .appendFraction(ChronoField.NANO_OF_SECOND, 0, 9, true)
            .appendLiteral('Z')
            .toFormatter()
            .withZone(ZoneOffset.UTC);

    private WireTimestamps() {}

    /** The wire form of an instant, or null for null, which both callers keep as an explicit null. */
    static String format(Instant instant) {
        return instant == null ? null : ISO_UTC_TRIMMED.format(instant);
    }
}
