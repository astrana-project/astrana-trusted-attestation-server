using System.Data.Common;
using System.Reflection;
using Astrana.TrustedAttestation.Server.Configuration;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Options;

namespace Astrana.TrustedAttestation.Server.Data;

/// <summary>
/// Creates the schema on first run, so IT staff only have to deploy the application, create an empty
/// database, and set connection details (decision record 12 in docs/adr).
///
/// The DDL executed is the repository's own <c>shared/schema/*.sql</c>, embedded verbatim. It is the same
/// script a database administrator would review, rather than a second definition maintained in EF
/// migrations that could drift from it.
/// </summary>
public sealed class SchemaInitializer(
    TrustedAttestationDbContext db,
    IOptions<TrustedAttestationOptions> options,
    ILogger<SchemaInitializer> logger)
{
    private readonly TrustedAttestationOptions _options = options.Value;

    /// <summary>How long the loser of a creation race waits for the winner to finish before giving up.</summary>
    private static readonly TimeSpan RaceWait = TimeSpan.FromSeconds(30);

    public async Task InitializeAsync(CancellationToken cancellationToken = default)
    {
        var provider = _options.Database.Provider;

        var missing = await MissingObjectsAsync(cancellationToken);
        if (missing.Count == 0)
        {
            logger.LogInformation("Schema already present; nothing to create.");
            return;
        }

        // Some of the objects the application uses and not all of them is not a first run. It is a failed creation, a hand-applied
        // script that stopped part-way, or a dropped procedure, and running the script over it would fail
        // on the first object that exists. Name what is missing and stop.
        if (missing.Count < SchemaObjects.All.Count)
        {
            throw new InvalidOperationException(
                "The schema is incomplete, missing " + string.Join(", ", missing) + ". Apply " +
                $"shared/schema/schema-{ScriptSuffix(provider)}.sql to an empty database, or restore the missing objects from it.");
        }

        if (!_options.Database.CreateSchemaOnStartup)
        {
            throw new InvalidOperationException(
                "The schema does not exist and TrustedAttestation:Database:CreateSchemaOnStartup is false. " +
                $"Apply shared/schema/schema-{ScriptSuffix(provider)}.sql to the database, or turn the setting back on.");
        }

        var script = ReadEmbeddedScript(provider);
        var statements = SqlScriptSplitter.Split(script, provider);

        logger.LogInformation("Creating schema for {Provider} ({StatementCount} statements).",
            provider, statements.Count);

        try
        {
            // Not wrapped in one transaction: MySQL commits DDL implicitly, so a rollback would be a promise
            // the engine cannot keep. A failure part-way leaves a partial schema, which the operator resolves
            // by dropping the database and restarting -- correct for a first-run bootstrap on an empty database.
            foreach (var statement in statements)
            {
                await db.Database.ExecuteSqlRawAsync(statement, cancellationToken);
            }
        }
        catch (DbException exception)
        {
            // Two instances starting against one empty database both see nothing and both run the script.
            // The loser fails on an object the winner created a moment earlier. That is not a fault if the
            // winner finishes, so the loser waits for the schema to become complete and carries on.
            if (await BecomesCompleteAsync(cancellationToken))
            {
                logger.LogInformation("Another instance created the schema first; nothing left to create.");
                return;
            }

            throw new InvalidOperationException(
                "Creating the schema failed and it is incomplete, missing " +
                string.Join(", ", await MissingObjectsAsync(cancellationToken)) +
                $". Drop what was created and restart, or apply shared/schema/schema-{ScriptSuffix(provider)}.sql by hand.",
                exception);
        }

        logger.LogInformation("Schema created.");
    }

    private async Task<bool> BecomesCompleteAsync(CancellationToken cancellationToken)
    {
        var deadline = DateTime.UtcNow + RaceWait;
        while (true)
        {
            if ((await MissingObjectsAsync(cancellationToken)).Count == 0)
            {
                return true;
            }

            if (DateTime.UtcNow >= deadline)
            {
                return false;
            }

            await Task.Delay(TimeSpan.FromSeconds(1), cancellationToken);
        }
    }

    /// <summary>
    /// Every object the application uses that the database does not have: the two tables and the two
    /// procedures it calls (see SchemaObjects for why the administrator procedures are not probed). Creating
    /// the schema still runs the whole file, administrator procedures included. All three engines expose <c>information_schema.tables</c> and
    /// <c>information_schema.routines</c>, but each names the current schema differently -- and the filter
    /// matters: MySQL's information_schema spans every database the connection can see, so an unfiltered
    /// probe would find a table of the same name in someone else's database and conclude ours already exists.
    ///
    /// Run through plain ADO rather than the ORM. EF maps a scalar query back by column name, and
    /// PostgreSQL folds unquoted identifiers to lower case while the other two do not -- so the one
    /// portable-looking query is the one thing here that cannot be written portably through EF.
    /// </summary>
    private async Task<IReadOnlyList<string>> MissingObjectsAsync(CancellationToken cancellationToken)
    {
        var currentSchema = _options.Database.Provider switch
        {
            DatabaseProvider.SqlServer => "SCHEMA_NAME()",
            DatabaseProvider.PostgreSql => "current_schema()",
            DatabaseProvider.MySql => "DATABASE()",
            _ => throw new InvalidOperationException(
                $"Unsupported database provider '{_options.Database.Provider}'."),
        };

        await db.Database.OpenConnectionAsync(cancellationToken);

        try
        {
            var tables = await NamesAsync(SchemaObjects.TablesQuery(currentSchema), cancellationToken);
            var procedures = await NamesAsync(SchemaObjects.ProceduresQuery(currentSchema), cancellationToken);

            return SchemaObjects.Missing(tables, procedures);
        }
        finally
        {
            await db.Database.CloseConnectionAsync();
        }
    }

    private async Task<List<string>> NamesAsync(string sql, CancellationToken cancellationToken)
    {
        await using var command = db.Database.GetDbConnection().CreateCommand();
        command.CommandText = sql;

        var names = new List<string>();
        await using var reader = await command.ExecuteReaderAsync(cancellationToken);
        while (await reader.ReadAsync(cancellationToken))
        {
            names.Add(reader.GetString(0));
        }

        return names;
    }

    private static string ReadEmbeddedScript(DatabaseProvider provider)
    {
        var name = $"Astrana.TrustedAttestation.Server.Schema.schema-{ScriptSuffix(provider)}.sql";

        using var stream = typeof(SchemaInitializer).GetTypeInfo().Assembly.GetManifestResourceStream(name)
            ?? throw new InvalidOperationException(
                $"Embedded schema resource '{name}' is missing. The build must embed the contract's schema " +
                "scripts; see Astrana.TrustedAttestation.Server.csproj.");

        using var reader = new StreamReader(stream);
        return reader.ReadToEnd();
    }

    private static string ScriptSuffix(DatabaseProvider provider) => provider switch
    {
        DatabaseProvider.SqlServer => "mssql",
        DatabaseProvider.PostgreSql => "postgres",
        DatabaseProvider.MySql => "mysql",
        _ => throw new ArgumentOutOfRangeException(nameof(provider), provider, "Unsupported database provider."),
    };
}

/// <summary>
/// The schema objects the application itself uses, and the probe that finds out which of them a database
/// has. Separated from the initializer so the judgement (complete, absent, or partial and what is missing)
/// can be pinned without an engine.
///
/// The schema file also creates three administrator procedures (grant, revoke and extend), which the
/// application never calls. They are left out of the probe, because information_schema shows a login only
/// the objects it holds a privilege on, so a server running as the narrow application role would never see
/// them and would refuse to start against a schema its administrator applied in full.
/// </summary>
internal static class SchemaObjects
{
    public static readonly IReadOnlyList<string> Tables = ["member_relationships", "audit_log"];

    /// <summary>The procedures the application calls, in the order the schema file creates them.</summary>
    public static readonly IReadOnlyList<string> Procedures =
    [
        "member_self_revoke_relationship",
        "prune_audit_log",
    ];

    public static readonly IReadOnlyList<string> All = [.. Tables, .. Procedures];

    public static string TablesQuery(string currentSchema) =>
        "SELECT table_name FROM information_schema.tables " +
        $"WHERE table_schema = {currentSchema} AND table_name IN ({Quoted(Tables)})";

    public static string ProceduresQuery(string currentSchema) =>
        "SELECT routine_name FROM information_schema.routines " +
        $"WHERE routine_schema = {currentSchema} AND routine_type = 'PROCEDURE' AND routine_name IN ({Quoted(Procedures)})";

    /// <summary>
    /// The expected objects the database lacks, in the order the schema file creates them. Names are
    /// compared exactly: the scripts create lower-case names, every engine reports those back as written,
    /// and on MySQL a table whose name differs only in case is a different table.
    /// </summary>
    public static IReadOnlyList<string> Missing(IEnumerable<string> presentTables, IEnumerable<string> presentProcedures)
    {
        var tables = new HashSet<string>(presentTables, StringComparer.Ordinal);
        var procedures = new HashSet<string>(presentProcedures, StringComparer.Ordinal);

        return
        [
            .. Tables.Where(table => !tables.Contains(table)).Select(table => $"table {table}"),
            .. Procedures.Where(procedure => !procedures.Contains(procedure)).Select(procedure => $"procedure {procedure}"),
        ];
    }

    private static string Quoted(IEnumerable<string> names) => string.Join(", ", names.Select(name => $"'{name}'"));
}
