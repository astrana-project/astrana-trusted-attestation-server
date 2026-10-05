using Astrana.TrustedAttestation.Server.Manifest;

namespace Astrana.TrustedAttestation.Server.Endpoints;

public static class ManifestEndpoint
{
    /// <summary>
    /// A single, permanently stable path -- never versioned. That is the point of a well-known discovery
    /// document: a client never has to guess a version to find it. The <c>manifest_version</c> field inside
    /// is what tells a consumer how to parse the content.
    /// </summary>
    public const string Path = "/.well-known/ata-manifest.json";

    /// <summary>
    /// GET and HEAD. A consumer checking that a manifest is there, or that it has not changed, asks with
    /// HEAD, and the other two frameworks answer HEAD for every GET route. The server sends the headers and
    /// no body for HEAD on its own.
    /// </summary>
    private static readonly string[] Methods = [HttpMethods.Get, HttpMethods.Head];

    public static void MapTrustedAttestationManifest(this IEndpointRouteBuilder app)
    {
        // Public, no auth. It describes the organisation, not any member, so there is nothing here to
        // protect -- and a prospective member has to be able to read it before they have an account.
        app.MapMethods(Path, Methods, (ManifestDocument manifest) => Results.Ok(manifest)).AllowAnonymous();
    }
}
