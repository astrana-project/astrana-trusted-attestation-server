package org.astrana.trustedattestation.manifest;

import static org.assertj.core.api.Assertions.assertThat;

import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.databind.PropertyNamingStrategies;
import com.fasterxml.jackson.databind.json.JsonMapper;
import com.fasterxml.jackson.databind.node.ObjectNode;
import java.io.IOException;
import java.nio.file.Path;
import java.util.List;
import java.util.stream.Stream;
import java.util.stream.StreamSupport;
import org.astrana.trustedattestation.contract.RelationshipTypeCatalog;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.Arguments;
import org.junit.jupiter.params.provider.MethodSource;

/**
 * The manifests in {@code shared/test-material/invalid-manifests.json}, which all three implementations'
 * validators must refuse, so that the three refuse the same ones. Tests run from the module directory,
 * so the file is read from {@code ../shared}.
 */
class SharedInvalidManifestsTest {

    private static final ObjectMapper JSON = JsonMapper.builder()
            .propertyNamingStrategy(PropertyNamingStrategies.SNAKE_CASE)
            .build();

    private static final JsonNode MATERIAL = read();

    private final RelationshipTypeCatalog catalog = new RelationshipTypeCatalog();

    @Test
    void theSharedValidManifestIsAccepted() throws IOException {
        assertThat(ManifestValidator.validate(document(MATERIAL.get("valid")), catalog))
                .isEmpty();
    }

    @ParameterizedTest(name = "{0}")
    @MethodSource("invalidCases")
    void eachSharedInvalidManifestIsRefusedForItsOwnReason(String description, JsonNode invalid) throws IOException {
        ObjectNode manifest = MATERIAL.get("valid").deepCopy();
        invalid.path("set").properties().forEach(field -> manifest.set(field.getKey(), field.getValue()));
        invalid.path("remove").forEach(field -> manifest.remove(field.asText()));
        List<String> expected = StreamSupport.stream(invalid.get("error").spliterator(), false)
                .map(JsonNode::asText)
                .toList();

        assertThat(ManifestValidator.validate(document(manifest), catalog))
                .anyMatch(error -> expected.stream().allMatch(error::contains));
    }

    static Stream<Arguments> invalidCases() {
        return StreamSupport.stream(MATERIAL.get("invalid").spliterator(), false)
                .map(invalid -> Arguments.of(invalid.get("case").asText(), invalid));
    }

    private static ManifestDocument document(JsonNode manifest) throws IOException {
        return JSON.treeToValue(manifest, ManifestDocument.class);
    }

    private static JsonNode read() {
        try {
            return JSON.readTree(Path.of("..", "shared", "test-material", "invalid-manifests.json")
                    .toFile());
        } catch (IOException e) {
            throw new IllegalStateException("could not read the shared invalid manifests", e);
        }
    }
}
