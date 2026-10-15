# 49. Version numbers indicate what an update means, and container image changes are published as rebuilds without a new version

Accepted on 2026-10-15.

## Context

The operator, whoever runs the server, decides when to update it. The version number should indicate what an update
means to them, whether it only fixes defects, whether it adds to or changes how the server behaves, or whether they may
have to change their configuration or the systems connected to the server. It should also identify the application
exactly, so that two installations with the same version run the same application, and any change to the application
shows as a new version.

The server comes in three implementations that behave the same
([record 5](0005-three-implementations-held-to-behavioural-parity.md)). Many fixes concern one implementation only, such
as a fix for a vulnerability in one of its code libraries. A fix, a security fix most of all, should be published as
soon as it is merged.

Each implementation is published as a container image that its `Dockerfile` builds from base container images, the
images named in its `FROM` lines
([record 45](0045-the-published-container-images-contain-only-what-the-server-needs.md)). They include the image the
server runs on and the images that supply its runtime and build tools. Their publishers release security fixes between
the application's releases. The application has a version, and the container image is one build of that version from one
digest of each of its base container images.

## Decision

Each implementation's version has three numbers, `MAJOR.MINOR.PATCH`, and the number an update increments indicates what
it means to the operator:

- A new `PATCH` changes no behaviour except to fix a defect. It can also update a code library or change the code
  without changing what it does. An inconsistency, such as wording that differs from the rest of the interface, is a
  defect.
- A new `MINOR` adds or changes behaviour in a way that is backwards compatible, so the operator does not have to change
  their configuration or the systems connected to the server.
- A new `MAJOR` includes a change that is not backwards compatible, so the operator may have to change their
  configuration or the systems connected to the server.

Every change to the application gets a new version. The application is the implementation's own code, the shared
contract, schema and interface files it is built from, and the code libraries it runs with. Nothing else gets a version.
That covers documentation, tests, the test harness, the demonstrations, development configuration, the repository's
tooling, the code libraries only development and tests use, and the build files, the `Dockerfile` among them.

A change to the `Dockerfile`, such as a newer base container image digest, changes only the container image. Merged
without a new version, it publishes a container image rebuild, the same version built again, with no changelog entry.

A change to how the server behaves is the same change in all three, so it takes the same new `MINOR` or `MAJOR` in all
three at once, whether it is made in a shared file or in all three implementations' code. The three implementations
therefore share `MAJOR.MINOR`. `PATCH` is incremented on its own in each implementation, because a fix to one changes
nothing in the other two. A defect fix in a shared file gives each implementation its own next `PATCH`.

Each implementation has its own changelog with no unreleased section, and each new version's dated heading is written in
the pull request that makes the change. Merging a pull request to `master` releases any new version it adds, or
publishes a container image rebuild if it changes the `Dockerfile` without one, because on each push to `master` the
release workflow publishes every new version or container image rebuild that is not published yet. When publishing
fails, the workflow tries again on the next push or when it is run by hand.

## Consequences

- The version numbers follow Semantic Versioning, and the changelogs follow Keep a Changelog. The first version is 1.0.0
  for all three.
- A release's Git tag is `<implementation>-v<version>`, and its image is tagged with the full version, with
  `MAJOR.MINOR` and with `latest`. The full-version image tag always points to the first build and never moves. A
  container image rebuild moves `MAJOR.MINOR` and `latest` and adds no image tag. The image's digest identifies one
  exact build.
- A container image rebuild is named with Semantic Versioning's build metadata, which means the same version in a
  different build, so `1.0.10+dr1` is the first container image rebuild of 1.0.10, where `dr` stands for Docker
  revision. Image tags cannot contain `+`, so the Git tag, the GitHub release and the image's version label use the
  name. Each container image rebuild has a GitHub release that says the application is unchanged and gives the digest,
  and its image and bill of materials are signed like a version's
  ([record 44](0044-anyone-can-check-where-a-published-image-came-from.md)). The changelog changes only with the
  application.
- An operator who follows `latest` gets every version and container image rebuild, and one who follows `MAJOR.MINOR`
  gets every patch and container image rebuild of that `MAJOR.MINOR`. An operator who pins a full version keeps its
  first build and no container image rebuild after it. An operator who pins `MAJOR.MINOR` with a digest gets each update
  as a change of digest, which an update tool such as Renovate proposes.
- Equal `MAJOR.MINOR` means the three answer the same on everything the contract and the shared interface define
  ([record 37](0037-only-behaviour-the-contract-and-the-shared-interface-define-must-match.md)). The
  implementation-blind suite in `shared/test` enforces that
  ([record 36](0036-parity-is-defined-by-one-implementation-blind-suite.md)), not the version numbers.
- A defect fix to the database schema in `shared/schema` can be in a patch release, and the operator applies the schema
  change to their database by hand
  ([record 12](0012-the-schema-is-applied-from-the-shared-sql-files-there-are-no-migrations.md)).
- An update to a code library gets its version and changelog entry in its pull request whoever opens it, a bot included.
  A container image rebuild needs neither. A changelog check must pass on every pull request.
- One version shared by all three was rejected because it would make the other two stacks re-release unchanged code.
  Using the contract's version for the application was rejected because the two would differ the first time an
  implementation gains a feature the contract does not change for. A `0.x` first version was rejected because the
  organisations this is built for read a `0.x` version as not ready for production.
- Post-merge release bots, and the Conventional Commits messages they read, were rejected because they finalise the
  version after merge and leave merged changes unreleased on `master`.
- Making every change to a shared file a new `MINOR` in all three was rejected, because a `MINOR` for every corrected
  string would announce features that do not exist. Giving a shared defect fix the same new `PATCH` in all three was
  rejected because the patch numbers already differ by the fixes each stack has had on its own.
- Writing the entries for code library updates when the next release is cut was rejected, because with release on merge
  there is no release to cut, and the updates would sit on `master` unreleased.
- Leaving a new base container image to the implementation's next release was rejected because its security fixes would
  wait. Giving it a new `PATCH` was rejected because that would say the application changed.
- A suffix such as `1.0.10-r1` was rejected because Semantic Versioning reads it as an earlier pre-release of 1.0.10,
  and Renovate treats the suffix as a separate variant, so it never offers 1.0.11 to an operator on `1.0.10-r1`, or
  `1.0.10-r1` to one on 1.0.10. A fourth number, such as `1.0.10.1`, was rejected for the same reason, because Renovate
  never moves between three-part and four-part versions. Moving the full-version image tag was rejected because it
  changes the image behind a pin without the operator choosing that.
