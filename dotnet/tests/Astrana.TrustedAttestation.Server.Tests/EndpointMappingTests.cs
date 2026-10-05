using Astrana.TrustedAttestation.Server.Configuration;
using Astrana.TrustedAttestation.Server.Contract;
using Astrana.TrustedAttestation.Server.Data;
using Astrana.TrustedAttestation.Server.Endpoints;
using Astrana.TrustedAttestation.Server.Manifest;
using Astrana.TrustedAttestation.Server.Security;
using Astrana.TrustedAttestation.Server.Services;
using Astrana.TrustedAttestation.Server.Tests.Support;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Http.Metadata;
using Microsoft.AspNetCore.Routing;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Options;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// What the route table says about the endpoints, read off the mapped endpoints themselves: the manifest
/// answers HEAD as well as GET, and every API endpoint carries the request-size cap the server enforces.
/// </summary>
public class EndpointMappingTests
{
    /// <summary>Enough of a route builder to map the endpoints on and read them back.</summary>
    private sealed class RouteTable : IEndpointRouteBuilder
    {
        public RouteTable()
        {
            var services = new ServiceCollection().AddLogging().AddRouting();
            services.AddSingleton(Options.Create(new TrustedAttestationOptions()));
            services.AddSingleton<MemberIdentityResolver>();
            services.AddSingleton<IRelationshipStore, FakeRelationshipStore>();
            services.AddSingleton<ITransactionRunner, ImmediateTransactionRunner>();
            services.AddSingleton<IAuditWriter, RecordingAuditWriter>();
            services.AddSingleton<ISelfRevokeCommand, RecordingSelfRevoke>();
            services.AddSingleton(RelationshipTypeCatalog.Load());
            services.AddSingleton<RelationshipService>();
            services.AddSingleton(TimeProvider.System);
            services.AddSingleton(new ManifestDocument
            {
                ManifestVersion = 1,
                DefaultLocale = "en",
                Name = new Dictionary<string, string> { ["en"] = "Acme Inc" },
                RelationshipTypes = ["employee"],
                EnrollmentUrl = "https://org.example/enrol",
                AttestationUrl = "https://org.example/attest",
            });
            ServiceProvider = services.BuildServiceProvider();
        }

        public IServiceProvider ServiceProvider { get; }

        public ICollection<EndpointDataSource> DataSources { get; } = [];

        public IApplicationBuilder CreateApplicationBuilder() => new ApplicationBuilder(ServiceProvider);

        public IReadOnlyList<RouteEndpoint> Endpoints =>
            [.. DataSources.SelectMany(source => source.Endpoints).OfType<RouteEndpoint>()];
    }

    private static IReadOnlyList<string> MethodsOf(Endpoint endpoint) =>
        endpoint.Metadata.GetMetadata<IHttpMethodMetadata>()?.HttpMethods ?? [];

    [Fact]
    public void The_manifest_answers_get_and_head()
    {
        var routes = new RouteTable();
        routes.MapTrustedAttestationManifest();

        var manifest = Assert.Single(routes.Endpoints);
        Assert.Equal(ManifestEndpoint.Path, manifest.RoutePattern.RawText);
        Assert.Equal(["GET", "HEAD"], MethodsOf(manifest).Order());
        Assert.NotNull(manifest.Metadata.GetMetadata<Microsoft.AspNetCore.Authorization.IAllowAnonymous>());
    }

    [Fact]
    public void Every_api_endpoint_is_capped_at_64_kilobytes()
    {
        var routes = new RouteTable();
        routes.MapTrustedAttestationApi();

        var endpoints = routes.Endpoints;
        Assert.Equal(10, endpoints.Count); // five operations under /api/v1 and again under /api
        Assert.All(endpoints, endpoint =>
            Assert.Equal(PublicKeyBody.MaxBytes, endpoint.Metadata.GetMetadata<IRequestSizeLimitMetadata>()?.MaxRequestBodySize));
    }
}
