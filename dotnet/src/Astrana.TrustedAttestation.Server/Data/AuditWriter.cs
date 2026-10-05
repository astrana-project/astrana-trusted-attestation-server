using Microsoft.EntityFrameworkCore;

namespace Astrana.TrustedAttestation.Server.Data;

/// <summary>
/// The seven events the audit trail records. Nothing else is ever written to it.
/// </summary>
public static class AuditEvent
{
    // Written here, by the application, for what a member does to their own record.
    public const string KeyRegistered = "key_registered";
    public const string KeyCleared = "key_cleared";
    public const string KeyRemoved = "key_removed";

    // Written by the stored procedures, not by the application. Named here so that the vocabulary lives
    // in one place and a reader can see the whole trail without opening the schema.
    public const string RelationshipGrantedByOrg = "relationship_granted_by_org";
    public const string RelationshipRevokedByOrg = "relationship_revoked_by_org";
    public const string RelationshipExtendedByOrg = "relationship_extended_by_org";
    public const string RelationshipSelfRevoked = "relationship_self_revoked";
}

/// <summary>
/// Writes the three self-service audit events.
///
/// Raw parameterised SQL rather than an EF entity, for two reasons. The application's database principal
/// holds <c>INSERT</c> only on <c>audit_log</c>, and EF's insert path reads the generated key back --
/// on PostgreSQL a <c>RETURNING</c> clause needing <c>SELECT</c> privilege the principal will not have.
/// And the requirement that the audit row is written in the same transaction as the change it logs is
/// easier to see is true when the insert is right here, not behind a change tracker.
///
/// Never records the public key itself, and never records a verification -- checking a key is not one of
/// the events, and logging checks would tell the organisation who is asking.
/// </summary>
public interface IAuditWriter
{
    Task WriteAsync(string eventType, string iamSubjectId, string relationshipType, CancellationToken cancellationToken);
}

public sealed class AuditWriter(TrustedAttestationDbContext db, TimeProvider timeProvider) : IAuditWriter
{
    /// <summary>
    /// Enlists in whatever transaction the caller has already opened on
    /// <see cref="TrustedAttestationDbContext"/>, so the audit row and the change it records commit or
    /// roll back together.
    /// </summary>
    /// <param name="relationshipType">
    /// Which of the member's relationships the event concerns. A member can hold several, so an entry
    /// naming only the subject would not say what actually happened.
    /// </param>
    public async Task WriteAsync(
        string eventType,
        string iamSubjectId,
        string relationshipType,
        CancellationToken cancellationToken)
    {
        var occurredAt = timeProvider.GetUtcNow().UtcDateTime;

        await db.Database.ExecuteSqlAsync(
            $"INSERT INTO audit_log (event_type, iam_subject_id, relationship_type, actor, occurred_at) VALUES ({eventType}, {iamSubjectId}, {relationshipType}, {null as string}, {occurredAt})",
            cancellationToken);
    }
}
