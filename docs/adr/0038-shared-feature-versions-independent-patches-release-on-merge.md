# 38. Shared feature versions, independent patches, release on merge

Superseded by record 44 on 2026-10-07.

## Context

A feature is observable through the contract, so it reaches all three implementations. Some fixes are specific to one
stack, a dependency vulnerability most often, and forcing the other two to re-release unchanged code would be dishonest.
And `master` has to show exactly what has been released.

## Decision

`MAJOR.MINOR` is shared across the three and `PATCH` is independent. A change to `shared/contract`, `shared/schema` or
`shared/ui`, or any change observable from outside the application, bumps `MINOR` or `MAJOR` in all three at once. A
change to one implementation with nothing observable bumps that implementation's `PATCH`. Documentation, tests, the test
harness, the demonstrations and development configuration change no version. Each implementation has its own changelog
in Keep a Changelog style with no `Unreleased` section, and the author writes the dated version heading in the pull
request. Merging the pull request to `master` releases that version. Each push to `master` publishes every
implementation whose changelog version is not released yet, and a release that fails is tried again on the next push or
by a manual run. The tag, `<implementation>-v<version>`, carries that version, and the image is tagged with it, with its
`MAJOR.MINOR` and with `latest`. The first version is 1.0.0 for all three, and the contract files carry their own
versions.

## Consequences

- Equal `MAJOR.MINOR` means behaviourally identical. The suite enforces that
  ([record 36](0036-parity-is-defined-by-one-implementation-blind-suite.md)), not the version numbers.
- A dependency fix in one stack does not drag the other two into empty releases.
- Work that is not ready to ship stays on its branch. A merged version stays unreleased only while its release is
  failing.
- A changelog check gates every pull request.
- One product version across all three was rejected because it forces the other two stacks to re-release unchanged code.
  Post-merge release bots were rejected because they finalise after merge and leave an `Unreleased` state. Conventional
  Commits was rejected because it is the input to those bots and cannot express a shared minor with independent patches.
  A `0.x` first version was rejected because the organisations this is built for read version numbers as a risk signal.
  Aligning the software version with the contract's was rejected because the two drift the first time an implementation
  gains a feature the contract does not change for.
