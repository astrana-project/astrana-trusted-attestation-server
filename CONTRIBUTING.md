# Contributing

Every change comes in as a pull request. When a pull request merges into `master`, the Release workflow publishes any
new version in its changelogs at once, so a pull request has to be ready for production before it merges.

A pull request is not ready if it makes an observable change in only some of the three implementations, or leaves the
changelog or the tests for later. Open a draft pull request if you want feedback early, and mark it ready for review
only when it is complete.

## Before you start

Every change starts with an issue. For anything beyond a small fix, open the issue before you start work, and describe
what you intend to change and why. If the maintainer agrees it should be done, go ahead. The maintainer does not review
a pull request until it links to its issue.

**Never raise a security vulnerability in an issue or a pull request.** Report it privately, as described in
[SECURITY.md](SECURITY.md).

You need Git, Node.js, Python 3 and Docker. You also need the toolchain for the implementation you work on, which is the
.NET 10 software development kit, a Java 25 development kit (the wrapper in `java/` supplies Maven) or PHP 8.4.1 or
later with Composer.

On Windows the scripts need PowerShell 7. Run `scripts/setup.sh` (`scripts\setup.ps1` on Windows) once after cloning. It
installs the formatter and reports which of the tools above you have. With `--hooks` (`-Hooks` on Windows) it also
installs two git hooks. One signs off each commit for you. The other runs the sign-off and changelog checks before a
push, so it stops the first push of a draft that changes released code but has no new changelog heading yet. Write the
heading first, or use `git push --no-verify` for that one push.

## What a pull request needs

- Make one change per pull request. Link the issue, write a short title in the imperative and write a description that
  explains what changed and why. They become the commit message on `master`.

- If the change alters anything observable from outside the server, such as the API, a response, a status code, the
  manifest or a page, make it in all three implementations and in the contract or the shared interface in `shared/ui`,
  with the tests [Tests](#tests) lists, all in the same pull request. The three implementations must behave identically.

- Add or update the tests for every code change, as [Tests](#tests) describes.

- Add a changelog entry where [Changelog and version](#changelog-and-version) requires one, sign off every commit and
  run the formatters.

- Keep your branch up to date with `master`.

- All the checks must pass. They are the unit tests of each implementation, the conformance, differential and
  accessibility suites, the SonarQube Cloud quality gate, CodeQL, the Hadolint, Trivy and Gitleaks scans, the arm64
  container image builds, and the formatting, script test, changelog and sign-off checks.

## Code

Write code that is easy to understand and maintain. Name things for what they are or do, so that a reader does not need
to open them to find out. Give each function one job, keep it short enough to hold in your head, and keep its complexity
low. The SonarQube Cloud quality gate checks complexity, duplication and coverage on every pull request.

Put code where a reader would look for it, and keep the structure the same across similar parts of the codebase. Make
dependencies explicit rather than using shared state.

Let the code and its structure describe themselves. Add a comment only where it adds something the code cannot say. If
the reason for something matters beyond the lines next to it, write it in an architecture decision record, not in a
comment. Remove dead code and leftover debugging rather than commenting it out.

Do not repeat yourself without a reason. Write each implementation in the idiom of its own stack, not in the style of
one of the others.

Keep third-party dependencies to a minimum. Each one is part of the attack surface of every organisation that runs this
software, and a vulnerability in one means a release. Use what the language and the framework already provide. Add a
dependency only when it does something they do not, and say in the pull request why it is needed. The
[decision record on dependencies](docs/adr/0006-keep-third-party-dependencies-to-a-minimum.md) gives the reasoning.

The pages must meet the Web Content Accessibility Guidelines (WCAG) 2.1 at level AA, as the
[decision record on the shared interface](docs/adr/0033-one-accessible-interface-shared-by-all-three-implementations.md)
requires, with the
[markup the shared interface describes](shared/ui/README.md#the-markup-the-landing-page-and-the-self-service-page-carry).

`shared/contract`, `shared/schema` and `shared/ui` contain the single source of the API contract, the database schemas,
the stylesheet and interface strings, and the relationship-type vocabulary. Each implementation copies the files it uses
at build time. Never edit an implementation's copy. Change the source in `shared/`. Import `relationship-types.json`,
never retype its values. Keep request and response shapes exactly as `openapi.yaml` defines them.

These rules keep the design secure, so do not weaken them.

- The application refuses to run without TLS, without the identity provider's discovery document under OpenID Connect,
  or without a valid manifest.
- The manifest is built from an allowlist of public fields, never by serialising the configuration.
- The audit log is append-only. The application's database role has `INSERT` on it and nothing else, apart from
  executing the retention procedure and, on PostgreSQL, using the sequence that numbers its rows.
- Attestation keys and attestation lookups are never logged.
- Error responses have a status code only, with no body and no `Content-Type`.
- No route contains a member identifier. The session supplies it.
- `PUT` on a relationship's attestation key never creates a relationship, and a member can never grant, extend or
  restore their own.

## Tests

The maintainer recommends writing the test first. Write the test that describes the behaviour you want before you change
the code, run it and see it fail for the reason you expect. Then write the smallest code that makes it pass.

The tests each kind of change needs:

| Change                                                                                                  | Tests it needs                                                                                                                                                                                                                                                           |
| ------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Code in one implementation that changes nothing observable from outside the server                      | Unit tests in that implementation                                                                                                                                                                                                                                        |
| Behaviour observable from outside the server, such as a response, a status code, the manifest or a page | A check in `shared/test/conformance/check.py`, unit tests in each implementation, and, where the three implementations could answer differently, a case in `shared/test/differential.py`, or in `shared/test/differential_auth.py` for a change a signed-in member makes |
| A new page                                                                                              | The tests for observable behaviour, and checks of the new page in `shared/test/accessibility.py`                                                                                                                                                                         |
| The database schema or its procedures, in `shared/schema`                                               | A check in `shared/test/schema-checks.py`, or in `shared/test/role-checks.py` for what each database role may do                                                                                                                                                         |
| A bug fix                                                                                               | A test that reproduces the bug, in the suite where the bug shows                                                                                                                                                                                                         |

- Do not change a test to make it pass, unless a decision has changed the behaviour it describes.
- Give every unit test its own dependencies, so that no test can affect another.

Continuous integration does not run `schema-checks.py` or `role-checks.py`. Run them yourself against a copy of the
database on each of the three engines, as [the database checks](shared/test/README.md#the-database-checks) describes.

The unit tests are in `dotnet/tests/` (xUnit), `java/src/test/` (JUnit) and `php/tests/` (PHPUnit), and each
implementation's README explains how to run them. [The suites' README](shared/test/README.md) describes what each suite
checks and how to run it.

Every class of application code needs at least 80% unit test coverage. Application code is the project's own logic, such
as its services, its validators and the decisions its controllers make. The rule leaves out framework wiring,
configuration classes and the code that reads and writes the database, because the conformance suite tests them through
the running server.

SonarQube Cloud shows each file's coverage, and it measures every file except the start-up code. Its quality gate also
fails a pull request whose new code has less than 80% coverage.

## Secrets

**Never commit a secret.** That means any password, token, private key, client secret or connection string that contains
a password, for any system, your own test accounts included. The settings files in the implementations contain none, the
development settings included. For local development, each implementation's README shows where credentials go instead.
For .NET that is user secrets, and otherwise a file Git ignores.

There is one exception. The demonstrations, the tests in `shared/test` and continuous integration start their own
containers, and the made-up passwords and client secrets for those containers are in the repository. They give access to
nothing but those containers, which run on your own computer or in continuous integration. A demonstration publishes its
ports on every network address of your computer, so run it only on a network you trust. Never use these passwords and
secrets anywhere else, and never swap in a real password.

If you commit a secret by mistake, treat it as exposed, even after you remove it, because it stays in the history.
Revoke or replace it first. Then tell the maintainer. If the secret gives access to anything real, tell them privately,
as [SECURITY.md](SECURITY.md) describes.

## Documentation

Write for human readers, in plain British English. Expand an acronym the first time it appears in a document.

- Do not use em-dashes or semicolons, and do not write "e.g.", "i.e." or "etc.".
- Do not front a sentence with a label and a colon, such as "Note:". A line ending in a colon that introduces a list or
  a code block is fine.
- Do not write sentences about the document itself, or point to something described elsewhere without adding anything.
- Do not add status or history notes, such as "currently", "no longer" or "was changed".
- Do not put planning notes, task lists or commentary about the work into repository documents.

Write "Astrana Trusted Attestation" or "the Astrana Trusted Attestation Server" in full, never the acronym. Identifiers
such as `ata_session` and `astrana/ata-*` stay as they are.

Leave out filler words such as "deliberately", "simply", "by design" and "for now". Every sentence should give the
reader something they can use.

## Architecture decision records

The reasons behind the design are in [docs/adr](docs/adr), one record per decision, read in order. If your change makes
a decision the index would have to list, or reverses one it already lists, include the record in the same pull request.
A change that stays inside an existing decision, such as a new configuration option, a fix or a dependency update, needs
no record.

A decision record covers one decision that could be reversed on its own. If you find yourself writing two, split them.
If what you are writing could not be reversed without reversing another record, it belongs in that record's
consequences.

- Title the record with the decision, in plain words, so that the title alone says what was decided. The file name is
  the title, with the next number in front.
- Start with the status as a sentence, `Accepted on YYYY-MM-DD.`, dated the day the decision was made.
- Then Context, Decision and Consequences sections. Context must describe what made the decision necessary. Decision
  must describe what was decided and nothing else. Consequences describe what follows from it, what it costs and what
  was considered and rejected and why, written as sentences rather than labelled fragments.
- Cite only lower-numbered records. If you need a later one, either the order is wrong or the sentence belongs in the
  later record.
- Do not end with a pointer to the contract, and do not point to this file, SECURITY.md, the README or the installation
  pages anywhere in a record. The contract and the schema files contain the detail.
- Write an HTTP status as its code and name, `HTTP 404 - Not Found`.
- Leave out counts that will change, such as how many identity providers or locales are tested.
- Keep it short. A record that runs past a screen is usually two decisions.
- Add it to the index in the group it belongs to, with the records it derives from.

To change a decision, write a new record and change the old one's status line to `Superseded by record N on YYYY-MM-DD.`
Never edit a record into a different decision. The old record is the history of why things were as they were.

Write records as [Documentation](#documentation) describes, treating each record as its own document for acronyms, and
use no bold for emphasis.

## Review and merging

Only the maintainer merges, and reviews every change that is not their own before merging it. The maintainer's own
changes go through the same automated checks as everyone else's.

Pull requests are squash-merged. Your branch becomes one commit on `master`, and its intermediate commits are not kept.
Your branch commits need no particular message style, because nothing reads them apart from the sign-off line. The pull
request title and description are what survive, and the changelog is written by hand rather than generated from commits.

The [code of conduct](CODE_OF_CONDUCT.md#decisions) sets out how decisions about the project are made and how to
disagree with one.

## Changelog and version

Each implementation has its own `CHANGELOG.md` (`dotnet/`, `java/`, `php/`), in
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) style.

Add a heading for the new version, `## [X.Y.Z] - YYYY-MM-DD`, and list your changes under it. Do not add an `Unreleased`
section. Date the heading the day you finalise the entry. If the pull request merges on a later day, update the date
before it merges, or the maintainer will when merging. The version you write is the one released when the pull request
merges, and the Git tag and the container image take their version from it. The Java implementation's jar takes its
version from `java/pom.xml`, so when you bump the Java implementation's version, set it there too. Regenerate the
implementation's `sbom.json` as its README describes whenever a dependency or the version changes. The changelog check
compares the versions in `java/pom.xml` and `sbom.json` with the changelog.

[Versions and releases](docs/versions.md#what-each-number-means) explains what each number means. Choose the version as
follows.

- If you make a functional change, in `shared/contract`, `shared/schema` or `shared/ui` or anywhere observable from
  outside the server, bump the same new `MINOR` or `MAJOR` in all three changelogs, with the same entry in each.
- If you fix a defect in `shared/contract`, `shared/schema` or `shared/ui`, or make any other change there that is not
  functional, bump `PATCH` in all three changelogs, each one step above that implementation's current version, with the
  same entry in each. The three patch numbers can differ.
- If you fix a defect in one implementation, update its dependencies, or refactor or restructure its shipped code
  without a functional change, bump that implementation's `PATCH`, in its changelog only.
- If you change only documentation, the demonstration stacks, tests, the test harness or development configuration,
  leave the changelogs alone. Development configuration means `appsettings.Development.json`, `launchSettings.json`,
  `application-dev.yml` and `application-dev-saml.yml`. The version tracks the software, and none of these are part of
  it.
- The same goes for the contract's specifications, `shared/contract/openapi.yaml` and
  `shared/contract/manifest.schema.json`. No implementation is built from them or runs them, so a change to them alone
  leaves the changelogs alone. A change they describe is released through the code that makes it.
- Build files that never ship need no changelog entry either. These are the Dockerfiles, the stylesheet's npm files and
  Sass source, the PHP build scripts, the Maven wrapper, the `sbom.json` bills of materials, the .NET restore settings
  and lock files, and the configuration of the JaCoCo and Spotless plugins in `java/pom.xml`. Merging a change to an
  implementation's `Dockerfile` without a new version publishes a container image rebuild of its latest version, with no
  changelog entry.
- Development-only dependencies need no changelog entry either. These are `require-dev` in `php/composer.json`,
  `packages-dev` in `php/composer.lock` and the dependencies with the `test` scope in `java/pom.xml`. A change to any
  other dependency's version still needs one, and so does any other change to `java/pom.xml`.

The changelog check fails the pull request if the heading is missing, the bump does not match the files you changed, the
three `MAJOR.MINOR` versions differ, or `java/pom.xml` or an `sbom.json` states a different version from its changelog.
The
[decision record on versioning](docs/adr/0049-version-numbers-indicate-what-an-update-means-and-container-image-changes-are-published-as-rebuilds-without-a-new-version.md)
explains why versions work this way.

## Automatic update pull requests

Dependabot opens pull requests to update the project's dependencies. What it checks each week is set in
`.github/dependabot.yml`.

Updates to GitHub Actions, Prettier and development-only dependencies don't change the server. Their pull requests pass
the changelog check and can be merged as they are.

Updates to libraries the server ships with do change it, so they need a changelog entry and a new version of the
implementation they ship in. Dependabot can't write either, and their pull requests fail the changelog check until
someone does. Before merging one, add a signed-off commit to its branch that:

- adds a new `PATCH` version to the implementation's changelog, as [Changelog and version](#changelog-and-version)
  describes
- names the updated library with its old and new versions, under "Security" with the advisories the update fixes that
  affect the server, or under "Changed" if the update fixes none
- adds "with the libraries it uses" when the update also changes the versions of the libraries that the updated library
  uses
- for Java, sets the same version in `java/pom.xml`
- regenerates the implementation's `sbom.json`, as its README describes

Once you've pushed to the branch, Dependabot stops rebasing it. If `master` gets newer commits before you merge, update
the branch yourself.

`java/pom.xml` overrides some of the versions that Spring Boot or OpenSAML would otherwise use, to get security fixes
sooner. Dependabot proposes updates to these overrides as it does for any other dependency. Once Spring Boot manages, or
OpenSAML depends on, the same version or a newer one, remove the override.

## Reviewing a translation

A machine drafted every language other than English in `shared/ui/ui-strings.json`, so each one needs a native speaker
to review it. To review a language, write its review sheet from the repository root, using the language's tag from the
strings file, such as `fr` for French or `zh-Hans` for Simplified Chinese:

```
python3 scripts/translation-review.py --locale fr
```

On Windows, use `py` in place of `python3`. The script writes the sheet to `translation-review/fr.md`, and Git ignores
that folder. `--out` writes it to another folder, and without `--locale` the script writes a sheet for every language.
The sheet lists each string with where it appears on the page, the English and the translation, then the name of each
relationship type, and the things to check.

To propose corrections, open a pull request that changes only that language's entries in `shared/ui/ui-strings.json`, or
its labels in `shared/contract/relationship-types.json`, and links to its issue. Keep every key as it is, and keep every
placeholder in curly brackets, such as `{org}`, exactly as written. A correction is a defect fix, so version it as
[Changelog and version](#changelog-and-version) describes, with an entry such as "Corrected the French interface text."

## Releasing

Releases are published only by the Release workflow, which runs on each push to `master`, so each time a pull request
merges. Never publish a container image, a Git tag or a GitHub release from your own computer. The workflow publishes
with the `DOCKERHUB_USERNAME` and `DOCKERHUB_TOKEN` repository secrets, and those credentials exist only in the
repository's continuous integration secrets. A release that fails, for example because those secrets are not set, is
tried again on the next push to `master`, or when the maintainer runs the Release workflow by hand.

The workflow signs each container image and each release's software bill of materials, and the installation guides show
users how to check those signatures. The signing identity is made from the repository's name, the workflow file's path
and the branch, so renaming any of them changes it. If you rename one, update the verify commands in the installation
guides and the identity the workflow checks its own signatures against, in the same pull request. The workflow's
`id-token: write` permission must stay, because signing depends on it. The
[decision record on checking where a published container image came from](docs/adr/0044-anyone-can-check-where-a-published-image-came-from.md)
explains why releases are signed this way.

## Developer Certificate of Origin

Contributions are accepted under the [Developer Certificate of Origin](DCO). By signing off a commit you certify that
you wrote the change or otherwise have the right to submit it under the project's licence, the
[MIT License](LICENSE.md). Sign off every commit:

```
git commit -s -m "Your message"
```

This adds a `Signed-off-by` line matching the commit author. `scripts/setup.sh --hooks` installs a hook that adds the
line for you. The sign-off check fails the pull request if any commit on your branch lacks one. If you forget, amend or
rebase to add it and push again.

## AI coding assistants

This project is developed with AI coding assistants, and the maintainer reviews everything they produce. You may use
them too. Signing off a change means you have reviewed it, you understand it and you stand behind it, however it was
written.

To credit an assistant, add a `Co-Authored-By` line naming it to the pull request description. Pull requests are
squash-merged, so `master` keeps the pull request description but not a line in a branch commit.

## Formatting

Run the formatters before you push. `scripts/format.sh` (`scripts\format.ps1` on Windows) runs them all, skipping any
whose toolchain you do not have. `scripts/check.sh` (`scripts\check.ps1` on Windows) runs the formatting, sign-off and
changelog checks, with `--tests` (`-Tests` on Windows) for the unit tests as well. Prettier formats the Markdown, YAML,
JSON, SCSS and CSS in the repository. To run it by hand:

```
npm ci
npm run format
```

Each implementation's code has its own formatter, run from that implementation's folder (`dotnet/`, `java/` or `php/`):

| Implementation | Format                                          | Check                                                               |
| -------------- | ----------------------------------------------- | ------------------------------------------------------------------- |
| .NET           | `dotnet format Astrana.TrustedAttestation.slnx` | `dotnet format Astrana.TrustedAttestation.slnx --verify-no-changes` |
| Java           | `./mvnw spotless:apply`                         | `./mvnw spotless:check`                                             |
| PHP            | `vendor/bin/pint`                               | `vendor/bin/pint --test`                                            |

The formatting check fails a pull request that is not formatted. The SQL schema files and the view templates are not
machine-formatted.

## Building and testing locally

Each implementation's README ([.NET](dotnet/README.md), [Java](java/README.md), [PHP](php/README.md)) explains how to
build, configure and run it. The conformance, differential and accessibility suites run against all three
implementations at once, in the same Docker stack that continuous integration uses. `scripts/integration.sh`
(`scripts\integration.ps1` on Windows) builds it, runs the suites and tears it down. The first run downloads several
gigabytes of container images and takes a while. The commands it runs:

```
docker compose -f shared/test/ci/docker-compose.ci.yml up -d --build keycloak postgres dotnet-app java-app php-app proxy
docker compose -f shared/test/ci/docker-compose.ci.yml --profile suite build suite
docker compose -f shared/test/ci/docker-compose.ci.yml run --rm --no-deps suite bash ci/run-suites.sh /reports
docker compose -f shared/test/ci/docker-compose.ci.yml --profile suite down -v
```

To run them by hand in Git Bash on Windows, set `MSYS_NO_PATHCONV=1` first, or Git Bash rewrites `/reports` as a Windows
path. `scripts/integration.sh` sets it for you. The report is `shared/test/ci/reports/index.html`, and
[the suites' own README](shared/test/README.md) describes what each suite checks, the identity provider matrix and the
database checks. A change to `shared/contract`, `shared/schema` or `shared/ui` changes all three implementations, so it
is not complete until those suites pass against all three, not only the one you work in. The SonarQube Cloud quality
gate runs only in continuous integration.
