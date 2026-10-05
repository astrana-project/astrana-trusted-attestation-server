package org.astrana.trustedattestation.manifest;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.Base64;
import java.util.Map;
import java.util.regex.Pattern;

/**
 * Resolves the configured logo into the data URI the manifest carries.
 *
 * <p>The manifest field is always an embedded data URI, so that rendering an organisation's branding needs
 * no second request to the organisation. Configuration accepts either that, or a path to an image file
 * which is read and encoded at startup. Pasting a base64 blob into a YAML file is unpleasant enough that
 * operators would be tempted to skip the logo entirely.
 */
public final class LogoData {

    /**
     * A base64 image data URI. It refuses a URL, because a link here would put the request back on the
     * organisation's server, which is exactly what embedding the image avoids.
     */
    public static final Pattern PATTERN =
            Pattern.compile("^data:image/(png|jpeg|gif|webp|svg\\+xml);base64,[A-Za-z0-9+/]+={0,2}$");

    private static final Map<String, String> MEDIA_TYPES = Map.of(
            "png", "image/png",
            "jpg", "image/jpeg",
            "jpeg", "image/jpeg",
            "gif", "image/gif",
            "webp", "image/webp",
            "svg", "image/svg+xml");

    private LogoData() {}

    /**
     * @param setting the configuration key the value came from, named in a refusal so the operator knows
     *     which of the two logos to fix
     * @param configured a data URI, a path, or nothing
     */
    public static String resolve(String setting, String configured) {
        if (configured == null || configured.isBlank()) {
            return null;
        }

        String value = configured.strip();

        if (value.regionMatches(true, 0, "data:", 0, 5)) {
            return value;
        }

        Path path = Path.of(value);
        if (!Files.isRegularFile(path)) {
            throw new IllegalStateException("The configured logo '" + path.toAbsolutePath() + "' does not exist. "
                    + setting + " takes either an image file path or a data URI.");
        }

        String extension = extensionOf(path);
        String mediaType = MEDIA_TYPES.get(extension);
        if (mediaType == null) {
            throw new IllegalStateException("The configured logo '" + path + "' has an unsupported " + "extension '"
                    + extension + "'. Supported: " + MEDIA_TYPES.keySet() + ".");
        }

        try {
            return "data:" + mediaType + ";base64," + Base64.getEncoder().encodeToString(Files.readAllBytes(path));
        } catch (IOException exception) {
            throw new IllegalStateException("The configured logo '" + path + "' could not be read.", exception);
        }
    }

    private static String extensionOf(Path path) {
        String name = path.getFileName().toString();
        int dot = name.lastIndexOf('.');

        return dot < 0 ? "" : name.substring(dot + 1).toLowerCase(java.util.Locale.ROOT);
    }
}
