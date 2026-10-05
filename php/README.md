# Astrana Trusted Attestation Server, PHP implementation

This is the PHP implementation of the Astrana Trusted Attestation Server, built on Laravel and PHP 8.4.1 or later. It
behaves exactly like the .NET and Java implementations on everything the contract and the shared interface define.

- To install, configure and run it, read [the PHP installation page](../docs/installation/php.md).
- For what every implementation must serve, read the contract under [shared/contract](../shared/contract) and the schema
  files under [shared/schema](../shared/schema).
- For why it is the way it is, read [the architecture decision records](../docs/adr/README.md).
- For how to contribute, read [CONTRIBUTING.md](../CONTRIBUTING.md) at the repository root.

## What is here

| Path                                                                                 | Contents                                                                                                            |
| ------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------- |
| `app`, `config`, `routes`, `resources`, `public`, `bootstrap`, `database`, `storage` | The Laravel application                                                                                             |
| `tests`, `phpunit.xml`                                                               | The unit and feature tests                                                                                          |
| `scripts/sync-shared-assets.php`                                                     | Copies the shared inputs into this tree, run by Composer                                                            |
| `scripts/add-native-sbom-components.php`, `scripts/generate-third-party-notices.php` | Complete the bill of materials and write the third-party notices, as described below                                |
| `contract`, `schema`                                                                 | Copies of the shared contract and schema files, written by the script above and ignored by Git                      |
| `saml`                                                                               | The development SAML signing certificate and key, generated locally and ignored by Git                              |
| `Dockerfile`                                                                         | The container image, built from the repository root                                                                 |
| `demo`                                                                               | The demonstration stack, described in [its own README](demo/README.md) and [the quick start](../docs/quickstart.md) |
| `CHANGELOG.md`, `sbom.json`                                                          | This implementation's changelog and software bill of materials                                                      |

## Prerequisites

PHP 8.4.1 or later with Composer, and Docker for the demonstration stack and the shared suites. The unit and feature
tests use no database.

In the local identity provider matrix, the application signs its SAML requests with a throwaway certificate and key from
`saml`, which the matrix mounts into the container. Generate them once, from this folder, and never use them for a
deployment. `MSYS_NO_PATHCONV=1` at the start of the `openssl` command stops Git Bash on Windows rewriting `/CN=...` as
a Windows path, and does nothing elsewhere.

```bash
mkdir -p saml
MSYS_NO_PATHCONV=1 openssl req -x509 -newkey rsa:2048 -nodes -days 365 -subj "/CN=trusted-attestation-dev-sp" \
  -keyout saml/sp-private.key -out saml/sp-certificate.crt
```

## Build and test

From this folder:

```bash
composer install
vendor/bin/phpunit
```

Installing also runs `scripts/sync-shared-assets.php`, which copies the shared inputs into place. The unit and feature
tests cover this implementation's own code. The conformance, differential and accessibility suites that define parity
run against all three implementations at once, as [CONTRIBUTING.md](../CONTRIBUTING.md) describes under Building and
testing locally.

## Run it locally

The server refuses every request without TLS, a database, an identity and access management system and a valid manifest.
The quickest way to run it on your machine is the demonstration stack, which supplies all four. From this folder:

```bash
docker compose -f demo/docker-compose.yml up --build
```

Then open https://localhost:17443. [The quick start](../docs/quickstart.md) walks through what the demonstration holds
and what to try. A PHP application has no start-up step, so the server checks all four on every request. The console
commands skip those checks.

To run it outside the demonstration, put your settings and credentials in `.env` in this folder. Git ignores that file,
and the installation page lists the settings.

## Shared inputs

`composer install` copies the contract, the schema files, the interface strings, the stylesheet, the favicon and the
theme override file from `../shared` into `contract`, `schema`, `resources` and `public`. Run `composer sync-shared` to
copy them again at any time. Git ignores the copies. Edit the files in `../shared`, never the copies, and remember that
a change there changes all three implementations.

## Audit retention

PHP has no long-running process, so incoming requests prune the audit log. A small random share of requests checks
whether the configured interval has passed since the last prune. If it has, that request runs the prune itself. Nothing
else needs scheduling, and there is no scheduled console command.

## Container image

Each release is published on Docker Hub as `astrana/ata-php`, tagged with its full version, its major and minor version
and `latest`, for 64-bit Intel and AMD processors (amd64) only. To build the image yourself, run this from the
repository root, so that `shared/` is in the build context:

```bash
docker build -f php/Dockerfile -t astrana/ata-php .
```

The image holds the application, serves with PHP's built-in web server on port 8000 and runs as a non-root user. The
built-in server suits a container behind a proxy that holds the certificate. It runs one worker, and PHP's manual says
it is not meant for production. For more than a small organisation's traffic, point a web server at `public/` instead.
Configuration comes from environment variables, as the installation page describes.

The published image supports MySQL and PostgreSQL. Microsoft's Open Database Connectivity (ODBC) driver for SQL Server
may be redistributed only under Microsoft's own licence terms, so it is not in the published image. An organisation that
needs SQL Server builds its own image with the `WITH_SQLSRV` build argument, which adds the driver, its licence file and
the `sqlsrv` and `pdo_sqlsrv` extensions at the versions the `Dockerfile` pins. By building it, you accept Microsoft's
licence terms for the driver.

```bash
docker build --build-arg WITH_SQLSRV=true -f php/Dockerfile -t ata-php-sqlsrv .
```

## Third-party notices

`scripts/generate-third-party-notices.php` writes `public/THIRD-PARTY-NOTICES.txt`, which the licence page links to. It
runs after every `composer install`, and again while the container image is built, which also copies the file to
`/app/THIRD-PARTY-NOTICES.txt`. The file opens with the project's own copyright line. Then it gives the notices of the
components bundled into the shared inputs, which is Bootstrap in the stylesheet, from `../shared/third-party/bundled`.
Next comes the licence file of every runtime Composer package. In the image it also gives the copyright files of the
Debian packages the `Dockerfile` adds. A package with no licence file stops the install or the build, so nothing ships
without its notice. The file is generated, so it is not kept in Git.

## Changelog and bill of materials

`CHANGELOG.md` follows Keep a Changelog, with no Unreleased section, as
[decision record 38](../docs/adr/0038-shared-feature-versions-independent-patches-release-on-merge.md) describes.
`sbom.json` is a CycloneDX software bill of materials for the published image, generated from the Composer lock file
without the development dependencies. It also lists what the image installs outside Composer. These are the Debian
packages the `Dockerfile` adds, at the versions the image was last built with, and the `sqlsrv` and `pdo_sqlsrv`
extensions and Microsoft's ODBC driver, at the versions the `Dockerfile` pins. The published image does not hold the SQL
Server parts, so the bill of materials marks them as optional. When a dependency or one of those versions changes,
regenerate it from this folder, with the version from the changelog:

```bash
docker run --rm -v "$(pwd)":/app -w /app --entrypoint sh composer:2 -c \
  'composer global config allow-plugins.cyclonedx/cyclonedx-php-composer true &&
   composer global require cyclonedx/cyclonedx-php-composer &&
   composer CycloneDX:make-sbom --output-format=JSON --output-file=sbom.json --omit=dev --spec-version=1.6 --mc-version=1.0.0 &&
   php scripts/add-native-sbom-components.php'
```

The last command adds the components from outside Composer. In Git Bash on Windows, start the command with
`MSYS_NO_PATHCONV=1` and write `"$(pwd -W)"` in place of `"$(pwd)"`, or Git Bash rewrites `/app` as a Windows path. A
unit test fails if `sbom.json` does not carry the versions the `Dockerfile` pins. The Debian package versions are listed
in `scripts/add-native-sbom-components.php`, with the Debian release they came from. The script refuses a `Dockerfile`
whose runtime image names another Debian release, so after moving to a new Debian release, rebuild the image and bring
that list up to date from the packages the image added:

```bash
docker run --rm --entrypoint sh astrana/ata-php -c \
  'dpkg-query -W $(cat /usr/local/share/astrana-trusted-attestation/debian-packages)'
```
