# The suites

The suites in this folder define conformance and parity for the three implementations of the Astrana Trusted Attestation
Server. They are written in Python, which none of the implementations uses, and need only the standard library, plus
Playwright and axe-core. They run against real identity providers, not mocks, and cannot tell which implementation they
are talking to. [Decision record 36](../../docs/adr/0036-parity-is-defined-by-one-implementation-blind-suite.md) says
why.

## One suite in three parts

The suite has three parts, the conformance, differential and accessibility suites, and each can be run on its own.

| Part          | File                   | What it checks                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| ------------- | ---------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Conformance   | `conformance/check.py` | What the contract and the decision records require of one running implementation, over HTTP, in named sections. The manifest and its allowlist, the landing page and the language switcher, sign-in, the self-service page, registering and clearing a key, revoking and removing a relationship, each with its audit entry, attestation, errors, headers, sign-out.                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| Differential  | `differential.py`      | The same requests sent to all three. It compares API answers on status, content type and body. It compares pages on the language and text direction they render in, not their markup, and redirects on where they send the browser and the cookie they set. The file lists the accepted differences, on inputs the contract does not define, each with its reason. Any other difference fails. It also sends requests straight to each application, past the proxy, with `X-Forwarded-Proto` headers the proxy would never pass on. Each application must answer HTTP 421 - Misdirected Request with no body unless that header holds the single value `https`, and must serve a request that has no such header. It also checks that the applications' own answers to these requests carry no `Server` or `X-Powered-By` header. |
| Accessibility | `accessibility.py`     | The landing page and the self-service page driven in a real browser. It checks them against the Web Content Accessibility Guidelines (WCAG) 2.1 AA rules a machine can detect, checks that every control can be reached by keyboard, and checks that the page fits a 320 pixel wide screen and 200 percent text size with no horizontal scrolling.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |

The conformance suite grants relationships directly in the database before anyone signs in, so it does not test purely
from the outside. It signs in through the identity provider's own pages. Every check is named in plain English, and the
report shows those names.

## Running them

The stack under `ci/` is the one continuous integration uses. It builds the three images from their Dockerfiles. It puts
them behind one TLS-terminating proxy at the addresses the demonstrations use, 15443 for .NET, 16443 for Java and 17443
for PHP, with one PostgreSQL and one Keycloak. Then it runs all three parts of the suite against all three
implementations and writes a report. From the repository root:

```bash
docker compose -f shared/test/ci/docker-compose.ci.yml up -d --build keycloak postgres dotnet-app java-app php-app proxy
docker compose -f shared/test/ci/docker-compose.ci.yml --profile suite build suite
docker compose -f shared/test/ci/docker-compose.ci.yml run --rm --no-deps suite bash ci/run-suites.sh /reports
docker compose -f shared/test/ci/docker-compose.ci.yml --profile suite down -v
```

In Git Bash on Windows, set `MSYS_NO_PATHCONV=1` first, or Git Bash rewrites `/reports` as a Windows path. The
repository's `scripts/integration.sh` runs those steps and sets it for you, and `--keep` leaves the stack up to rerun
the suites or look at a server. The report lands in `ci/reports/index.html` and is always written, even when a suite
fails or never starts. The stack uses the same ports as the demonstrations, so stop a demonstration first. The first run
downloads several gigabytes of images.

A suite can also be pointed at a single running instance. The conformance suite grants the relationships it tests, so it
also needs a command that runs SQL against that instance's database, for example
`python shared/test/conformance/check.py --base-url https://localhost:15443 --database mssql --sql-command "<command that reads SQL on standard input>"`.
It grants and clears its own test relationships in that database. Each file's `--help` lists its options.

## The identity provider matrix

`integration-matrix.sh` runs the conformance suite against all three implementations, once for each identity provider
you name with `--idp`. You can give `--idp` more than once. It then builds a matrix report with `integration-report.py`.
`--all` runs Keycloak, Zitadel, Authentik and SimpleSAMLphp. The providers it knows, each with its own folder of
configuration here, are Keycloak, Zitadel, Authentik, Casdoor, WSO2 Identity Server and Authelia over OpenID Connect,
and SimpleSAMLphp, Zitadel, Authentik and WSO2 over SAML. The matrix accepts a provider only if it can present a subject
identifier that the test fixtures can set to a known value. That lets the suite grant relationships before anyone signs
in. The matrix runs .NET on Microsoft's free SQL Server Developer edition, and starting it accepts the
[SQL Server Developer licence terms](https://go.microsoft.com/fwlink/?linkid=857698).

The matrix writes its own application stack under `reports/stack/` and starts each provider's stack on this machine. It
mounts the development SAML signing material read-only from `java/saml`, `php/saml` and the .NET project's `saml`
folder, so it runs only on this machine, not in continuous integration. That material is not in the repository, so
generate it before a SAML profile, with the `openssl` commands in each implementation's README
([.NET](../../dotnet/README.md), [Java](../../java/README.md) and [PHP](../../php/README.md)).

The identity providers' own keys, certificates and secrets are not in the repository either. Before its first profile,
the matrix runs `generate-idp-material.sh`. The script creates any that are missing in a `generated/` folder beside each
provider's configuration, and keeps the ones that already exist. Git ignores those folders, and the values differ on
every machine. Run the script yourself before starting one of these providers with `docker compose` directly.

- Authelia gets a TLS key and certificate for `authelia.127.0.0.1.nip.io`, a key for signing its tokens, and random
  secrets for its session, password reset tokens, storage encryption and token hashing. Its `configuration.yml` holds
  only references to those files, which Authelia fills in at start. Its database and notification file are written to
  the same folder, because the database is encrypted with the storage secret and cannot be read without it.
- WSO2 Identity Server gets a TLS key and certificate for `localhost`, packed into the keystore its image copies in.
- Authentik gets a random secret key in `secrets.env`, which its `docker-compose.yml` reads.

Authelia and WSO2 serve HTTPS with a self-signed certificate, so each implementation is given that certificate to trust.
The .NET implementation reads it through `SSL_CERT_FILE` and the PHP implementation through `curl.cainfo` and
`openssl.cafile`. The Java implementation reads a PKCS #12 truststore made from it, whose password is `changeit`. The
script writes that truststore with `openssl` 3.2 or later, and otherwise with `keytool` in an `eclipse-temurin`
container.

## The database checks

Two scripts test what the suites cannot reach over HTTP, against a live database of any of the three engines.

`schema-checks.py` calls the procedures and writes rows directly to check the data model behaves as the schema files
describe. It checks that:

- a grant creates one relationship without a key, and a second grant of the same type is refused
- a member can hold several relationships without a key at once
- no key can be held by two relationships
- revoking and extending touch only the named relationship
- each procedure writes its own audit row
- a member's own revoke writes one entry with no actor
- of two grants made at the same moment, only one succeeds
- a grant of a type outside the vocabulary, in the wrong case or with a trailing space raises an error and writes
  nothing
- a grant to a subject identifier with leading or trailing spaces does the same
- every procedure matches the subject identifier and the type exactly, including trailing spaces, which MySQL and SQL
  Server would otherwise ignore
- revoking or extending a relationship the member does not hold raises an error and writes no audit entry, but a
  member's own revoke of one raises no error

`role-checks.py` creates two roles of its own, gives them the grants the schema file documents and checks what each can
and cannot do against what
[decision record 16](../../docs/adr/0016-granting-revoking-and-extending-are-enforced-in-the-database-not-in-the-application.md)
requires. It also writes the key with an update that names every column, as an object-relational mapper would, and
checks that the database refuses the application's role. That refusal is why every implementation writes `public_key` on
its own. On PostgreSQL it creates temporary tables named `audit_log` and `member_relationships` as each role before
calling a procedure, and checks that the write still reached the real tables. The procedures pin their `search_path` to
make sure of that. On MySQL it also needs `--as-application-command` and `--as-organisation-command`, because MySQL
cannot drop privileges within a session. Both scripts change the database they run against, so run them against a copy.

`reset-schema.sh` drops the schema from one engine in the shared development stack (`docker-compose.yml`), so the next
implementation to start recreates it. `engines.py` is the module the scripts use to talk to each engine.

## The other scripts

`check-vocabulary.py` confirms that the relationship type vocabulary in `shared/contract/relationship-types.json`
matches the OpenAPI document, the manifest schema, the list `grant_member_relationship` checks a type against in each
schema file, and every copy an implementation consumes. `mutation-harness.py` introduces one fault at a time into an
implementation and reports whether its unit tests or the conformance suite notice. Pass the implementation's folder
(`dotnet`, `java` or `php`) with `--repo`, and a JSON file describing the faults with `--mutants`. File paths in the
JSON file are relative to the implementation's folder. `differential_auth.py` signs in as the same member on each
implementation and compares the answers to the actions that change data, which are registering and clearing a key, and
revoking and removing a relationship. Give it one `--app` per implementation, naming its address, database engine and
SQL command.

## Adding a check

Each new requirement, in the contract or in a decision record, gets a check in `conformance/check.py`, in the section it
belongs to. Name the check as a sentence a reader can act on. Where the three could answer differently, also add a case
to `differential.py`. Add a difference the contract does not define to the accepted list with its reason, never
silently. Run the suites against all three implementations before a change to an implementation or to anything under
`shared/` is complete.
