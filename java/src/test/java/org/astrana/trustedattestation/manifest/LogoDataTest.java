package org.astrana.trustedattestation.manifest;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.Base64;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

/**
 * How the configured logo becomes the data URI the manifest carries.
 *
 * <p>This runs once at startup and its failures stop the application, since a configured logo that does
 * not resolve is a misconfiguration. The refusal messages matter as much as the behaviour. The
 * application is not running when they appear, so the text is the entire diagnosis. The .NET and PHP
 * implementations have the same tests for the same reasons.
 */
class LogoDataTest {

    private static final String SETTING = "trusted-attestation.manifest.logo-data";

    @TempDir
    Path root;

    private Path write(String name, byte[] bytes) throws IOException {
        Path path = root.resolve(name);
        Files.write(path, bytes);
        return path;
    }

    // ---------------------------------------------------------------------------------------------
    // The three configured shapes
    // ---------------------------------------------------------------------------------------------

    @Test
    void noLogoConfiguredIsSimplyNoLogo() {
        // Absent is an ordinary state, not an error: the manifest field is optional and the page falls
        // back to the organisation's name.
        assertThat(LogoData.resolve(SETTING, null)).isNull();
        assertThat(LogoData.resolve(SETTING, "")).isNull();
        assertThat(LogoData.resolve(SETTING, "   ")).isNull();
    }

    @Test
    void aDataUriPassesThroughUntouched() {
        String uri = "data:image/png;base64,iVBORw0KGgo=";

        assertThat(LogoData.resolve(SETTING, uri)).isEqualTo(uri);
    }

    @Test
    void aFileBecomesItsBase64WithTheRightMediaType() throws IOException {
        byte[] bytes = {1, 2, 3};

        String resolved = LogoData.resolve(SETTING, write("logo.png", bytes).toString());

        assertThat(resolved)
                .isEqualTo("data:image/png;base64," + Base64.getEncoder().encodeToString(bytes));
    }

    @Test
    void theExtensionDecidesTheMediaTypeCaseInsensitively() throws IOException {
        // Filesystems hand back whatever casing the file was created with, and a logo exported as
        // LOGO.PNG must not become an unsupported-extension refusal.
        String resolved =
                LogoData.resolve(SETTING, write("LOGO.PNG", new byte[] {9}).toString());

        assertThat(resolved).startsWith("data:image/png;");
    }

    // ---------------------------------------------------------------------------------------------
    // The refusals, and what they tell the operator
    // ---------------------------------------------------------------------------------------------

    @Test
    void aPathThatDoesNotExistRefusesAndNamesThePathItTried() {
        // The absolute path, not the configured value: the difference between the two is usually the
        // entire bug, as the mutation harness's reload script proved when MSYS mangled a path on the
        // way in and the message was the only evidence of what the app had actually been handed.
        String missing = root.resolve("missing.png").toString();

        assertThatThrownBy(() -> LogoData.resolve(SETTING, missing))
                .isInstanceOf(IllegalStateException.class)
                .hasMessageContaining("missing.png")
                .hasMessageContaining("logo-data");
    }

    @Test
    void aMissingDarkLogoNamesTheDarkLogoSetting() {
        // Two settings take a logo, and the refusal names the one that was given, so an operator fixing
        // a dark logo is not sent to the light one.
        String missing = root.resolve("missing-dark.png").toString();

        assertThatThrownBy(() -> LogoData.resolve("trusted-attestation.manifest.logo-data-dark", missing))
                .isInstanceOf(IllegalStateException.class)
                .hasMessageContaining("trusted-attestation.manifest.logo-data-dark takes");
    }

    @Test
    void anUnsupportedExtensionRefusesAndListsWhatIsSupported() throws IOException {
        Path bmp = write("logo.bmp", new byte[] {1});

        assertThatThrownBy(() -> LogoData.resolve(SETTING, bmp.toString()))
                .isInstanceOf(IllegalStateException.class)
                .hasMessageContaining("bmp")
                .hasMessageContaining("png");
    }

    @Test
    void aFileWithNoExtensionRefusesRatherThanGuessing() throws IOException {
        // Sniffing the bytes would mean this implementation deciding what an image is; naming the file
        // properly is the operator's one-line fix, and the message says which file.
        Path bare = write("logo", new byte[] {1});

        assertThatThrownBy(() -> LogoData.resolve(SETTING, bare.toString()))
                .isInstanceOf(IllegalStateException.class)
                .hasMessageContaining("logo");
    }

    // ---------------------------------------------------------------------------------------------
    // The pattern the validator holds manifests to
    // ---------------------------------------------------------------------------------------------

    @Test
    void thePatternAcceptsAnEmbeddedImage() {
        assertThat(LogoData.PATTERN
                        .matcher("data:image/png;base64,iVBORw0KGgo=")
                        .matches())
                .isTrue();
        assertThat(LogoData.PATTERN
                        .matcher("data:image/svg+xml;base64,PHN2Zy8+")
                        .matches())
                .isTrue();
    }

    @Test
    void thePatternRefusesAUrlANonImageAndGarbage() {
        // The URL case is the point of the field existing: a link would put every consumer's request
        // back on the organisation's server, which is the side-channel embedding avoids. The text/html
        // case is what stops a manifest smuggling something a careless consumer might render.
        assertThat(LogoData.PATTERN.matcher("https://example.org/logo.png").matches())
                .isFalse();
        assertThat(LogoData.PATTERN
                        .matcher("data:text/html;base64,PHNjcmlwdC8+")
                        .matches())
                .isFalse();
        assertThat(LogoData.PATTERN.matcher("data:image/png;base64,not!!base64").matches())
                .isFalse();
    }

    @Test
    void whatResolveProducesIsWhatThePatternAccepts() throws IOException {
        // The two halves of this class are used apart -- resolve at startup, PATTERN in the manifest
        // validator -- and nothing else guarantees they agree. A media type added to one and not the
        // other would mean the app builds a manifest its own validator rejects.
        String resolved =
                LogoData.resolve(SETTING, write("logo.webp", new byte[] {5, 6}).toString());

        assertThat(resolved).isNotNull();
        assertThat(LogoData.PATTERN.matcher(resolved).matches()).isTrue();
    }
}
