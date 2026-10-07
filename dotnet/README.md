# Astrana Trusted Attestation Server, .NET implementation

This is the .NET implementation of the Astrana Trusted Attestation Server, built on ASP.NET Core 10. It behaves exactly
like the Java and PHP implementations on everything the contract and the shared interface define.

- To install, configure and run it, read [the .NET installation page](../docs/installation/dotnet.md).
- For what every implementation must serve, read the contract under [shared/contract](../shared/contract) and the schema
  files under [shared/schema](../shared/schema).
- For why it is the way it is, read [the architecture decision records](../docs/adr/README.md).
- For how to contribute, read [CONTRIBUTING.md](../CONTRIBUTING.md) at the repository root.

## What is here

| Path                                            | Contents                                                                                                            |
| ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| `src/Astrana.TrustedAttestation.Server`         | The application                                                                                                     |
| `tests/Astrana.TrustedAttestation.Server.Tests` | The unit tests                                                                                                      |
| `Astrana.TrustedAttestation.slnx`               | The solution, used by every build and test command below                                                            |
| `build`                                         | The build step that writes the third-party notices, described below                                                 |
| `Dockerfile`                                    | The container image, built from the repository root                                                                 |
| `demo`                                          | The demonstration stack, described in [its own README](demo/README.md) and [the quick start](../docs/quickstart.md) |
| `global.json`                                   | The .NET software development kit version the build uses                                                            |
| `coverage.runsettings`                          | Coverage settings the SonarQube Cloud workflow passes to the test run                                               |
| `CHANGELOG.md`, `sbom.json`                     | This implementation's changelog and software bill of materials                                                      |

## Prerequisites

The .NET 10 software development kit, and Docker for the demonstration stack and the shared suites.

## Build and test

From this folder:

```bash
dotnet build Astrana.TrustedAttestation.slnx
dotnet test Astrana.TrustedAttestation.slnx
```

The unit tests cover this implementation's own code. The conformance, differential and accessibility suites that define
parity run against all three implementations at once, as [CONTRIBUTING.md](../CONTRIBUTING.md) describes under Building
and testing locally.

## Run it locally

The server refuses to run without TLS, an identity and access management system, a database and a valid manifest. The
quickest way to run it on your machine is the demonstration stack, which supplies all four. Its database is Microsoft's
free SQL Server Developer edition, and by starting the stack you accept the
[SQL Server Developer licence terms](https://go.microsoft.com/fwlink/?linkid=857698). From this folder:

```bash
docker compose -f demo/docker-compose.yml up --build
```

Then open https://localhost:15443. [The quick start](../docs/quickstart.md) walks through what the demonstration holds
and what to try.

To run the application directly with `dotnet run`, first start the shared development environment from the repository
root. It provides the Keycloak realm on port 8081 and PostgreSQL on port 55432, which `appsettings.Development.json`
points at:

```bash
docker compose -f shared/test/docker-compose.yml --profile postgres up -d
```

The settings file holds no credentials. Give the application the development environment's database connection and
client secret once, as user secrets, which are kept in your user profile outside the repository. From
`src/Astrana.TrustedAttestation.Server`:

```bash
dotnet user-secrets set "TrustedAttestation:Database:ConnectionString" "Host=localhost;Port=55432;Database=trusted_attestation;Username=trusted_attestation;Password=trusted-attestation-dev-password"
dotnet user-secrets set "TrustedAttestation:Iam:ClientSecret" "trusted-attestation-dev-secret"
```

These values belong to the throwaway containers the shared development environment starts, and work nowhere else.

Then, from the same folder, trust the development certificate once with `dotnet dev-certs https --trust` and run
`dotnet run`. The server answers at https://localhost:15443, the port the demonstration stack and the integration stack
also use, so stop them first.

To try SAML, set `TrustedAttestation__Iam__Protocol=Saml`. You generate the development signing key `saml/sp-dev.pfx`
locally, and Git ignores it. Its password is `changeit`, because the local identity provider matrix opens the same file
with that password. Put the password in user secrets too, as the last command below does. `MSYS_NO_PATHCONV=1` at the
start of the first `openssl` command stops Git Bash on Windows rewriting `/CN=...` as a Windows path, and does nothing
elsewhere. From this folder:

```bash
saml=src/Astrana.TrustedAttestation.Server/saml
mkdir -p $saml
MSYS_NO_PATHCONV=1 openssl req -x509 -newkey rsa:2048 -nodes -days 365 -subj "/CN=trusted-attestation-dev-sp" -keyout $saml/k.pem -out $saml/c.pem
openssl pkcs12 -export -inkey $saml/k.pem -in $saml/c.pem -out $saml/sp-dev.pfx -passout pass:changeit
dotnet user-secrets --project src/Astrana.TrustedAttestation.Server set "TrustedAttestation:Iam:Saml:SigningCertificatePassword" "changeit"
```

## Shared inputs

The build embeds the relationship types and attribution from the contract, the schema files and the interface strings
from `../shared` as resources. A build target copies the stylesheet, favicon and theme override file into `wwwroot`. Git
ignores the copies. Edit the files in `../shared`, never the copies, and remember that a change there changes all three
implementations.

## Container image

Each release is published on Docker Hub as `astrana/ata-dotnet`, tagged with its full version, its major and minor
version and `latest`, for 64-bit Intel and AMD processors (amd64) only. To build the image yourself, run this from the
repository root, so that `shared/` is in the build context:

```bash
docker build -f dotnet/Dockerfile -t astrana/ata-dotnet .
```

The image holds the application only. It is built for Linux on the processor the image is for, which is amd64 for the
published image, so the native files the packages carry for other systems and processors stay out of it. It runs as a
non-root user and listens on port 8080. Configuration comes from environment variables or a mounted `appsettings.json`,
as the installation page describes.

The SQL Server driver brings in Microsoft's Windows sign-in broker package, `Microsoft.Identity.Client.NativeInterop`,
whose licence does not allow it to be distributed. The server signs in to SQL Server with a username and password and
never uses the broker. So the project file leaves all of the package's files out of every build, the managed assembly
and the native runtime libmsalruntime alike.

## Third-party notices

Every build writes `THIRD-PARTY-NOTICES.txt` into `wwwroot`, from the packages the build restored. The server serves it
at `/THIRD-PARTY-NOTICES.txt`, and the licence page links to it. The image also holds a copy at
`/app/THIRD-PARTY-NOTICES.txt`.

For each package whose files ship, the file gives the version, the licence, the copyright notice and where to get the
source. Then comes every licence and notice text the package ships. Where a package names a licence without shipping its
text, the text comes from `../shared/third-party/licences`. Bootstrap's notice, for the compiled stylesheet, comes from
`../shared/third-party/bundled`.

The writer is `build/ThirdPartyNotices.cs`, which MSBuild runs through `build/ThirdPartyNotices.targets`. A package with
no licence text fails the build. To fix it, add a line naming its licence to
`../shared/third-party/component-facts.tsv`, and add the licence text to the licences folder if the package ships none.
Git ignores the written file.

## Changelog and bill of materials

`CHANGELOG.md` follows Keep a Changelog, with no Unreleased section, as
[decision record 44](../docs/adr/0044-version-numbers-say-what-an-update-means.md) describes. `sbom.json` is a CycloneDX
software bill of materials generated from the project's package references by the CycloneDX .NET tool, installed with
`dotnet tool install --global CycloneDX`. Regenerate it from this folder whenever a dependency or the version changes.
Set `--set-version` to the version of the latest entry in `CHANGELOG.md`:

```bash
dotnet-CycloneDX Astrana.TrustedAttestation.slnx -o . -fn sbom.json -t -ed -ns --set-version 1.0.0
python3 ../scripts/complete-sbom.py dotnet
```

The second command completes what the tool leaves out. It adds Bootstrap, at the version `../shared/ui/package.json`
pins. It also sets the licence of each package listed in `../shared/third-party/component-facts.tsv`, because some
packages give only a web address for their licence, and the tool records the MySQL drivers' licence without its
exception. Running it twice changes nothing.
