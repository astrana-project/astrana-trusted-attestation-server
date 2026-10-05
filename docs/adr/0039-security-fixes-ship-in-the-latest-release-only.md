# 39. Security fixes ship in the latest release only

Accepted on 2026-10-05.

## Context

Releases are independent per implementation and a release happens on merge
([record 38](0038-shared-feature-versions-independent-patches-release-on-merge.md)). The organisations this is built for
run software for years and ask which versions receive security fixes, and backports are work the project cannot promise
to do.

## Decision

A security fix ships as a new release of the affected implementation, and only the latest release of each implementation
receives fixes. Nothing is backported. Within a major version every release is backwards compatible, so taking a fix
never brings a breaking change. When a new major version is released, its release notes say whether, and for how long,
the previous major version continues to receive fixes. The policy states what the maintainer aims to do. It is not a
guarantee.

## Consequences

- To get a fix, an organisation updates to the latest release, which the compatibility rule makes safe within a major
  version. That is the whole of the support policy. A fix that arrives in a feature release may bring a schema change,
  which whoever runs the server applies by hand
  ([record 12](0012-the-schema-is-applied-from-the-shared-sql-files-there-are-no-migrations.md)).
- The release to take is the latest of all three when the fix is in a shared input, and the latest of the affected
  implementation otherwise.
- An organisation that cannot update promptly carries the exposure until it does.
- Fixing a previous major version needs a release branch and a backport, which release on merge does not provide, and
  its release would move the image's `latest` tag back to that version. So a release note that promises fixes to a
  previous major version commits the project to that work. Maintained release branches with backports were rejected as
  the general policy because that is work the project cannot promise, and a promised backport that does not arrive is
  worse than a policy that says update.
- A fixed support window per version was rejected for the same reason. The major-version note is the one commitment the
  project can keep.
