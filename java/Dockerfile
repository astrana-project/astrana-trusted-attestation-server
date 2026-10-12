# Built from the repository root as context (so shared/ is in scope):
#   docker build -f java/Dockerfile -t astrana/ata-java .
#
# Build, on the processor of the computer doing the build, so that an image for another processor is
# not compiled under emulation. The jar runs on any processor.
# The digest pins the exact base image, and Dependabot moves it when the tag gets a new image.
FROM --platform=$BUILDPLATFORM maven:3.9-eclipse-temurin-25@sha256:93b8a14ea2f412782e4e842651273b4d903e35cc496284f178fbbe2d67d00976 AS build
WORKDIR /src

COPY java/pom.xml java/
RUN mvn -B -q -f java/pom.xml dependency:go-offline

# The contract, schema and interface inputs are packaged as resources from ../shared, so they have to
# be present before the build, as does the program in java/build that writes the third-party notices.
COPY shared/ shared/
COPY java/build/ java/build/
COPY java/src/ java/src/
RUN mvn -B -q -f java/pom.xml -DskipTests package

# Java runtime
# A Java runtime holding only the modules the server uses, linked with jlink from Eclipse Temurin's Java
# development kit. This stage runs on the processor the image is for, because the runtime it links is native
# code for that processor. The modules are the ones jdeps finds in the executable jar's classes and libraries,
# and jlink adds the modules those need in turn. The server's pages take their words from its own strings file
# in every language, not from Java's locale data, so jdk.localedata is left out. The runtime keeps each module's
# licence and notice files in legal/, and the certificate authorities Java trusts in lib/security/cacerts.
# The digest pins the exact base image, and Dependabot moves it when the tag gets a new image.
FROM eclipse-temurin:25-jdk@sha256:8c0a84ea11c8f6ed52600fc19f1040121f2a162998e9f50a5faebbbad9172dcc AS runtime
RUN jlink \
        --add-modules java.base,java.compiler,java.desktop,java.instrument,java.management,java.net.http \
        --add-modules java.prefs,java.rmi,java.scripting,java.security.jgss,java.sql.rowset,java.xml.crypto \
        --add-modules jdk.jfr,jdk.net,jdk.unsupported \
        --strip-debug --no-man-pages --no-header-files --compress=zip-6 \
        --output /runtime \
    && cp "$JAVA_HOME/NOTICE" /runtime/NOTICE

# Locale
# The C.UTF-8 locale, from the same Debian release and C library the image below is built from. Without it
# Java reads environment variables, file names and its console output as ASCII.
# The digest pins the exact base image, and Dependabot moves it when the tag gets a new image.
FROM debian:trixie-slim@sha256:a29215f6a35e51e22adffa17f89e9d2ef06214e64a2bad10d765c46aea49f11f AS locale

# Run
# Google's distroless base image, built from Debian 13 packages. It holds the C library the runtime above
# needs, time zone data and little else, with no shell and no package manager.
# The digest pins the exact base image, and Dependabot moves it when the tag gets a new image.
FROM gcr.io/distroless/base-nossl-debian13:latest@sha256:af5cb8dd589b8520b8c06bebb9efb73d7e16406cab58e85c51761fff49d370a0
ENV JAVA_HOME=/opt/java/openjdk
ENV PATH="$JAVA_HOME/bin:$PATH" \
    LANG=C.UTF-8
COPY --from=locale /usr/lib/locale/C.utf8 /usr/lib/locale/C.utf8
COPY --from=runtime /runtime $JAVA_HOME

WORKDIR /app
COPY --from=build /src/java/target/trusted-attestation-server-*.jar app.jar
# The third-party notices, served from the jar, also at a fixed path beside it.
COPY --from=build /src/java/target/classes/static/THIRD-PARTY-NOTICES.txt THIRD-PARTY-NOTICES.txt

# No port is exposed by default. Astrana Trusted Attestation refuses to start without TLS, so the
# operator has to choose, either configure server.ssl.* here, or set
# TRUSTED_ATTESTATION_TLS_TERMINATED_BY_PROXY=true and put a TLS-terminating proxy in front.
#
# The daily audit prune runs inside this same process. There is no second service to deploy.
#
# The image has no shell to add a user with, so it runs as a numeric user and group that are not root.
# Being named by number also lets a platform that refuses to run containers as root, such as Kubernetes
# with runAsNonRoot, check it.
USER 10001:10001
ENTRYPOINT ["java", "-jar", "app.jar"]
