package org.astrana.trustedattestation.contract;

import java.io.IOException;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import org.springframework.boot.json.JsonParserFactory;
import org.springframework.core.io.ClassPathResource;
import org.springframework.stereotype.Component;

/**
 * The governed {@code relationship_type} vocabulary, loaded from the packaged copy of the contract's
 * {@code relationship-types.json}.
 *
 * <p>The list is deliberately not restated in Java. Extending it is a change to the shared file and a feature
 * release of all three implementations, never per-org configuration (decision record 14 in docs/adr), and all three
 * import the same file so the vocabulary cannot drift between them.
 */
@Component
public class RelationshipTypeCatalog {

    private static final String RESOURCE = "contract/relationship-types.json";

    private final int schemaVersion;
    private final List<String> ids;
    private final Map<String, Map<String, String>> labelsById;

    public RelationshipTypeCatalog() {
        Map<String, Object> root = readContractFile();

        this.schemaVersion = root.get("schema_version") instanceof Number version ? version.intValue() : 0;

        List<String> loadedIds = new ArrayList<>();
        Map<String, Map<String, String>> loadedLabels = new LinkedHashMap<>();

        if (!(root.get("types") instanceof List<?> types) || types.isEmpty()) {
            throw new IllegalStateException("Packaged contract resource '" + RESOURCE + "' defines no types.");
        }

        for (Object element : types) {
            if (!(element instanceof Map<?, ?> type)) {
                continue;
            }

            Object id = type.get("id");
            if (!(id instanceof String identifier) || identifier.isBlank()) {
                throw new IllegalStateException("Packaged contract resource '" + RESOURCE + "' has a type with no id.");
            }

            Map<String, String> labels = new LinkedHashMap<>();
            if (type.get("labels") instanceof Map<?, ?> rawLabels) {
                rawLabels.forEach((locale, label) -> labels.put(String.valueOf(locale), String.valueOf(label)));
            }

            if (loadedLabels.put(identifier, Map.copyOf(labels)) != null) {
                throw new IllegalStateException(
                        "Packaged contract resource '" + RESOURCE + "' lists '" + identifier + "' more than once.");
            }

            loadedIds.add(identifier);
        }

        this.ids = List.copyOf(loadedIds);
        this.labelsById = Map.copyOf(loadedLabels);
    }

    private static Map<String, Object> readContractFile() {
        try (InputStream stream = new ClassPathResource(RESOURCE).getInputStream()) {
            return JsonParserFactory.getJsonParser()
                    .parseMap(new String(stream.readAllBytes(), StandardCharsets.UTF_8));
        } catch (IOException exception) {
            // A packaging fault, not a runtime condition to degrade around.
            throw new IllegalStateException(
                    "Packaged contract resource '" + RESOURCE + "' could not be "
                            + "read. The build must include the contract directory; see pom.xml.",
                    exception);
        }
    }

    public int schemaVersion() {
        return schemaVersion;
    }

    /** Every governed identifier, in the order the contract file lists them. */
    public List<String> ids() {
        return ids;
    }

    /**
     * Whether a value is part of the governed vocabulary. Matching is case-sensitive: the identifiers
     * are fixed machine-readable keys, never display text.
     */
    public boolean isGoverned(String value) {
        return value != null && labelsById.containsKey(value);
    }

    /**
     * The canonical display label in the requested locale, falling back to the more general language
     * ({@code fr-CA} to {@code fr}), then to English, then to the identifier itself.
     *
     * <p>Labels are maintained centrally in the contract file rather than translated per instance, so the
     * same relationship reads the same way regardless of which org issued it.
     */
    public String label(String id, String locale) {
        Map<String, String> labels = labelsById.get(id);
        if (labels == null) {
            return id;
        }

        for (String candidate : localeFallbacks(locale)) {
            String label = labels.get(candidate);
            if (label != null) {
                return label;
            }
        }

        return labels.getOrDefault("en", id);
    }

    /** Shared with the UI string lookup, which needs the same fallback chain. */
    public static List<String> localeFallbacks(String locale) {
        if (locale == null || locale.isBlank()) {
            return List.of();
        }

        // Java language tags use '-'; a Locale rendered with toString() uses '_'. Accept either.
        String normalised = locale.replace('_', '-');
        int separator = normalised.indexOf('-');

        return separator > 0 ? List.of(normalised, normalised.substring(0, separator)) : List.of(normalised);
    }
}
