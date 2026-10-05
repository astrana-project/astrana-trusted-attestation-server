using Astrana.TrustedAttestation.Server.Data;
using Astrana.TrustedAttestation.Server.Services;

namespace Astrana.TrustedAttestation.Server.Tests.Support;

/// <summary>
/// The hand-written doubles the write path is tested over -- no database, no mocking framework.
///
/// Shared by <see cref="RelationshipServiceTests"/>, which pins the service's reasoning directly, and by
/// the endpoint tests, which build a real <see cref="RelationshipService"/> over these same doubles and
/// check that each outcome is turned into the right HTTP result. One source of truth for the fakes means
/// the endpoint tests exercise the exact reasoning the service tests do, rather than a re-implementation
/// of it that could drift.
/// </summary>
internal sealed class ImmediateTransactionRunner : ITransactionRunner
{
    /// <summary>Runs the operation directly -- commit/rollback is the store's concern in these tests.</summary>
    public Task<T> RunAsync<T>(Func<CancellationToken, Task<T>> operation, CancellationToken cancellationToken) =>
        operation(cancellationToken);
}

internal sealed class RecordingAuditWriter : IAuditWriter
{
    public List<string> Events { get; } = [];

    /// <summary>The relationship type each entry named, so a caller can be shown to pass the row's own.</summary>
    public List<string> RelationshipTypes { get; } = [];

    public Task WriteAsync(string eventType, string subject, string relationshipType, CancellationToken cancellationToken)
    {
        Events.Add(eventType);
        RelationshipTypes.Add(relationshipType);
        return Task.CompletedTask;
    }
}

internal sealed class RecordingSelfRevoke : ISelfRevokeCommand
{
    public int Invocations { get; private set; }

    public string? RelationshipType { get; private set; }

    public Task InvokeAsync(string subject, string relationshipType, CancellationToken cancellationToken)
    {
        Invocations++;
        RelationshipType = relationshipType;
        return Task.CompletedTask;
    }
}

internal sealed class FakeRelationshipStore : IRelationshipStore
{
    public MemberRelationship? Own { get; set; }
    public MemberRelationship? ByPublicKey { get; set; }
    public bool KeyHeldElsewhere { get; set; }
    public bool SaveLosesTheRace { get; set; }
    public bool SaveFindsTheRowGone { get; set; }
    public IReadOnlyList<MemberRelationship> Held { get; set; } = [];
    public bool Removed { get; private set; }
    public bool Saved { get; private set; }

    /// <summary>The subject the last list was keyed on -- so a caller can be shown to use the resolved one.</summary>
    public string? ListedSubject { get; private set; }

    /// <summary>How often the own-relationship lookup ran, so a refusal can be shown to happen before it.</summary>
    public int Lookups { get; private set; }

    public Task<IReadOnlyList<MemberRelationship>> ListHeldByAsync(string subject, CancellationToken ct)
    {
        ListedSubject = subject;
        return Task.FromResult(Held);
    }

    public Task<MemberRelationship?> FindOwnAsync(string subject, string type, CancellationToken ct)
    {
        Lookups++;
        return Task.FromResult(Own);
    }

    public Task<MemberRelationship?> FindByPublicKeyAsync(byte[] key, CancellationToken ct) =>
        Task.FromResult(ByPublicKey);

    public Task<bool> IsKeyHeldElsewhereAsync(byte[] key, long exceptId, CancellationToken ct) =>
        Task.FromResult(KeyHeldElsewhere);

    public void Remove(MemberRelationship row) => Removed = true;

    public Task SaveAsync(CancellationToken ct)
    {
        if (SaveLosesTheRace)
        {
            throw new KeyConflictException();
        }

        if (SaveFindsTheRowGone)
        {
            throw new RelationshipVanishedException();
        }

        Saved = true;
        return Task.CompletedTask;
    }
}

/// <summary>A clock frozen at a chosen instant, so status derivation and expiry are deterministic.</summary>
internal sealed class FixedTimeProvider(DateTimeOffset now) : TimeProvider
{
    public override DateTimeOffset GetUtcNow() => now;
}
