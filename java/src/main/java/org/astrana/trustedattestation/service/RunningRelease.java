package org.astrana.trustedattestation.service;

import org.springframework.boot.info.BuildProperties;

/**
 * The release this server is running, which the self-service page shows in its footer and no other page does
 * (decision record 46 in docs/adr). The version is the one the build records from pom.xml in
 * META-INF/build-info.properties, which is the jar's version and the top heading of CHANGELOG.md.
 *
 * @param version the full version, such as 1.0.1
 */
public record RunningRelease(String version) {

    /** Where the version links to. Fixed, so that no operator can point members somewhere else. */
    public static final String RELEASES_URL = "https://www.astrana.org/releases";

    /**
     * The release the build recorded.
     *
     * @param build what the build recorded, or null when META-INF/build-info.properties is missing
     * @throws IllegalStateException when the record is missing, because then the version is unknown
     */
    public static RunningRelease of(BuildProperties build) {
        if (build == null) {
            throw new IllegalStateException("META-INF/build-info.properties is missing, so the server cannot tell"
                    + " which release it is. Build the server with Maven, which writes that file from pom.xml, and"
                    + " start it from that build.");
        }
        return new RunningRelease(build.getVersion());
    }

    /** The version as the footer writes it, such as v1.0.1. */
    public String label() {
        return "v" + version;
    }

    public String releasesUrl() {
        return RELEASES_URL;
    }
}
