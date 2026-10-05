using Astrana.TrustedAttestation.Server.Configuration;
using Astrana.TrustedAttestation.Server.Services;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The one decision <see cref="AuditPruneService"/> makes before any clock or database is involved:
/// whether to run at all.
///
/// Pruning is on by default so the smaller orgs least likely to have a compliance team do not silently
/// keep audit history forever -- retention length is what scales how much a breach exposes. Turning it
/// off is a deliberate choice an org running its own scheduler makes, and when it is off the service must
/// do nothing and say so loudly, because a prune that silently never runs looks identical to one that is
/// working. The scheduling arithmetic is tested in <see cref="PruneScheduleTests"/>. The daily delay loop
/// and the procedure call itself run against a real database and belong to the conformance suite.
/// </summary>
public class AuditPruneServiceTests
{
    /// <summary>A scope factory that records whether the prune path ever reached for a database.</summary>
    private sealed class SpyingScopeFactory : IServiceScopeFactory
    {
        public bool Created { get; private set; }

        public IServiceScope CreateScope()
        {
            Created = true;
            throw new InvalidOperationException("The disabled prune must not open a scope or touch the database.");
        }
    }

    private sealed class RecordingLogger : ILogger<AuditPruneService>
    {
        public List<LogLevel> Levels { get; } = [];

        public IDisposable BeginScope<TState>(TState state) where TState : notnull => NullScope.Instance;

        public bool IsEnabled(LogLevel logLevel) => true;

        public void Log<TState>(LogLevel logLevel, EventId eventId, TState state, Exception? exception,
            Func<TState, Exception?, string> formatter) => Levels.Add(logLevel);

        private sealed class NullScope : IDisposable
        {
            public static readonly NullScope Instance = new();
            public void Dispose() { }
        }
    }

    [Fact]
    public async Task With_pruning_turned_off_the_service_never_touches_the_database_and_warns()
    {
        // An org running its own scheduler turns this off so the two do not both run. The service then has
        // to be inert -- opening a scope would have it prune anyway -- and it warns rather than falling
        // silent, because "audit rows accumulate forever" is the failure that otherwise leaves no trace.
        var options = new TrustedAttestationOptions();
        options.Audit.PruneEnabled = false;

        var scopeFactory = new SpyingScopeFactory();
        var logger = new RecordingLogger();
        var service = new AuditPruneService(scopeFactory, Options.Create(options), TimeProvider.System, logger);

        await service.StartAsync(default);

        // Wait on the execution itself rather than on StartAsync's return: the disabled path finishes
        // without ever awaiting, so ExecuteTask is already complete here, and awaiting it makes what it
        // did (or did not do) visible on this thread deterministically. An enabled service would instead
        // still be parked in its delay loop, which is what the conformance suite drives against a database.
        await service.ExecuteTask!;
        await service.StopAsync(default);

        Assert.False(scopeFactory.Created);
        Assert.Contains(LogLevel.Warning, logger.Levels);
    }
}
