# 44. Version numbers say what an update means

Accepted on 2026-10-07.

## Context

Whoever runs the server decides when to update it. The version number is the first thing they see, and it should tell
them what an update means for them: whether it only fixes things, whether it adds something, and whether they may have
to change something on their side. It should also make it easy to see that the code changed, so that two copies with the
same version are the same code.

The server comes in three implementations that behave the same
([record 5](0005-three-implementations-held-to-behavioural-parity.md)). A change in behaviour reaches all three. Many
fixes are specific to one stack, a dependency's vulnerability most often. And `master` has to show exactly what has been
released.

## Decision

A version has three numbers, `MAJOR.MINOR.PATCH`, and each new one tells whoever runs the server one thing:

- A new `PATCH` changes nothing they rely on. It fixes a defect, updates a dependency, or changes the code without
  changing what it does. An inconsistency, such as wording that differs from the rest of the interface, is a defect.
- A new `MINOR` adds or changes behaviour, and everything that worked before still works.
- A new `MAJOR` includes a change that is not backwards compatible, so they may have to change something on their side.

Every change to what an implementation ships gets a new version. What ships is the implementation's own code, the shared
contract, schema and interface files it is built from, and the packages it runs with. Nothing else is the software, so
nothing else gets a version. That covers documentation, tests, the test harness, the demonstrations, development
configuration, the repository's tooling, development and test dependencies, and build files that do not ship. Base
images are infrastructure for running the software, not part of it, so a new base image is not a release of its own.

The three implementations share `MAJOR.MINOR`, because they behave the same, so a change in behaviour is the same change
in each. It takes the same new `MINOR` or `MAJOR` in all three at once, whether it is made in a shared file or in all
three implementations' code. `PATCH` moves on its own in each implementation, because a fix to one changes nothing in
the other two. A defect fix in a shared file gives each implementation its own next `PATCH`, so the three patch numbers
can differ.

Each implementation has its own changelog with no unreleased section, and the author writes the dated version heading in
the pull request. Merging the pull request to `master` releases that version. Each push to `master` publishes every
implementation whose changelog version is not released yet, and a release that fails is tried again on the next push or
by a manual run.

## Consequences

- The numbers agree with Semantic Versioning. The changelogs follow Keep a Changelog. The first version is 1.0.0 for all
  three, and the contract files carry their own versions.
- A release is tagged `<implementation>-v<version>`, and its image is tagged with the full version, with `MAJOR.MINOR`
  and with `latest`.
- Equal `MAJOR.MINOR` means behaviourally identical. The suite enforces that
  ([record 36](0036-parity-is-defined-by-one-implementation-blind-suite.md)), not the version numbers.
- A fix in one stack does not drag the other two into empty releases.
- A defect fix to `shared/schema` can arrive in a patch release, and whoever runs the server applies it by hand
  ([record 12](0012-the-schema-is-applied-from-the-shared-sql-files-there-are-no-migrations.md)).
- A dependency update is released like any other change, whoever opens it, with its changelog entry and version in its
  pull request. A changelog check gates every pull request, dependency updates included, and lists the build files that
  do not ship. So every update to a package an implementation ships is released, with a changelog entry, by the time it
  reaches `master`.
- The bills of materials change no version. A dependency change that alters one is released through the project or lock
  file that made it.
- A new base image reaches the published image with that implementation's next release.
- Work that is not ready to ship stays on its branch. A merged version stays unreleased only while its release is
  failing.
- One product version across all three was rejected because it would make the other two stacks re-release unchanged
  code. Aligning the software version with the contract's was rejected because the two drift the first time an
  implementation gains a feature the contract does not change for. A `0.x` first version was rejected because the
  organisations this is built for read version numbers as a risk signal.
- Post-merge release bots, and the Conventional Commits messages they read, were rejected because they finalise the
  version after merge and leave an unreleased state on `master`.
- Making every change to a shared file a new `MINOR` in all three was rejected, because a `MINOR` for every corrected
  string would announce features that do not exist. Giving a shared defect fix the same new `PATCH` in all three was
  rejected because the patch numbers already differ by the fixes each stack has had on its own.
- Writing the entries for dependency updates when the next release is cut was rejected, because with release on merge
  there is no release to cut, and the updates would sit on `master` unreleased.
