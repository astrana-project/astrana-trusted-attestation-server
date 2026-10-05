using Astrana.TrustedAttestation.Server.Configuration;
using Astrana.TrustedAttestation.Server.Data;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Options;

namespace Astrana.TrustedAttestation.Server.Services;

/// <summary>
/// Runs <c>prune_audit_log</c> once a day.
///
/// Pruning is on by default rather than left to the host to remember. Retention being "the host's
/// decision" only protects anyone if the decision actually gets made, and the smaller orgs least likely to
/// have a compliance team are exactly the ones most likely to never prune at all -- which is backwards,
/// since retention length is what scales breach exposure.
///
/// Scheduled here rather than with SQL Server Agent, the MySQL event scheduler or pg_cron: availability
/// varies too much by engine and hosting tier to be a reliable default. <see cref="BackgroundService"/> is
/// part of the hosting framework already, runs inside the same process as the app, and works identically
/// whichever of the three databases an org picked. Nothing extra is deployed.
/// </summary>
public sealed class AuditPruneService(
    IServiceScopeFactory scopeFactory,
    IOptions<TrustedAttestationOptions> options,
    TimeProvider timeProvider,
    ILogger<AuditPruneService> logger) : BackgroundService
{
    private readonly AuditOptions _audit = options.Value.Audit;

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        if (!_audit.PruneEnabled)
        {
            logger.LogWarning(
                "Audit pruning is disabled. Audit rows will accumulate indefinitely, and retention length " +
                "directly scales how much history a database breach would expose.");
            return;
        }

        while (!stoppingToken.IsCancellationRequested)
        {
            var delay = TimeUntilNextRun();
            logger.LogInformation("Next audit prune in {Delay} (retention {RetentionDays} days).",
                delay, _audit.RetentionDays);

            try
            {
                await Task.Delay(delay, timeProvider, stoppingToken);
            }
            catch (OperationCanceledException)
            {
                return;
            }

            await PruneAsync(stoppingToken);
        }
    }

    private async Task PruneAsync(CancellationToken cancellationToken)
    {
        try
        {
            using var scope = scopeFactory.CreateScope();
            var db = scope.ServiceProvider.GetRequiredService<TrustedAttestationDbContext>();

            // The logic itself stays in the database, same as revoke and extend. Only the schedule that
            // triggers it lives in the application.
            var retentionDays = _audit.RetentionDays;

            // The three engines spell a procedure call differently; the procedure itself is identical.
            await (options.Value.Database.Provider switch
            {
                DatabaseProvider.SqlServer => db.Database.ExecuteSqlAsync(
                    $"EXEC prune_audit_log @retention_days = {retentionDays}", cancellationToken),
                _ => db.Database.ExecuteSqlAsync(
                    $"CALL prune_audit_log({retentionDays})", cancellationToken),
            });

            logger.LogInformation("Audit prune completed.");
        }
        catch (Exception exception)
        {
            // A failed prune must not take the app down: the member-facing and verification paths are
            // unaffected by it, and the next run will try again.
            logger.LogError(exception, "Audit prune failed. It will be retried at the next scheduled run.");
        }
    }

    // The run time is a wall-clock time in the host's zone, so the host's zone rules decide the instant,
    // clock changes included.
    private TimeSpan TimeUntilNextRun() =>
        PruneSchedule.Until(timeProvider.GetUtcNow(), _audit.RunAt, timeProvider.LocalTimeZone);
}
