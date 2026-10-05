package org.astrana.trustedattestation.web;

import org.astrana.trustedattestation.manifest.ManifestDocument;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.RestController;

@RestController
public class ManifestController {

    private final ManifestDocument manifest;

    public ManifestController(ManifestDocument manifest) {
        this.manifest = manifest;
    }

    /**
     * A single, permanently stable path -- never versioned. That is the point of a well-known discovery
     * document: a client never has to guess a version to find it. The {@code manifest_version} field
     * inside is what tells a consumer how to parse the content.
     */
    @GetMapping("/.well-known/ata-manifest.json")
    public ManifestDocument manifest() {
        return manifest;
    }
}
