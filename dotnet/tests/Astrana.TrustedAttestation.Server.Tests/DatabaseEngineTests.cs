using Astrana.TrustedAttestation.Server.Configuration;
using Astrana.TrustedAttestation.Server.Data;
using Microsoft.EntityFrameworkCore;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The configured engine picks the Entity Framework provider, so one codebase runs on whichever of the three
/// databases the organisation already has. No connection is opened here.
/// </summary>
public class DatabaseEngineTests
{
    private static string ProviderFor(DatabaseProvider provider)
    {
        var options = new DbContextOptionsBuilder<TrustedAttestationDbContext>();
        TrustedAttestationDbContext.UseConfiguredEngine(options, new DatabaseOptions
        {
            Provider = provider,
            ConnectionString = "Server=localhost;Database=ata",
        });

        using var db = new TrustedAttestationDbContext(options.Options);
        return db.Database.ProviderName!;
    }

    [Theory]
    [InlineData(DatabaseProvider.SqlServer, "Microsoft.EntityFrameworkCore.SqlServer")]
    [InlineData(DatabaseProvider.PostgreSql, "Npgsql.EntityFrameworkCore.PostgreSQL")]
    [InlineData(DatabaseProvider.MySql, "MySql.EntityFrameworkCore")]
    public void Each_configured_engine_gets_its_own_provider(DatabaseProvider provider, string expected)
    {
        Assert.Equal(expected, ProviderFor(provider));
    }

    [Fact]
    public void An_engine_outside_the_three_stops_start_up()
    {
        var exception = Assert.Throws<InvalidOperationException>(() => ProviderFor((DatabaseProvider)99));

        Assert.Equal("Unsupported database provider '99'.", exception.Message);
    }
}
