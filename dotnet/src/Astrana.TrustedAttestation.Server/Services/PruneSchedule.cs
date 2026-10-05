namespace Astrana.TrustedAttestation.Server.Services;

/// <summary>
/// When the next audit prune is due.
///
/// Separated from <see cref="AuditPruneService"/> so it can be tested. It was a private method inside a
/// <see cref="BackgroundService"/>, which is the shape of code that quietly stops working: a wrong
/// answer here does not fail anything, it means the prune runs at the wrong time, or twice, or never,
/// and the only symptom is an audit table that grows when it should not. The Java and PHP
/// implementations both had tests for their scheduling; this one had none.
/// </summary>
internal static class PruneSchedule
{
    /// <summary>
    /// How long to wait before the next run at <paramref name="runAt"/>, a wall-clock time in
    /// <paramref name="zone"/>.
    ///
    /// Always strictly positive. Landing exactly on the run time waits a full day rather than returning
    /// zero: a zero delay returns immediately, prunes, and comes straight back to a still-zero delay,
    /// hammering the database for as long as the clock stays on that minute.
    ///
    /// The run time is resolved in the host's time zone through its rules, not through the offset the
    /// clock happens to show now. A fixed offset carried across a clock change lands an hour off, and a
    /// run time inside the hour an autumn change repeats would come due twice. Here each calendar day has
    /// one run: a wall time the autumn change repeats is taken at its second occurrence, and one the spring
    /// change skips is taken at the moment the clocks jump to.
    /// </summary>
    public static TimeSpan Until(DateTimeOffset now, TimeOnly runAt, TimeZoneInfo zone)
    {
        var local = TimeZoneInfo.ConvertTime(now, zone);

        var today = InstantOf(local.Date, runAt, zone);
        var next = today > now ? today : InstantOf(local.Date.AddDays(1), runAt, zone);

        return next - now;
    }

    /// <summary>
    /// The instant a wall-clock time falls on in a zone. For a time the zone's rules make ambiguous or
    /// skip, the standard offset applies, which is the later of the two occurrences and the first valid
    /// moment after the gap respectively.
    /// </summary>
    private static DateTimeOffset InstantOf(DateTime date, TimeOnly runAt, TimeZoneInfo zone)
    {
        var wall = DateTime.SpecifyKind(date.Add(runAt.ToTimeSpan()), DateTimeKind.Unspecified);

        return new DateTimeOffset(wall, zone.GetUtcOffset(wall));
    }
}
