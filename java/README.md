# Astrana Trusted Attestation Server, Java implementation

This is the Java implementation of the Astrana Trusted Attestation Server, built on Spring Boot and Java 25. It behaves
exactly like the .NET and PHP implementations on everything the contract and the shared interface define.

- To install, configure and run it, read [the Java installation page](../docs/installation/java.md).
- For what every implementation must serve, read the contract under [shared/contract](../shared/contract) and the schema
  files under [shared/schema](../shared/schema).
- For why it is the way it is, read [the architecture decision records](../docs/adr/README.md).
- For how to contribute, read [CONTRIBUTING.md](../CONTRIBUTING.md) at the repository root.

## What is here

| Path                                  | Contents                                                                                                                                                  |
| ------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/main`                            | The application, with its default configuration in `src/main/resources/application.yml` and the development profiles beside it, which the jar leaves out  |
| `src/test`                            | The unit tests                                                                                                                                            |
| `dev`                                 | The throwaway TLS keystore for the `dev` profile, generated locally, git-ignored and never packaged into the jar                                          |
| `saml`                                | The development SAML signing certificate and key for the `dev-saml` profile and the integration matrix, generated locally, git-ignored and never packaged |
| `pom.xml`, `mvnw`, `mvnw.cmd`, `.mvn` | The Maven build and its wrapper                                                                                                                           |
| `build`                               | The program the build runs to write the third-party notices, described below                                                                              |
| `Dockerfile`                          | The container image, built from the repository root                                                                                                       |
| `demo`                                | The demonstration stack, described in [its own README](demo/README.md) and [the quick start](../docs/quickstart.md)                                       |
| `CHANGELOG.md`, `sbom.json`           | This implementation's changelog and software bill of materials                                                                                            |

## Prerequisites

A Java 25 development kit, or only Docker. You need Docker anyway for the demonstration stack and the shared suites.

## Build and test

From this folder, with a development kit installed:

```bash
./mvnw -B -ntp test
```

Or without one, from the repository root, in a Maven container that keeps its downloads in a named volume:

```bash
docker run --rm -v "$PWD":/src -w /src/java -v ata-m2:/root/.m2 maven:3.9-eclipse-temurin-25 mvn -B -ntp test
```

In Git Bash on Windows, start the command with `MSYS_NO_PATHCONV=1` and write `"$(pwd -W)"` in place of `"$PWD"`, or Git
Bash rewrites `/src/java` as a Windows path.

`./mvnw -DskipTests package` produces `target/trusted-attestation-server-<version>.jar`, such as
`trusted-attestation-server-1.0.0.jar`, with no development configuration in it. The unit tests cover this
implementation's own code. The conformance, differential and accessibility suites that define parity run against all
three implementations at once, as [CONTRIBUTING.md](../CONTRIBUTING.md) describes under Building and testing locally.

## Run it locally

The server refuses to run without TLS, a database, an identity and access management system and a valid manifest. The
quickest way to run it on your machine is the demonstration stack, which supplies all four. From this folder:

```bash
docker compose -f demo/docker-compose.yml up --build
```

Then open https://localhost:16443. [The quick start](../docs/quickstart.md) walks through what the demonstration holds
and what to try. To run the jar directly, give it a configuration file of your own with
`--spring.config.additional-location`, as the installation page describes.

### The development profiles

The `dev` profile runs the server from source on https://localhost:16443. It uses the shared development environment,
which is Keycloak on port 8081 and PostgreSQL on port 55432. Its files, `application-dev.yml` and
`application-dev-saml.yml`, stay in `src/main/resources`. The Maven build leaves them out of the jar, and
`spring-boot:run` reads them from that folder. Start the environment from the repository root:

```bash
docker compose -f shared/test/docker-compose.yml --profile postgres up -d
```

The profile's files hold no credentials. Generate the throwaway TLS keystore once, with a password you choose, and write
the credentials to `dev/secrets.yml`, which the `dev` profile reads and Git ignores. From this folder:

```bash
password=choose-a-password
mkdir -p dev
keytool -genkeypair -alias trusted-attestation-dev -keyalg RSA -keysize 2048 -validity 365 \
  -dname "CN=localhost" -ext "SAN=dns:localhost,ip:127.0.0.1" \
  -keystore dev/dev-keystore.p12 -storetype PKCS12 -storepass "$password"
cat > dev/secrets.yml <<EOF
server.ssl.key-store-password: $password
spring.datasource.password: trusted-attestation-dev-password
spring.security.oauth2.client.registration.keycloak.client-secret: trusted-attestation-dev-secret
EOF
```

The database password and the client secret belong to the throwaway containers the shared development environment
starts, and work nowhere else.

Then run it from this folder:

```bash
./mvnw spring-boot:run -Dspring-boot.run.profiles=dev
```

The `dev-saml` profile signs in over SAML instead. Generate its signing certificate and key once, from this folder, then
run with both profiles. `MSYS_NO_PATHCONV=1` at the start of the `openssl` command stops Git Bash on Windows rewriting
`/CN=...` as a Windows path, and does nothing elsewhere.

```bash
mkdir -p saml
MSYS_NO_PATHCONV=1 openssl req -x509 -newkey rsa:2048 -nodes -days 365 -subj "/CN=trusted-attestation-dev-sp" \
  -keyout saml/sp-private.key -out saml/sp-certificate.crt
./mvnw spring-boot:run -Dspring-boot.run.profiles=dev,dev-saml
```

The members are `alice`, `bob`, `carol` and `dave`, each with the password `password`. A member has no relationships
until you grant one by calling `grant_member_relationship` in the database.

## Shared inputs

The Maven build packages the contract, the schema files, the interface strings, the stylesheet, the favicon and the
theme override file in `../shared` as resources. Nothing is copied into the source tree. Edit the files in `../shared`,
never a packaged copy, and remember that a change there changes all three implementations. The packaged stylesheet is
the compiled `shared/ui/dist/trusted-attestation.css`. Edit its source in `shared/ui/src` and rebuild it as
[the shared interface's README](../shared/ui/README.md) describes.

## Container image

Each release is published on Docker Hub as `astrana/ata-java`, tagged with its full version, its major and minor version
and `latest`, for 64-bit Intel and AMD processors (amd64) only. To build the image yourself, run this from the
repository root, so that `shared/` is in the build context:

```bash
docker build -f java/Dockerfile -t astrana/ata-java .
```

The image holds the application only, runs as a non-root user and listens on port 8080. Configuration comes from a file
mounted at `/app/config/application.yaml` or from environment variables, as the installation page describes. The jar
leaves out Spring Boot's jar tools, which extract its layers, because the image runs the jar as it is.

## Third-party notices

Every build writes `THIRD-PARTY-NOTICES.txt` into the jar's static resources, from the dependencies the build resolved.
The server serves it at `/THIRD-PARTY-NOTICES.txt`, and the licence page links to it. The image also holds a copy at
`/app/THIRD-PARTY-NOTICES.txt`.

For each jar the executable jar carries, the file gives the version, the licence, the supplier and where to get the
source. Then comes every licence and notice file the jar ships. Where a jar names a licence without shipping its text,
the text comes from `../shared/third-party/licences`. A component offered under a choice of licences is listed under the
first its project names. Bootstrap's notice, for the compiled stylesheet, comes from `../shared/third-party/bundled`.

The writer is `build/ThirdPartyNotices.java`, a single-file program the Maven build runs with the Java development kit
alone. A jar with no licence text fails the build. To fix it, add a line naming its licence to
`../shared/third-party/component-facts.tsv`, and add the licence text to the licences folder if the jar ships none.

## Changelog and bill of materials

`CHANGELOG.md` follows Keep a Changelog, with no Unreleased section, as
[decision record 38](../docs/adr/0038-shared-feature-versions-independent-patches-release-on-merge.md) describes.
`sbom.json` is a CycloneDX software bill of materials generated from the Maven dependency tree. When a dependency
changes, regenerate it from this folder, copy the result over the committed file, then complete it:

```bash
./mvnw org.cyclonedx:cyclonedx-maven-plugin:2.9.1:makeBom -DoutputName=sbom -DoutputFormat=json
cp target/sbom.json sbom.json
python3 ../scripts/complete-sbom.py java
```

The last command completes what the plugin leaves out. It adds Bootstrap, at the version `../shared/ui/package.json`
pins, and Spring Boot's loader, whose classes the build copies into the jar. It also sets the licence of each dependency
listed in `../shared/third-party/component-facts.tsv`, such as the MySQL driver, whose project states its licence in
words rather than as a Software Package Data Exchange (SPDX) expression. Running it twice changes nothing.
