using System.Reflection;
using Astrana.TrustedAttestation.Server.Configuration;
using Astrana.TrustedAttestation.Server.Data;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The splitter runs against the real embedded schema scripts, not hand-written fixtures. If the contract
/// repo's DDL changes shape -- a new procedure, a different delimiter -- these tests see it.
/// </summary>
public class SqlScriptSplitterTests
{
    private static string EmbeddedScript(string suffix)
    {
        var name = $"Astrana.TrustedAttestation.Server.Schema.schema-{suffix}.sql";
        using var stream = typeof(SchemaInitializer).GetTypeInfo().Assembly.GetManifestResourceStream(name)
            ?? throw new InvalidOperationException($"Missing embedded resource '{name}'.");
        using var reader = new StreamReader(stream);
        return reader.ReadToEnd();
    }

    private static IReadOnlyList<string> Split(DatabaseProvider provider, string suffix) =>
        SqlScriptSplitter.Split(EmbeddedScript(suffix), provider);

    [Theory]
    [InlineData(DatabaseProvider.SqlServer, "mssql")]
    [InlineData(DatabaseProvider.PostgreSql, "postgres")]
    [InlineData(DatabaseProvider.MySql, "mysql")]
    public void Produces_both_tables_and_every_procedure(DatabaseProvider provider, string suffix)
    {
        var statements = Split(provider, suffix);

        Assert.Contains(statements, s => s.Contains("CREATE TABLE member_relationships", StringComparison.OrdinalIgnoreCase));
        Assert.Contains(statements, s => s.Contains("CREATE TABLE audit_log", StringComparison.OrdinalIgnoreCase));
        foreach (var procedure in new[]
                 {
                     "grant_member_relationship",
                     "revoke_member_relationship",
                     "extend_member_relationship",
                     "member_self_revoke_relationship",
                     "prune_audit_log",
                 })
        {
            Assert.Contains(statements, s => s.Contains(procedure, StringComparison.OrdinalIgnoreCase));
        }
    }

    [Theory]
    [InlineData(DatabaseProvider.SqlServer, "mssql")]
    [InlineData(DatabaseProvider.PostgreSql, "postgres")]
    [InlineData(DatabaseProvider.MySql, "mysql")]
    public void Emits_no_empty_or_comment_only_statements(DatabaseProvider provider, string suffix)
    {
        foreach (var statement in Split(provider, suffix))
        {
            Assert.NotEqual(0, statement.Trim().Length);

            var hasExecutableLine = statement
                .Split('\n')
                .Select(line => line.Trim())
                .Any(line => line.Length > 0 && !line.StartsWith("--", StringComparison.Ordinal));

            Assert.True(hasExecutableLine, $"Comment-only statement would be sent to the server:\n{statement}");
        }
    }

    [Fact]
    public void Never_passes_client_only_directives_to_the_driver()
    {
        // GO and DELIMITER are features of each engine's command-line client. A driver rejects them.
        foreach (var statement in Split(DatabaseProvider.SqlServer, "mssql"))
        {
            Assert.DoesNotContain("\nGO", statement, StringComparison.OrdinalIgnoreCase);
        }

        foreach (var statement in Split(DatabaseProvider.MySql, "mysql"))
        {
            Assert.DoesNotContain("DELIMITER", statement, StringComparison.OrdinalIgnoreCase);
            Assert.DoesNotContain("$$", statement, StringComparison.Ordinal);
        }
    }

    [Fact]
    public void Keeps_a_postgres_procedure_body_whole()
    {
        // The bodies are dollar-quoted and full of semicolons. Splitting naively on ';' would cut a
        // procedure into fragments, each of which fails on its own.
        //
        // Matched on the opening parenthesis, because the schema's GRANT examples name the same
        // procedures in a comment and a bare name matches those as well.
        var procedure = Assert.Single(
            Split(DatabaseProvider.PostgreSql, "postgres"),
            s => s.Contains("PROCEDURE revoke_member_relationship(", StringComparison.OrdinalIgnoreCase));

        Assert.Contains("UPDATE member_relationships", procedure, StringComparison.OrdinalIgnoreCase);
        Assert.Contains("INSERT INTO audit_log", procedure, StringComparison.OrdinalIgnoreCase);
        Assert.Contains("END;", procedure, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void Keeps_a_mysql_procedure_body_whole()
    {
        var procedure = Assert.Single(
            Split(DatabaseProvider.MySql, "mysql"),
            s => s.Contains("PROCEDURE extend_member_relationship(", StringComparison.OrdinalIgnoreCase));

        Assert.Contains("UPDATE member_relationships", procedure, StringComparison.OrdinalIgnoreCase);
        Assert.Contains("INSERT INTO audit_log", procedure, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void Postgres_semicolons_inside_string_literals_do_not_split()
    {
        var statements = SqlScriptSplitter.Split(
            "INSERT INTO t (a) VALUES ('one; two'); INSERT INTO t (a) VALUES ('three');",
            DatabaseProvider.PostgreSql);

        Assert.Equal(2, statements.Count);
        Assert.Contains("one; two", statements[0], StringComparison.Ordinal);
    }

    [Fact]
    public void Sql_server_go_separator_is_matched_only_on_its_own_line()
    {
        // "GO" appears inside identifiers and words; only a line that is nothing but GO is a batch break.
        var statements = SqlScriptSplitter.Split(
            "SELECT 'ONGOING';\nGO\nSELECT 2;\n",
            DatabaseProvider.SqlServer);

        Assert.Equal(2, statements.Count);
        Assert.Contains("ONGOING", statements[0], StringComparison.Ordinal);
    }
}
