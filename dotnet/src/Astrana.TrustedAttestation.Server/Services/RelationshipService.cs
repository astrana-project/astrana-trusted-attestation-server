using Astrana.TrustedAttestation.Server.Contract;
using Astrana.TrustedAttestation.Server.Data;

namespace Astrana.TrustedAttestation.Server.Services;

/// <summary>What became of a key registration.</summary>
public enum SetKeyStatus
{
    /// <summary>The relationship was never granted, so there is nothing to key. A 404, never a create.</summary>
    NotGranted,

    /// <summary>The key already belongs to another relationship. A 409.</summary>
    Conflict,

    /// <summary>The key was stored. A 200 -- whose reported status may still be revoked.</summary>
    Saved,
}

/// <summary>The outcome of a key registration, and -- when it was saved -- the row to answer from.</summary>
public readonly record struct SetKeyOutcome(SetKeyStatus Status, MemberRelationship? Row);

/// <summary>
/// The four operations' decisions, lifted out of the HTTP handlers and off the database.
///
/// A grant that was never made is a 404 and never an upsert; a key another relationship already holds is a
/// 409, whether the up-front check or the unique index catches it; a stored key still reports a standing
/// that may be revoked, because registering a key is not an appeal. Each of those is reasoning, not
/// plumbing, so it lives here where the store and the transaction are faked and it can be pinned without a
/// database. The Entity Framework store and the real transaction runner -- what actually touches Postgres,
/// MySQL or SQL Server -- are exercised by the conformance suite instead.
/// </summary>
public sealed class RelationshipService(
    IRelationshipStore store,
    ITransactionRunner transaction,
    IAuditWriter audit,
    ISelfRevokeCommand selfRevoke,
    RelationshipTypeCatalog catalog)
{
    /// <summary>
    /// Every relationship the subject holds. Only rows whose subject equals the session's character for
    /// character are kept, for the reason <see cref="FindGrantedAsync"/> gives.
    /// </summary>
    public async Task<IReadOnlyList<MemberRelationship>> FindAllHeldByAsync(
        string subject, CancellationToken cancellationToken)
    {
        var rows = await store.ListHeldByAsync(subject, cancellationToken);
        return [.. rows.Where(row => IsExactly(row.IamSubjectId, subject))];
    }

    public Task<MemberRelationship?> AttestAsync(byte[] publicKey, CancellationToken cancellationToken) =>
        store.FindByPublicKeyAsync(publicKey, cancellationToken);

    /// <param name="publicKey">
    /// The key to register, or <c>null</c> to clear whatever key is set. Clearing is the member unsetting
    /// their own key to pause the relationship without giving up the grant: an empty key field saved from
    /// the page arrives here as null, and the caller returns to the same "waiting for your key" state as
    /// before their first save. It is the member's own act, so unlike an organisation revocation it can be
    /// undone by simply saving a key again.
    /// </param>
    public async Task<SetKeyOutcome> SetKeyAsync(
        string subject, string relationshipType, byte[]? publicKey, CancellationToken cancellationToken)
    {
        try
        {
            return await transaction.RunAsync(async token =>
            {
                // Not an upsert, and this is the line that makes it so: a relationship the organisation
                // never granted is refused, not created, or a member could vouch for themselves. This
                // holds for clearing too: there has to be a grant before there is a key to clear.
                var row = await FindGrantedAsync(subject, relationshipType, token);
                if (row is null)
                {
                    return new SetKeyOutcome(SetKeyStatus.NotGranted, null);
                }

                if (publicKey is null)
                {
                    // Clearing. No conflict check -- there is no key to collide with anything -- and, as
                    // with registering, RevokedAt and ExpiresAt are left alone, so a revoked relationship
                    // still reports revoked. Audited only when a key was actually removed, so re-saving an
                    // already-empty field is a quiet no-op rather than a stream of hollow log entries.
                    if (row.PublicKey is not null)
                    {
                        row.PublicKey = null;
                        await audit.WriteAsync(AuditEvent.KeyCleared, subject, row.RelationshipType, token);
                        await store.SaveAsync(token);
                    }

                    return new SetKeyOutcome(SetKeyStatus.Saved, row);
                }

                // A key belongs to exactly one relationship anywhere in the table, this member's own
                // included. Checked here for a clean answer; the unique index settles a race below.
                if (await store.IsKeyHeldElsewhereAsync(publicKey, row.Id, token))
                {
                    return new SetKeyOutcome(SetKeyStatus.Conflict, null);
                }

                // RevokedAt and ExpiresAt are deliberately left alone. Registering a key is not an appeal:
                // a member must not lift the organisation's revocation by registering a key again, so the status the
                // handler reports may well still say revoked even though the key was stored.
                row.PublicKey = publicKey;
                await audit.WriteAsync(AuditEvent.KeyRegistered, subject, row.RelationshipType, token);
                await store.SaveAsync(token);

                return new SetKeyOutcome(SetKeyStatus.Saved, row);
            }, cancellationToken);
        }
        catch (KeyConflictException)
        {
            // Lost the race on the unique index -- the same answer the up-front check gives.
            return new SetKeyOutcome(SetKeyStatus.Conflict, null);
        }
        catch (RelationshipVanishedException)
        {
            // Gone between the lookup and the write -- the same answer the lookup would have given, and
            // the rolled-back transaction has taken the audit entry with it.
            return new SetKeyOutcome(SetKeyStatus.NotGranted, null);
        }
    }

    public async Task<bool> DeleteAsync(string subject, string relationshipType, CancellationToken cancellationToken)
    {
        try
        {
            return await transaction.RunAsync(async token =>
            {
                var row = await FindGrantedAsync(subject, relationshipType, token);
                if (row is null)
                {
                    return false;
                }

                // A hard delete of this one relationship: a member's own erasure, not a status change, and not
                // a return to unkeyed. The audit trail records that it happened -- which is not the same
                // personal data as the key itself -- and the member's other relationships are untouched.
                store.Remove(row);
                await audit.WriteAsync(AuditEvent.KeyRemoved, subject, row.RelationshipType, token);
                await store.SaveAsync(token);

                return true;
            }, cancellationToken);
        }
        catch (RelationshipVanishedException)
        {
            return false;
        }
    }

    public async Task<bool> SelfRevokeAsync(
        string subject, string relationshipType, CancellationToken cancellationToken)
    {
        // Checked here so a relationship the caller does not hold is a 404 rather than the procedure's
        // silent success: the procedure cannot tell "not granted" from "already revoked", which is right
        // for it but wrong as an HTTP answer. No transaction -- the procedure is itself the atomic unit,
        // and it writes its own audit row.
        var row = await FindGrantedAsync(subject, relationshipType, cancellationToken);
        if (row is null)
        {
            return false;
        }

        await selfRevoke.InvokeAsync(subject, row.RelationshipType, cancellationToken);
        return true;
    }

    /// <summary>
    /// The member's relationship of exactly this type, or null. The type in the path has to be a value of
    /// the governed vocabulary, and the row's type and subject have to equal the path's type and the
    /// session's subject character for character, whatever the database's collation makes of the lookup.
    /// A collation that folds case would otherwise find <c>employee</c> for a path saying <c>EMPLOYEE</c>,
    /// and MySQL and SQL Server ignore trailing spaces, so they would find the row of subject <c>alice</c>
    /// for a session whose subject is <c>alice </c>. The three implementations would then answer the same
    /// request differently depending on the engine behind them. Every write names the row's own type from
    /// here on, never the path's.
    /// </summary>
    private async Task<MemberRelationship?> FindGrantedAsync(
        string subject, string relationshipType, CancellationToken cancellationToken)
    {
        if (!catalog.IsGoverned(relationshipType))
        {
            return null;
        }

        var row = await store.FindOwnAsync(subject, relationshipType, cancellationToken);

        return row is not null && IsExactly(row.RelationshipType, relationshipType) && IsExactly(row.IamSubjectId, subject)
            ? row
            : null;
    }

    private static bool IsExactly(string stored, string requested) =>
        string.Equals(stored, requested, StringComparison.Ordinal);
}
