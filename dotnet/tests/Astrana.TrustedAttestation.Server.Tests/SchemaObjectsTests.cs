using Astrana.TrustedAttestation.Server.Data;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The schema existence check's judgement. It looks for the objects the application itself uses, the two
/// tables and the two procedures it calls, so a database holding the tables but not those procedures is
/// incomplete, named as such, rather than "present". The three administrator procedures are left out,
/// because a login holding only the application role cannot see them in information_schema, and probing
/// for them would refuse a hardened deployment whose administrator applied the whole schema. The probe
/// itself runs against an engine in the conformance suite. This pins what it is looking for and what it
/// concludes.
/// </summary>
public class SchemaObjectsTests
{
    [Fact]
    public void The_objects_the_application_uses_are_expected()
    {
        Assert.Equal(["member_relationships", "audit_log"], SchemaObjects.Tables);
        Assert.Equal(["member_self_revoke_relationship", "prune_audit_log"], SchemaObjects.Procedures);
        Assert.Equal(4, SchemaObjects.All.Count);
    }

    [Fact]
    public void The_administrator_procedures_are_not_probed()
    {
        // A login holding only the application role sees none of these, so a hardened deployment must not
        // depend on seeing them.
        var procedures = SchemaObjects.ProceduresQuery("current_schema()");

        Assert.DoesNotContain("'grant_member_relationship'", procedures, StringComparison.Ordinal);
        Assert.DoesNotContain("'revoke_member_relationship'", procedures, StringComparison.Ordinal);
        Assert.DoesNotContain("'extend_member_relationship'", procedures, StringComparison.Ordinal);
    }

    [Fact]
    public void A_schema_seen_through_the_application_role_has_nothing_missing()
    {
        // What a login holding only the application role sees of a schema the administrator applied in full.
        Assert.Empty(SchemaObjects.Missing(
            ["member_relationships", "audit_log"],
            ["member_self_revoke_relationship", "prune_audit_log"]));
    }

    [Fact]
    public void A_complete_schema_has_nothing_missing()
    {
        Assert.Empty(SchemaObjects.Missing(SchemaObjects.Tables, SchemaObjects.Procedures));
    }

    [Fact]
    public void An_empty_database_is_missing_everything()
    {
        Assert.Equal(4, SchemaObjects.Missing([], []).Count);
    }

    [Fact]
    public void A_partial_schema_names_what_is_missing()
    {
        // The tables exist and a procedure does not, which is what a script that stopped half-way, or a
        // dropped procedure, leaves behind.
        var missing = SchemaObjects.Missing(SchemaObjects.Tables, ["member_self_revoke_relationship"]);

        Assert.Equal(["procedure prune_audit_log"], missing);
    }

    [Fact]
    public void Names_are_compared_exactly()
    {
        // On MySQL a table whose name differs in case is a different table, and the scripts create
        // lower-case names everywhere.
        var missing = SchemaObjects.Missing(["Member_Relationships", "audit_log"], SchemaObjects.Procedures);

        Assert.Equal(["table member_relationships"], missing);
    }

    [Fact]
    public void The_probes_filter_on_the_current_schema_and_name_every_expected_object()
    {
        var tables = SchemaObjects.TablesQuery("current_schema()");
        var procedures = SchemaObjects.ProceduresQuery("current_schema()");

        Assert.Contains("table_schema = current_schema()", tables, StringComparison.Ordinal);
        Assert.Contains("'member_relationships', 'audit_log'", tables, StringComparison.Ordinal);
        Assert.Contains("routine_schema = current_schema()", procedures, StringComparison.Ordinal);
        Assert.Contains("routine_type = 'PROCEDURE'", procedures, StringComparison.Ordinal);
        Assert.All(SchemaObjects.Procedures, procedure => Assert.Contains($"'{procedure}'", procedures, StringComparison.Ordinal));
    }
}
