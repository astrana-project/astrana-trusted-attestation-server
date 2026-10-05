using Astrana.TrustedAttestation.Server.Configuration;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Storage.ValueConversion;

namespace Astrana.TrustedAttestation.Server.Data;

/// <summary>
/// The whole data layer: one table, one row per relationship.
///
/// <c>audit_log</c> is absent from the model. The application's database principal holds
/// <c>INSERT</c> only on that table, because an audit trail that can be edited after the fact is not one
/// (decision record 17 in docs/adr). EF Core's insert path reads the generated key back, which on PostgreSQL means a
/// <c>RETURNING</c> clause requiring <c>SELECT</c> privilege the principal does not have. Audit rows are
/// therefore written through parameterised raw SQL in <see cref="AuditWriter"/>, which enlists in the
/// same transaction as the change it records.
/// </summary>
public sealed class TrustedAttestationDbContext(DbContextOptions<TrustedAttestationDbContext> options) : DbContext(options)
{
    public DbSet<MemberRelationship> MemberRelationships => Set<MemberRelationship>();

    /// <summary>
    /// One codebase, three engines. The ORM abstracts the engine, so an organisation picks whichever database
    /// it already runs regardless of which stack it deployed.
    /// </summary>
    public static void UseConfiguredEngine(DbContextOptionsBuilder options, DatabaseOptions database)
    {
        var connectionString = database.ConnectionString;

        switch (database.Provider)
        {
            case DatabaseProvider.SqlServer:
                options.UseSqlServer(connectionString);
                break;
            case DatabaseProvider.PostgreSql:
                options.UseNpgsql(connectionString);
                break;
            case DatabaseProvider.MySql:
                options.UseMySQL(connectionString);
                break;
            default:
                throw new InvalidOperationException($"Unsupported database provider '{database.Provider}'.");
        }
    }

    protected override void OnModelCreating(ModelBuilder modelBuilder)
    {
        var entity = modelBuilder.Entity<MemberRelationship>();

        entity.ToTable("member_relationships");
        entity.HasKey(e => e.Id);

        entity.Property(e => e.Id).HasColumnName("id").ValueGeneratedOnAdd();
        entity.Property(e => e.IamSubjectId).HasColumnName("iam_subject_id").HasMaxLength(255).IsRequired();

        // Not required: a relationship exists from the moment the organisation grants it, which is
        // before the member has ever logged in to set a key.
        entity.Property(e => e.PublicKey).HasColumnName("public_key").HasMaxLength(32);
        entity.Property(e => e.RelationshipType).HasColumnName("relationship_type").HasMaxLength(64).IsRequired();
        entity.Property(e => e.RelationshipSubtype).HasColumnName("relationship_subtype").HasMaxLength(255);
        // Stamp DateTimeKind.Utc on the way out of the database. These instants are stored in UTC (the app
        // works entirely in UTC), but only PostgreSQL's timestamptz carries that back through Npgsql as
        // Kind=Utc; SQL Server's datetime2 and MySQL's datetime return Kind=Unspecified. System.Text.Json
        // renders an Unspecified DateTime with no trailing "Z", so expires_at on the /me response would
        // read "2020-01-01T00:00:00" on those engines and "2020-01-01T00:00:00Z" on PostgreSQL -- and the
        // other two implementations always emit the Z. The consumer must not be able to tell the engine (or
        // the stack) apart, so read every stored instant back as UTC. Read-side only (write is identity):
        // it stamps the Kind, it never shifts the instant.
        var readAsUtc = new ValueConverter<DateTime, DateTime>(
            value => value,
            value => DateTime.SpecifyKind(value, DateTimeKind.Utc));

        entity.Property(e => e.ExpiresAt).HasColumnName("expires_at").HasConversion(readAsUtc);
        entity.Property(e => e.RevokedAt).HasColumnName("revoked_at").HasConversion(readAsUtc);

        // iam_subject_id is indexed but NOT unique -- a member holding several relationships has a row
        // for each, and making it unique here would silently limit every member to one. What is unique is
        // the pair, so an organisation cannot grant the same type twice and leave two ambiguous rows that
        // PUT and revoke could not tell apart.
        entity.HasIndex(e => e.IamSubjectId);
        entity.HasIndex(e => new { e.IamSubjectId, e.RelationshipType }).IsUnique();

        // The verification hot path: every check and every periodic re-check hits this index. Unique
        // across the whole table, not per member -- see MemberRelationship.PublicKey for why that is a
        // security property rather than data hygiene.
        //
        // This mapping is metadata for querying, not a schema source: the table is created from the
        // canonical schema-{engine}.sql (SchemaInitializer), never from this model -- there is no
        // EnsureCreated or migration anywhere. That matters here because the real index is *filtered*
        // (UNIQUE WHERE public_key IS NOT NULL), so many granted-but-unkeyed rows with a NULL key are
        // allowed while two identical non-null keys collide. EF Core has no HasFilter left on it because it
        // never emits DDL; were that to change, the filter would have to be added (and expressed per engine)
        // to keep SQL Server from rejecting a second NULL and breaking that requirement.
        entity.HasIndex(e => e.PublicKey).IsUnique();
    }
}
