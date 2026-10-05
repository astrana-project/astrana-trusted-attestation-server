using Microsoft.EntityFrameworkCore;

namespace Astrana.TrustedAttestation.Server.Data;

/// <summary>
/// Runs an operation inside one database transaction, committing on success and rolling back on any
/// exception it throws.
///
/// Behind an interface so the write path's atomic unit -- find the relationship, check the key, write the
/// audit row and save, all or nothing -- can be unit-tested by running the operation directly. It is the
/// .NET counterpart of the Spring <c>TransactionTemplate</c> the Java implementation composes the same
/// logic on, which keeps the two stacks' write paths shaped the same way rather than only behaving the same.
/// </summary>
public interface ITransactionRunner
{
    Task<T> RunAsync<T>(Func<CancellationToken, Task<T>> operation, CancellationToken cancellationToken);
}

public sealed class EfTransactionRunner(TrustedAttestationDbContext db) : ITransactionRunner
{
    public async Task<T> RunAsync<T>(
        Func<CancellationToken, Task<T>> operation, CancellationToken cancellationToken)
    {
        await using var transaction = await db.Database.BeginTransactionAsync(cancellationToken);

        // If the operation throws -- a save that lost the unique-index race among them -- the transaction
        // is disposed without a commit, which rolls it back, and the exception propagates to the caller.
        var result = await operation(cancellationToken);

        await transaction.CommitAsync(cancellationToken);
        return result;
    }
}
