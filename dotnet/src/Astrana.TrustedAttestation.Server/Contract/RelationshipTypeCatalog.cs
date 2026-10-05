using System.Reflection;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Astrana.TrustedAttestation.Server.Contract;

/// <summary>
/// The governed <c>relationship_type</c> vocabulary, loaded from the embedded copy of the contract's
/// <c>relationship-types.json</c>.
///
/// The list is deliberately not restated in C#. Extending it is a change to the shared file and a feature
/// release of all three implementations, never per-org configuration (decision record 14 in docs/adr), and all
/// three import the same file so the vocabulary cannot drift between them.
/// </summary>
public sealed class RelationshipTypeCatalog
{
    private const string ResourceName = "Astrana.TrustedAttestation.Server.Contract.relationship-types.json";

    private readonly Dictionary<string, IReadOnlyDictionary<string, string>> _labelsById;

    private RelationshipTypeCatalog(int schemaVersion, IReadOnlyList<string> ids,
        Dictionary<string, IReadOnlyDictionary<string, string>> labelsById)
    {
        SchemaVersion = schemaVersion;
        Ids = ids;
        _labelsById = labelsById;
    }

    public int SchemaVersion { get; }

    /// <summary>Every governed identifier, in the order the contract file lists them.</summary>
    public IReadOnlyList<string> Ids { get; }

    public static RelationshipTypeCatalog Load()
    {
        using var stream = typeof(RelationshipTypeCatalog).GetTypeInfo().Assembly
            .GetManifestResourceStream(ResourceName)
            ?? throw new InvalidOperationException(
                $"Embedded contract resource '{ResourceName}' is missing. The build must embed the contract's " +
                "relationship-types.json; see Astrana.TrustedAttestation.Server.csproj.");

        var document = JsonSerializer.Deserialize<CatalogDocument>(stream, JsonOptions)
            ?? throw new InvalidOperationException($"Embedded contract resource '{ResourceName}' is empty.");

        if (document.Types.Count == 0)
        {
            throw new InvalidOperationException($"Embedded contract resource '{ResourceName}' defines no types.");
        }

        var ids = new List<string>(document.Types.Count);
        var labels = new Dictionary<string, IReadOnlyDictionary<string, string>>(StringComparer.Ordinal);

        foreach (var type in document.Types)
        {
            if (string.IsNullOrWhiteSpace(type.Id))
            {
                throw new InvalidOperationException($"Embedded contract resource '{ResourceName}' has a type with no id.");
            }

            if (!labels.TryAdd(type.Id, type.Labels))
            {
                throw new InvalidOperationException(
                    $"Embedded contract resource '{ResourceName}' lists '{type.Id}' more than once.");
            }

            ids.Add(type.Id);
        }

        return new RelationshipTypeCatalog(document.SchemaVersion, ids, labels);
    }

    /// <summary>
    /// Whether a value is part of the governed vocabulary. Matching is ordinal and case-sensitive, because
    /// the identifiers are fixed machine-readable keys, never display text (decision record 14 in docs/adr).
    /// </summary>
    public bool IsGoverned(string? value) => value is not null && _labelsById.ContainsKey(value);

    /// <summary>
    /// The canonical display label for a value in the requested locale, falling back to the more general
    /// language (<c>fr-CA</c> to <c>fr</c>), then to English, then to the identifier itself.
    ///
    /// Labels are maintained centrally in the contract file rather than translated per instance, so the
    /// same relationship reads the same way regardless of which org issued it.
    /// </summary>
    public string Label(string id, string? locale)
    {
        if (!_labelsById.TryGetValue(id, out var labels))
        {
            return id;
        }

        foreach (var candidate in LocaleFallbacks(locale))
        {
            if (labels.TryGetValue(candidate, out var label))
            {
                return label;
            }
        }

        return labels.TryGetValue("en", out var english) ? english : id;
    }

    private static IEnumerable<string> LocaleFallbacks(string? locale)
    {
        if (string.IsNullOrWhiteSpace(locale))
        {
            yield break;
        }

        yield return locale;

        var separator = locale.IndexOf('-');
        if (separator > 0)
        {
            yield return locale[..separator];
        }
    }

    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web);

    private sealed class CatalogDocument
    {
        [JsonPropertyName("schema_version")]
        public int SchemaVersion { get; init; }

        [JsonPropertyName("types")]
        public List<CatalogEntry> Types { get; init; } = [];
    }

    private sealed class CatalogEntry
    {
        [JsonPropertyName("id")]
        public string Id { get; init; } = string.Empty;

        [JsonPropertyName("labels")]
        public Dictionary<string, string> Labels { get; init; } = [];
    }
}
