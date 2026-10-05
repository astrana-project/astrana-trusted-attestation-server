using Astrana.TrustedAttestation.Server.Data;
using Npgsql;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// Which save failures are a key conflict. Only a unique-constraint violation, by the engine's own error
/// code, becomes the 409; anything else stays the fault it is, because a 409 tells the member their key
/// belongs to someone else, and a deadlock or a lost connection says nothing of the kind. PostgreSQL's
/// exception can be built here; the MySQL and SQL Server exception types have no public constructor, so
/// their codes (1062, and 2601 or 2627) are pinned by the pattern in the store and checked by the
/// conformance suite against the engines.
/// </summary>
public class RelationshipStoreTests
{
    private static PostgresException Postgres(string sqlState) =>
        new("duplicate key value violates unique constraint", "ERROR", "ERROR", sqlState);

    [Fact]
    public void A_postgres_unique_violation_is_a_conflict()
    {
        Assert.True(EfRelationshipStore.IsUniqueViolation(Postgres("23505")));
    }

    [Theory]
    [InlineData("23503")] // foreign key
    [InlineData("23514")] // check constraint
    [InlineData("40P01")] // deadlock
    [InlineData("08006")] // connection failure
    public void Any_other_postgres_error_is_not(string sqlState)
    {
        Assert.False(EfRelationshipStore.IsUniqueViolation(Postgres(sqlState)));
    }

    [Fact]
    public void An_exception_from_no_engine_is_not()
    {
        Assert.False(EfRelationshipStore.IsUniqueViolation(new InvalidOperationException("unrelated")));
        Assert.False(EfRelationshipStore.IsUniqueViolation(null));
    }
}
