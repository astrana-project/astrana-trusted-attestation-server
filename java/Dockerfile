# Built from the repository root as context (so shared/ is in scope):
#   docker build -f java/Dockerfile -t astrana/ata-java .
#
# Build
FROM maven:3.9-eclipse-temurin-25 AS build
WORKDIR /src

COPY java/pom.xml java/
RUN mvn -B -q -f java/pom.xml dependency:go-offline

# The contract, schema and interface inputs are packaged as resources from ../shared, so they have to
# be present before the build, as does the program in java/build that writes the third-party notices.
COPY shared/ shared/
COPY java/build/ java/build/
COPY java/src/ java/src/
RUN mvn -B -q -f java/pom.xml -DskipTests package

# Run
FROM eclipse-temurin:25-jre
WORKDIR /app
COPY --from=build /src/java/target/trusted-attestation-server-*.jar app.jar
# The third-party notices, served from the jar, also at a fixed path beside it.
COPY --from=build /src/java/target/classes/static/THIRD-PARTY-NOTICES.txt THIRD-PARTY-NOTICES.txt

# No port is exposed by default. Astrana Trusted Attestation refuses to start without TLS, so the
# operator has to choose, either configure server.ssl.* here, or set
# TRUSTED_ATTESTATION_TLS_TERMINATED_BY_PROXY=true and put a TLS-terminating proxy in front.
#
# The daily audit prune runs inside this same process. There is no second service to deploy.
RUN useradd --system --uid 10001 ata
USER ata
ENTRYPOINT ["java", "-jar", "app.jar"]
