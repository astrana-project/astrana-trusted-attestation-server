using Astrana.TrustedAttestation.Server.Services;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// When the next audit prune is due.
///
/// The Java and PHP implementations both have tests for their scheduling; this one did not, and the
/// calculation was private inside a <c>BackgroundService</c> where nothing could reach it. That is the
/// shape of code that quietly stops running: a wrong answer here does not fail, it simply means the
/// prune happens at the wrong time, or twice, or never, and the only symptom is an audit table that
/// grows when it should not.
///
/// Retention length is what scales how much history a breach exposes, so "never pruned" is not a tidiness
/// problem.
/// </summary>
public class PruneScheduleTests
{
    private static DateTimeOffset At(int hour, int minute, int day = 15) =>
        new(2026, 8, day, hour, minute, 0, TimeSpan.Zero);

    private static TimeSpan Until(DateTimeOffset now, TimeOnly runAt) => PruneSchedule.Until(now, runAt, TimeZoneInfo.Utc);

    [Fact]
    public void A_run_time_still_ahead_today_is_waited_for_today()
    {
        var delay = Until(At(09, 00), new TimeOnly(03, 30).AddHours(9));

        Assert.Equal(TimeSpan.FromHours(3.5), delay);
    }

    [Fact]
    public void A_run_time_already_past_waits_until_tomorrow()
    {
        // 03:30 has been and gone at 09:00, so the next one is eighteen and a half hours away. Getting
        // this backwards would run the prune immediately on every startup.
        var delay = Until(At(09, 00), new TimeOnly(03, 30));

        Assert.Equal(TimeSpan.FromHours(18.5), delay);
    }

    [Fact]
    public void The_exact_run_time_waits_a_full_day_rather_than_no_time_at_all()
    {
        // The case that turns a scheduler into a busy loop. A zero delay returns immediately, prunes,
        // and comes straight back to a still-zero delay -- hammering the database for as long as the
        // clock stays on that minute.
        var delay = Until(At(03, 30), new TimeOnly(03, 30));

        Assert.Equal(TimeSpan.FromDays(1), delay);
    }

    [Fact]
    public void A_run_time_one_minute_ahead_is_one_minute_away()
    {
        Assert.Equal(TimeSpan.FromMinutes(1), Until(At(03, 29), new TimeOnly(03, 30)));
    }

    [Fact]
    public void A_run_time_one_minute_past_is_nearly_a_day_away()
    {
        Assert.Equal(TimeSpan.FromMinutes(1439), Until(At(03, 31), new TimeOnly(03, 30)));
    }

    [Fact]
    public void Crossing_midnight_is_handled_by_the_date_rather_than_by_arithmetic()
    {
        // 23:50 now, due at 00:10: ten minutes away, on tomorrow's date. A subtraction that stayed
        // within the day would produce a negative delay here, and Task.Delay rejects those.
        var delay = Until(At(23, 50), new TimeOnly(00, 10));

        Assert.Equal(TimeSpan.FromMinutes(20), delay);
    }

    [Fact]
    public void The_delay_is_never_negative_and_never_zero()
    {
        // Swept across a whole day a minute at a time, against a run time in the middle of it. Both
        // failures are silent: a negative delay throws inside the background service and stops the
        // schedule for the lifetime of the process, and a zero delay spins.
        var runAt = new TimeOnly(03, 30);

        for (var minutes = 0; minutes < 24 * 60; minutes++)
        {
            var now = At(00, 00).AddMinutes(minutes);
            var delay = Until(now, runAt);

            Assert.True(delay > TimeSpan.Zero, $"delay was {delay} at {now:HH:mm}");
            Assert.True(delay <= TimeSpan.FromDays(1), $"delay was {delay} at {now:HH:mm}");
        }
    }

    [Fact]
    public void The_run_time_is_a_wall_clock_time_in_the_hosts_zone()
    {
        // 03:30 in a zone nine hours ahead of UTC is 18:30 UTC. Anchoring the calculation to UTC instead
        // would run the prune at the wrong local hour -- the one thing the setting exists to control.
        var tokyo = TimeZoneInfo.CreateCustomTimeZone("Tokyo", TimeSpan.FromHours(9), "Tokyo", "Tokyo");
        var now = new DateTimeOffset(2026, 8, 15, 0, 0, 0, TimeSpan.Zero);

        Assert.Equal(TimeSpan.FromHours(18.5), PruneSchedule.Until(now, new TimeOnly(03, 30), tokyo));
    }

    // -- Clock changes ------------------------------------------------------------------------------

    /// <summary>
    /// A zone with Central European rules, built here rather than looked up, so the test does not depend on
    /// the host's time zone database: UTC+1, and UTC+2 from the last Sunday of March at 02:00 to the last
    /// Sunday of October at 03:00. In 2026 those are 29 March and 25 October.
    /// </summary>
    private static readonly TimeZoneInfo CentralEurope = TimeZoneInfo.CreateCustomTimeZone(
        "Central Europe (test)", TimeSpan.FromHours(1), "Central Europe (test)", "CET", "CEST",
        [
            TimeZoneInfo.AdjustmentRule.CreateAdjustmentRule(
                DateTime.MinValue.Date, DateTime.MaxValue.Date, TimeSpan.FromHours(1),
                TimeZoneInfo.TransitionTime.CreateFloatingDateRule(new DateTime(1, 1, 1, 2, 0, 0), 3, 5, DayOfWeek.Sunday),
                TimeZoneInfo.TransitionTime.CreateFloatingDateRule(new DateTime(1, 1, 1, 3, 0, 0), 10, 5, DayOfWeek.Sunday)),
        ]);

    private static DateTimeOffset NextRun(DateTimeOffset now, TimeOnly runAt) =>
        now + PruneSchedule.Until(now, runAt, CentralEurope);

    [Fact]
    public void An_autumn_clock_change_runs_the_prune_once_not_twice()
    {
        // 02:30 happens twice on 25 October 2026: once at UTC+2 (00:30Z), and again at UTC+1 (01:30Z).
        // The run is due once that day, and the run after it is on the 26th. Carrying the offset the clock
        // showed before the change would instead schedule 02:30 at UTC+2 again, which is 01:30 local on
        // the 26th, an hour early, and a run time read with the offset of the moment would come due twice.
        var runAt = new TimeOnly(02, 30);
        var dayBefore = new DateTimeOffset(2026, 10, 24, 12, 0, 0, TimeSpan.FromHours(2));

        var first = NextRun(dayBefore, runAt);
        var second = NextRun(first.AddSeconds(1), runAt);

        var firstLocal = TimeZoneInfo.ConvertTime(first, CentralEurope);
        var secondLocal = TimeZoneInfo.ConvertTime(second, CentralEurope);
        Assert.Equal(new DateTime(2026, 10, 25), firstLocal.Date);
        Assert.Equal(new TimeOnly(02, 30), TimeOnly.FromDateTime(firstLocal.DateTime));
        Assert.Equal(new DateTime(2026, 10, 26), secondLocal.Date);
        Assert.Equal(new TimeOnly(02, 30), TimeOnly.FromDateTime(secondLocal.DateTime));
        Assert.Equal(TimeSpan.FromHours(1), secondLocal.Offset);
    }

    [Fact]
    public void A_spring_clock_change_skips_no_day()
    {
        // 02:30 does not exist on 29 March 2026. The run still happens that day, at the moment the clocks
        // jump to, and the next one is on the 30th at 02:30 local.
        var runAt = new TimeOnly(02, 30);
        var dayBefore = new DateTimeOffset(2026, 3, 28, 12, 0, 0, TimeSpan.FromHours(1));

        var first = NextRun(dayBefore, runAt);
        var second = NextRun(first.AddSeconds(1), runAt);

        var firstLocal = TimeZoneInfo.ConvertTime(first, CentralEurope);
        var secondLocal = TimeZoneInfo.ConvertTime(second, CentralEurope);
        Assert.Equal(new DateTime(2026, 3, 29), firstLocal.Date);
        Assert.Equal(new DateTime(2026, 3, 30), secondLocal.Date);
        Assert.Equal(new TimeOnly(02, 30), TimeOnly.FromDateTime(secondLocal.DateTime));
        Assert.True(PruneSchedule.Until(first.AddSeconds(1), runAt, CentralEurope) > TimeSpan.Zero);
    }

    [Fact]
    public void Across_a_clock_change_the_run_stays_at_the_configured_local_hour()
    {
        // The day after the autumn change, a 03:00 run is at 03:00 CET (02:00Z), not at 03:00 CEST (01:00Z).
        var runAt = new TimeOnly(03, 00);
        var beforeTheChange = new DateTimeOffset(2026, 10, 25, 1, 0, 0, TimeSpan.FromHours(2)); // 01:00 CEST

        var next = NextRun(beforeTheChange, runAt);

        Assert.Equal(new DateTimeOffset(2026, 10, 25, 2, 0, 0, TimeSpan.Zero), next);
    }
}
