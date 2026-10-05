using Astrana.TrustedAttestation.Server.Contract;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// One spelling of an instant for the API and the page: ISO 8601 in UTC with a trailing Z, no fraction
/// when it is zero, and otherwise the fraction with its trailing zeros removed. Pinned here because the
/// three implementations have to produce the same string from the same row, and each framework's default
/// is different.
/// </summary>
public class WireTimestampTests
{
    private static readonly DateTime Instant = new(2027, 3, 1, 9, 30, 5, DateTimeKind.Utc);

    [Fact]
    public void A_whole_second_carries_no_fraction()
    {
        Assert.Equal("2027-03-01T09:30:05Z", WireTimestamp.Format(Instant));
    }

    [Theory]
    [InlineData(1_200_000, "2027-03-01T09:30:05.12Z")]
    [InlineData(1_230_000, "2027-03-01T09:30:05.123Z")]
    [InlineData(1, "2027-03-01T09:30:05.0000001Z")]
    public void A_fraction_is_trimmed_of_trailing_zeros(long ticks, string expected)
    {
        // Ticks are hundred-nanosecond units: 1,200,000 ticks is 120 milliseconds, written as .12.
        Assert.Equal(expected, WireTimestamp.Format(Instant.AddTicks(ticks)));
    }

    [Fact]
    public void An_unspecified_kind_is_read_as_utc_not_shifted()
    {
        // What SQL Server and MySQL hand back. The stored value is UTC, so the text is the same.
        var unspecified = DateTime.SpecifyKind(Instant, DateTimeKind.Unspecified);

        Assert.Equal("2027-03-01T09:30:05Z", WireTimestamp.Format(unspecified));
    }

    [Fact]
    public void A_local_kind_is_converted_to_utc()
    {
        var local = Instant.ToLocalTime();

        Assert.Equal("2027-03-01T09:30:05Z", WireTimestamp.Format(local));
    }

    [Fact]
    public void No_instant_is_no_text()
    {
        Assert.Null(WireTimestamp.Format((DateTime?)null));
    }
}
