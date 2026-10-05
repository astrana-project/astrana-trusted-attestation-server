# Contributing

Every change comes in as a pull request. Merging into `master` releases it, so a pull request has to be ready for
production before it merges.

A pull request is not ready if it makes an observable change in only some of the three implementations, or leaves the
changelog or the tests for later. Open a draft pull request if you want early eyes on the work, and mark it ready for
review only when it is complete.

## Before you start

Every change has an issue. For anything beyond a small fix, open the issue before you start work, and describe what you
intend to change and why. If we agree it should be done, go ahead. The maintainer does not review a pull request until
it links to its issue.

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
  says what changed and why. They become the commit message on `master`.

- If the change alters anything observable from outside the application (the API, a response, a status code, the
  manifest, the pages), make it in all three implementations, in the contract or the shared interface in `shared/ui`,
  and in the conformance suite, in the same pull request. The three implementations must behave identically.

- If you add or change code, add or update the unit tests for it. The project targets 80% unit test coverage or higher.

- Add a changelog entry, sign off every commit and run the formatters.

- Keep your branch up to date with `master`.

- All the checks must pass: the unit tests of each implementation, the conformance, differential and accessibility
  suites, the SonarQube Cloud quality gate, CodeQL, formatting, changelog and sign-off.

## Code

Write code that is easy to understand and maintain. Name things for what they are or do, so that a reader does not need
to open them to find out. Keep each function to one job and short enough to hold in your head, and keep its complexity
low.

Put code where a reader would look for it, and keep the structure the same across similar parts of the codebase. Make
dependencies explicit rather than reaching for shared state. Let the code and its structure describe themselves. Add a
comment only where it adds something the code cannot say. If the reason for something matters beyond the lines next to
it, write it in an architecture decision record, not in a comment. Remove dead code and leftover debugging rather than
commenting it out. The SonarQube Cloud quality gate checks complexity, duplication and coverage on every pull request.

Do not repeat yourself without a reason. Write each implementation in the idiom of its own stack, not in a style carried
over from one of the others.

Keep third-party dependencies to a minimum. Each one is part of the attack surface of every organisation that runs this
software, and a vulnerability in one means a release. Use what the language and the framework already provide. Add a
dependency only when it does something they do not, and say in the pull request why it is needed. The
[decision record on dependencies](docs/adr/0006-keep-third-party-dependencies-to-a-minimum.md) gives the reasoning.

`shared/` holds the single source of the API contract, the database schemas, the stylesheet and interface strings, and
the relationship-type vocabulary. Each implementation copies them in at build time. Never edit an implementation's copy.
Change the source in `shared/`. Import `relationship-types.json`, never retype its values. Keep request and response
shapes exactly as `openapi.yaml` defines them.

Give every unit test its own dependencies, so that no test can affect another.

These rules keep the design secure, so do not weaken them.

- The application refuses to run without TLS, without the identity provider's discovery document under OpenID Connect,
  or without a valid manifest.
- The manifest is built from an allowlist of public fields, never by serialising the configuration.
- The audit log is append-only. The application's database role holds `INSERT` on it and nothing else, apart from
  executing the retention procedure and, on PostgreSQL, using the sequence that numbers its rows.
- Attestation keys and attestation lookups are never logged.
- Error responses carry a status code only, with no body and no `Content-Type`.
- No route carries a member identifier. The session supplies it.
- `PUT` on a relationship's attestation key never creates a relationship, and a member can never grant, extend or
  restore their own.

## Secrets

**Never commit a secret.** That means any password, token, private key, client secret or connection string that carries
a password, for any system, your own test accounts included. The settings files in the implementations hold none, the
development settings included. For local development, each implementation's README shows where credentials go instead:
.NET user secrets, or a file Git ignores.

There is one exception. The demonstrations, the tests in `shared/test` and continuous integration start their own
containers, and the made-up passwords and client secrets for those containers are in the repository. They open nothing
but those containers, which run on your own computer or in continuous integration. A demonstration publishes its ports
on every network address of your computer, so run it only on a network you trust. Never use these passwords and secrets
anywhere else, and never swap in a real password.

If you commit a secret by mistake, treat it as exposed, even after you remove it, because it stays in the history.
Revoke or replace it first. Then tell the maintainer. If the secret gives access to anything real, tell them privately,
as [SECURITY.md](SECURITY.md) describes.

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
  pages anywhere in a record. The contract and the schema files hold the detail.
- Write an HTTP status as its code and name, `HTTP 404 - Not Found`.
- Leave out counts that will change, such as how many identity providers or locales are tested.
- Keep it short. A record that runs past a screen is usually two decisions.
- Add it to the index in the group it belongs to, with the records it derives from.

To change a decision, write a new record and change the old one's status line to `Superseded by record N on YYYY-MM-DD.`
Never edit a record into a different decision. The old record is the history of why things were as they were.

Write records in plain language, with British spelling, words in full, acronyms expanded on first use in each record, no
bold for emphasis, and no labels fronting a sentence.

## Review and merging

Only the maintainer merges. Every change that is not the maintainer's own is reviewed by the maintainer before it
merges. The maintainer's own changes go through the same automated checks as everyone else's.

Pull requests are squash-merged. Your branch becomes one commit on `master`, and its intermediate commits do not reach
`master`. Your branch commits need no particular message style, because nothing reads them apart from the sign-off line.
The pull request title and description are what survive, and the changelog is written by hand rather than generated from
commits.

The [code of conduct](CODE_OF_CONDUCT.md#decisions) sets out how decisions about the project are made and how to
disagree with one.

## Changelog and version

Each implementation has its own `CHANGELOG.md` (`dotnet/`, `java/`, `php/`), in
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) style.

Add a heading for the new version, `## [X.Y.Z] - YYYY-MM-DD`, and list your changes under it. Do not add an `Unreleased`
section. Date the heading the day you finalise the entry. If the pull request merges on a later day, update the date
before it merges, or the maintainer will when merging. The version you write is the one released when the pull request
merges, and the tag and the Docker image take their version from it. Java's jar takes its version from `java/pom.xml`
instead, so set the same version there when you bump Java's changelog.

The Release workflow publishes on each push to `master`, using the `DOCKERHUB_USERNAME` and `DOCKERHUB_TOKEN` repository
secrets. A release that fails, for example because those secrets are not set, is tried again on the next push to
`master`, or when the maintainer runs the Release workflow by hand.

- If you change `shared/contract`, `shared/schema` or `shared/ui`, or anything observable from outside the application,
  bump `MINOR` (compatible) or `MAJOR` (breaking) in all three changelogs, with the same version and the same entry in
  each.
- If you change one implementation and nothing observable, such as a dependency update or an internal fix, bump that
  implementation's `PATCH`, in its changelog only.
- If you change only documentation, the demonstration stacks, tests, the test harness or development configuration,
  leave the changelogs alone. Development configuration means `appsettings.Development.json`, `launchSettings.json`,
  `application-dev.yml` and `application-dev-saml.yml`. The version tracks the software, and none of these are part of
  it.

The changelog check fails the pull request if the heading is missing, the bump does not match the files you changed, or
the three `MAJOR.MINOR` versions differ. The
[decision record on versioning](docs/adr/0038-shared-feature-versions-independent-patches-release-on-merge.md) explains
why versions work this way.

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
squash-merged, so a line in a branch commit does not reach `master`, and the description does.

## Formatting

Run the formatters before you push. `scripts/format.sh` (`scripts\format.ps1` on Windows) runs them all, skipping any
whose toolchain you do not have. `scripts/check.sh` (`scripts\check.ps1` on Windows) runs the formatting, sign-off and
changelog checks, with `--tests` (`-Tests` on Windows) for the unit tests as well. The integration suites run with
`scripts/integration.sh`, and the SonarQube Cloud quality gate runs only in continuous integration. Prettier formats the
Markdown, YAML, JSON, SCSS and CSS in the repository. To run it by hand:

```
npm install
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

Each implementation's README ([.NET](dotnet/README.md), [Java](java/README.md), [PHP](php/README.md)) says how to build,
configure and run it. The conformance, differential and accessibility suites run against all three implementations at
once, in the same Docker stack that continuous integration uses. `scripts/integration.sh` (`scripts\integration.ps1` on
Windows) builds it, runs the suites and tears it down. The first run downloads several gigabytes of images and takes a
while. The commands it runs:

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
is not complete until those suites pass against all three, not only the one you work in.
