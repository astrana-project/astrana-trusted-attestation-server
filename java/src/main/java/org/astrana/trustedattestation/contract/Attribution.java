package org.astrana.trustedattestation.contract;

import java.io.IOException;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.util.List;
import java.util.Map;
import org.springframework.boot.json.JsonParserFactory;
import org.springframework.core.io.ClassPathResource;
import org.springframework.stereotype.Component;

/**
 * The software's own attribution, loaded from the packaged copy of the contract's {@code attribution.json}:
 * the footer every page shows and the content of the licence page.
 *
 * <p>Record 33 in docs/adr requires it on every deployment and does not let the organisation running the
 * instance configure it away, so it is not in {@code TrustedAttestationProperties} at all. The same file is
 * imported by all three implementations, so the notice reads identically wherever it appears. Organisation
 * branding is a separate thing entirely, and comes from the manifest.
 */
@Component
public class Attribution {

    private static final String RESOURCE = "contract/attribution.json";

    private final String projectName;
    private final String projectUrl;
    private final String sourceUrl;
    private final String licenseNotice;
    private final String licenseLabel;
    private final String licenseUrl;
    private final List<String> licenseText;
    private final String trademarkNotice;

    public Attribution() {
        Map<String, Object> document;

        try (InputStream stream = new ClassPathResource(RESOURCE).getInputStream()) {
            document = JsonParserFactory.getJsonParser()
                    .parseMap(new String(stream.readAllBytes(), StandardCharsets.UTF_8));
        } catch (IOException exception) {
            throw new IllegalStateException(
                    "Packaged contract resource '" + RESOURCE + "' could not be "
                            + "read. The build must include the contract directory; see pom.xml.",
                    exception);
        }

        this.projectName = text(document.get("project_name"));
        this.projectUrl = text(document.get("project_url"));
        this.sourceUrl = text(document.get("source_url"));

        Object license = document.get("license");
        Map<?, ?> licenseMap = license instanceof Map<?, ?> map ? map : Map.of();
        this.licenseNotice = text(licenseMap.get("notice"));

        String label = text(licenseMap.get("label"));
        this.licenseLabel = label.isBlank() ? this.licenseNotice : label;

        String url = text(licenseMap.get("url"));
        this.licenseUrl = url.isBlank() ? null : url;

        Object paragraphs = licenseMap.get("text");
        this.licenseText = paragraphs instanceof List<?> list
                ? list.stream().map(Attribution::text).toList()
                : List.of();
        this.trademarkNotice = text(licenseMap.get("trademark_notice"));

        if (projectName.isBlank() || projectUrl.isBlank()) {
            throw new IllegalStateException(
                    "Packaged contract resource '" + RESOURCE + "' must name the project and link to it.");
        }
    }

    public String projectName() {
        return projectName;
    }

    public String projectUrl() {
        return projectUrl;
    }

    /** Where the source code and documentation live. */
    public String sourceUrl() {
        return sourceUrl;
    }

    /** The one-line notice: the copyright line and the licence, shown first on the licence page. */
    public String licenseNotice() {
        return licenseNotice;
    }

    /**
     * The short text the footer links with (for example, "Licence"), kept separate from the fuller
     * {@link #licenseNotice()}. Falls back to the notice when the contract file sets no label, so a footer
     * is never empty.
     */
    public String licenseLabel() {
        return licenseLabel;
    }

    /** Where the footer's licence link goes. Null renders the label as plain text. */
    public String licenseUrl() {
        return licenseUrl;
    }

    /** The licence text, one entry per paragraph, as the licence page shows it. */
    public List<String> licenseText() {
        return licenseText;
    }

    /** The trademark notice the licence page shows after the licence text. */
    public String trademarkNotice() {
        return trademarkNotice;
    }

    private static String text(Object value) {
        return value == null ? "" : String.valueOf(value);
    }
}
