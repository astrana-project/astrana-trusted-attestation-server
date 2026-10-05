using Astrana.TrustedAttestation.Server.Configuration;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Options;

namespace Astrana.TrustedAttestation.Server.Data;

/// <summary>
/// Calls <c>member_self_revoke_relationship</c>.
///
/// Through the procedure rather than by writing <c>revoked_at</c> directly, because the procedure is
/// what enforces the direction: NULL to now(), and never the reverse. A column grant cannot express
/// "settable once, never clearable", so the application is never given the ability to lift a revocation
/// at all -- only the organisation can, through <c>extend_member_relationship</c>, which this
/// application does not call and its database principal cannot execute.
///
/// The procedure writes its own audit row, so nothing here does. Doing both would put two entries in an
/// append-only log for one event, and the log cannot be corrected afterwards.
/// </summary>
public interface ISelfRevokeCommand
{
    Task InvokeAsync(string iamSubjectId, string relationshipType, CancellationToken cancellationToken);
}

public sealed class SelfRevokeCommand(
    TrustedAttestationDbContext db,
    IOptions<TrustedAttestationOptions> options) : ISelfRevokeCommand
{
    private readonly DatabaseProvider _provider = options.Value.Database.Provider;

    public async Task InvokeAsync(string iamSubjectId, string relationshipType, CancellationToken cancellationToken)
    {
        // Parameterised in every branch. The two values are interpolated by EF's SQL interpolation into
        // bind parameters, not into the statement text -- ExecuteSqlAsync takes a FormattableString
        // precisely so that this reads like concatenation while never being it.
        //
        // SQL Server spells a procedure call differently from the other two, and MySQL's procedures
        // declare no defaults, so every argument is passed explicitly everywhere.
        FormattableString call = _provider switch
        {
            DatabaseProvider.SqlServer =>
                $"EXEC member_self_revoke_relationship {iamSubjectId}, {relationshipType}",
            DatabaseProvider.PostgreSql or DatabaseProvider.MySql =>
                $"CALL member_self_revoke_relationship({iamSubjectId}, {relationshipType})",
            _ => throw new InvalidOperationException($"Unsupported database provider '{_provider}'."),
        };

        await db.Database.ExecuteSqlAsync(call, cancellationToken);
    }
}
