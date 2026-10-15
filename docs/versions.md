# Versions and releases

The .NET, Java and PHP implementations of the Astrana Trusted Attestation Server are each released on their own, with a
version of three numbers, `MAJOR.MINOR.PATCH`, following [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## What each number means

- A new `PATCH` changes no behaviour except to fix a defect. It can also update a code library or change the code
  without changing what it does, such as a refactor. An inconsistency, such as wording that differs from the rest of the
  interface, counts as a defect.
- A new `MINOR` adds or changes behaviour in a way that is backwards compatible, so you do not have to change your
  configuration or the systems connected to the server.
- A new `MAJOR` includes a change that is not backwards compatible, so you may have to change your configuration or the
  systems connected to the server.

Every change to the application gets a new version, so the version always tells you whether the application changed. The
application is the implementation's own code, the shared contract, schema and interface files it is built from, and the
code libraries it runs with. A change to documentation, tests, the code libraries only development and tests use, the
demonstrations or the `Dockerfile` does not.

## The three implementations

The three implementations share `MAJOR.MINOR`. Equal `MAJOR` and `MINOR` versions mean the three answer the same on
everything the contract and the shared interface define, so you can choose or change implementation without members or
verifying Astrana instances seeing a difference.

`PATCH` moves on its own in each implementation, because a fix to one does not release the other two. A defect fix in
something the three share brings a new `PATCH` of each, and the three patch numbers can differ.

## Updating

Each implementation's changelog lists what every release changed:

- [.NET changelog](../dotnet/CHANGELOG.md)
- [Java changelog](../java/CHANGELOG.md)
- [PHP changelog](../php/CHANGELOG.md)

The self-service page shows the version a server is running in its footer. Read the entries between that version and the
new one before you update. A release that changes the database schema says so in its entry, and you apply the change by
hand. To hear about new releases, watch the repository's releases on GitHub.

Every published image carries three tags, its full version such as `1.0.0`, its major and minor version such as `1.0`,
and `latest`. When an implementation's `Dockerfile` changes, for example to take fixes to the base container images it
is built from, its latest release is rebuilt. The rebuild moves the major and minor version and `latest`, has a GitHub
release that says the application is unchanged, and has no changelog entry. The full-version tag always points to the
release's first build. Pull the major and minor version and run it by digest, so that you get every rebuild and choose
when the image changes. Each implementation's installation page shows how.

[SECURITY.md](../SECURITY.md#supported-versions) says which releases get security fixes.

## Why versions work this way

[Decision record 49](adr/0049-version-numbers-indicate-what-an-update-means-and-container-image-changes-are-published-as-rebuilds-without-a-new-version.md)
gives the reasons. To choose the version for a change you are contributing, see
[Changelog and version](../CONTRIBUTING.md#changelog-and-version) in CONTRIBUTING.md.
