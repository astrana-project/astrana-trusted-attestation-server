# Versions and releases

The .NET, Java and PHP implementations of the Astrana Trusted Attestation Server are each released on their own, with a
version of three numbers, `MAJOR.MINOR.PATCH`, following [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## What each number means

- A new `PATCH` fixes a defect, updates a dependency or changes the code without changing what it does, such as a
  refactor. An inconsistency, such as wording that differs from the rest of the interface, counts as a defect.
- A new `MINOR` adds or changes functionality in a way that is backwards compatible.
- A new `MAJOR` includes a change that is not backwards compatible.

Every change to an implementation's code, its structure or the packages its code depends on gets a new version, so the
version always tells you whether the code changed. A change to documentation, tests, the packages only development and
tests use, the demonstrations or the base images the published images are built on does not.

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

Read the entries between your version and the new one before you update. A release that changes the database schema says
so in its entry, and you apply the change by hand. To hear about new releases, watch the repository's releases on
GitHub.

Every published image carries three tags, its full version such as `1.0.0`, its major and minor version such as `1.0`,
and `latest`. Pull the full version, so that the image changes only when you choose to update. Each implementation's
installation page shows how.

[SECURITY.md](../SECURITY.md#supported-versions) says which releases get security fixes.

## Why versions work this way

[Decision record 44](adr/0044-version-numbers-say-what-an-update-means.md) gives the reasons. To choose the version for
a change you are contributing, see [Changelog and version](../CONTRIBUTING.md#changelog-and-version) in CONTRIBUTING.md.
