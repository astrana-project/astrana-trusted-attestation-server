using System.Text.Json;
using System.Text.Json.Nodes;
using Astrana.TrustedAttestation.Server.Contract;
using Astrana.TrustedAttestation.Server.Manifest;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The manifests in <c>shared/test-material/invalid-manifests.json</c>, which all three implementations'
/// validators must refuse, so that the three refuse the same ones. The build copies the file beside the tests.
/// </summary>
public class SharedInvalidManifestsTests
{
    private static readonly JsonObject Material = JsonNode.Parse(
        File.ReadAllText(Path.Combine(AppContext.BaseDirectory, "invalid-manifests.json")))!.AsObject();

    private readonly RelationshipTypeCatalog _catalog = RelationshipTypeCatalog.Load();

    public static TheoryData<string> InvalidCases() =>
        new(Material["invalid"]!.AsArray().Select(invalid => invalid!["case"]!.GetValue<string>()));

    [Fact]
    public void The_shared_valid_manifest_is_accepted()
    {
        Assert.Empty(ManifestValidator.Validate(Document(Material["valid"]!), _catalog));
    }

    [Theory]
    [MemberData(nameof(InvalidCases))]
    public void Each_shared_invalid_manifest_is_refused_for_its_own_reason(string description)
    {
        var invalid = Material["invalid"]!.AsArray().Single(entry => entry!["case"]!.GetValue<string>() == description)!;
        var manifest = Material["valid"]!.DeepClone().AsObject();
        foreach (var (field, value) in invalid["set"]?.AsObject() ?? [])
        {
            manifest[field] = value?.DeepClone();
        }

        foreach (var field in invalid["remove"]?.AsArray() ?? [])
        {
            manifest.Remove(field!.GetValue<string>());
        }

        var expected = invalid["error"]!.AsArray().Select(text => text!.GetValue<string>()).ToList();

        Assert.Contains(
            ManifestValidator.Validate(Document(manifest), _catalog),
            error => expected.All(text => error.Contains(text, StringComparison.Ordinal)));
    }

    private static ManifestDocument Document(JsonNode manifest) => manifest.Deserialize<ManifestDocument>()!;
}
