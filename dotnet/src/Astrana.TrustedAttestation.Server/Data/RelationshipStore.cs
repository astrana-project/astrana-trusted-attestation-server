using Microsoft.Data.SqlClient;
using Microsoft.EntityFrameworkCore;
using MySql.Data.MySqlClient;
using Npgsql;

namespace Astrana.TrustedAttestation.Server.Data;

/// <summary>
/// A key registration lost the race for a public key another relationship already holds.
///
/// The unique index on <c>public_key</c> is the real arbiter -- the up-front check only gives a clean
/// answer in the common, uncontended case -- so <see cref="IRelationshipStore.SaveAsync"/> translates the
/// database's constraint violation into this, which the service turns into the same 409 the check gives.
/// </summary>
public sealed class KeyConflictException : Exception;

/// <summary>
/// The relationship was removed between the lookup and the write: the organisation revoked and deleted
/// it, or the member removed it from another tab. The write touched no row, so the service answers as it
/// would have had the lookup found nothing, a 404, and the transaction rolls back, so no audit entry
/// records a change that did not happen.
/// </summary>
public sealed class RelationshipVanishedException : Exception;

/// <summary>
/// The member-relationship reads and writes the write path needs, behind an interface so the decisions
/// built on them -- which result is a 404, which a 409 -- can be exercised without a database. The Entity
/// Framework implementation is what the conformance suite drives end to end; the interface is what the
/// unit tests fake.
/// </summary>
public interface IRelationshipStore
{
    /// <summary>Every relationship the subject holds, ordered, read-only. An empty list is a real answer.</summary>
    Task<IReadOnlyList<MemberRelationship>> ListHeldByAsync(string subject, CancellationToken cancellationToken);

    /// <summary>The subject's relationship of the given type, tracked for update, or null if not granted.</summary>
    Task<MemberRelationship?> FindOwnAsync(string subject, string relationshipType, CancellationToken cancellationToken);

    /// <summary>The one relationship a public key belongs to, read-only, or null. The anonymous /attest lookup.</summary>
    Task<MemberRelationship?> FindByPublicKeyAsync(byte[] publicKey, CancellationToken cancellationToken);

    /// <summary>Whether the key already belongs to some other relationship -- this member's own included.</summary>
    Task<bool> IsKeyHeldElsewhereAsync(byte[] publicKey, long exceptId, CancellationToken cancellationToken);

    /// <summary>Marks a tracked relationship for deletion on the next <see cref="SaveAsync"/>.</summary>
    void Remove(MemberRelationship row);

    /// <summary>
    /// Persists tracked changes, translating a unique-index violation on <c>public_key</c> into
    /// <see cref="KeyConflictException"/> and a row that has gone since it was read into
    /// <see cref="RelationshipVanishedException"/>. Any other failure propagates as the fault it is.
    /// </summary>
    Task SaveAsync(CancellationToken cancellationToken);
}

public sealed class EfRelationshipStore(
    TrustedAttestationDbContext db,
    ILogger<EfRelationshipStore> logger) : IRelationshipStore
{
    public async Task<IReadOnlyList<MemberRelationship>> ListHeldByAsync(
        string subject, CancellationToken cancellationToken) =>
        await db.MemberRelationships
            .AsNoTracking()
            .Where(r => r.IamSubjectId == subject)
            .OrderBy(r => r.RelationshipType)
            .ToListAsync(cancellationToken);

    public Task<MemberRelationship?> FindOwnAsync(
        string subject, string relationshipType, CancellationToken cancellationToken) =>
        db.MemberRelationships.SingleOrDefaultAsync(
            r => r.IamSubjectId == subject && r.RelationshipType == relationshipType,
            cancellationToken);

    public Task<MemberRelationship?> FindByPublicKeyAsync(byte[] publicKey, CancellationToken cancellationToken) =>
        db.MemberRelationships
            .AsNoTracking()
            .SingleOrDefaultAsync(r => r.PublicKey == publicKey, cancellationToken);

    public Task<bool> IsKeyHeldElsewhereAsync(byte[] publicKey, long exceptId, CancellationToken cancellationToken) =>
        db.MemberRelationships.AnyAsync(r => r.PublicKey == publicKey && r.Id != exceptId, cancellationToken);

    public void Remove(MemberRelationship row) => db.MemberRelationships.Remove(row);

    public async Task SaveAsync(CancellationToken cancellationToken)
    {
        try
        {
            await db.SaveChangesAsync(cancellationToken);
        }
        catch (DbUpdateConcurrencyException)
        {
            // Entity Framework expected the update or delete to touch one row and it touched none: the row
            // was removed after the lookup read it.
            logger.LogInformation("A relationship was removed between the lookup and the write.");
            throw new RelationshipVanishedException();
        }
        catch (DbUpdateException exception) when (IsUniqueViolation(exception.InnerException))
        {
            // Logged without the exception, whose message on some engines repeats the colliding value, and
            // a key never goes to a log.
            logger.LogWarning("Rejected a key registration that collided on a unique constraint.");
            throw new KeyConflictException();
        }
    }

    /// <summary>
    /// Whether a save failed on a unique constraint, by the engine's own error code: PostgreSQL
    /// <c>23505</c>, MySQL <c>1062</c>, SQL Server <c>2601</c> and <c>2627</c>. Only that is a 409. A
    /// deadlock, a lost connection or a check constraint is a fault, and answering 409 for it would tell
    /// the member their key belongs to someone else when nothing of the kind is true.
    /// </summary>
    internal static bool IsUniqueViolation(Exception? cause) => cause switch
    {
        PostgresException { SqlState: "23505" } => true,
        MySqlException { Number: 1062 } => true,
        SqlException { Number: 2601 or 2627 } => true,
        _ => false,
    };
}
