# 45. Anyone can check where a published image came from

Accepted on 2026-10-08.

## Context

Each release of an implementation publishes its container image and a GitHub release that includes its software bill of
materials, the list of every component the image contains
([record 44](0044-version-numbers-say-what-an-update-means.md)). Whoever pulls the image or downloads the bill of
materials needs to be able to check where it came from. It should be the one this repository's release workflow built
and published, and not something published in its place under the same name.

A signature shows that only if nobody else could have made it. A signature made with a long-lived key shows only that
someone had the key.

## Decision

The release workflow, running on `master`, is the only publisher, and it signs what it publishes. It signs each image by
its digest, never by a tag, and each release's bill of materials. Each signature is made with a short-lived certificate
issued to the workflow's own identity, which is the repository, the workflow file and the branch, and is recorded in a
public log. There is no signing key that a person keeps.

Each image also comes with a provenance attestation, a statement of where and how the image was built, naming the
repository, the commit and the build steps. It also comes with a bill of materials attestation. The image's signature
covers both. The workflow publishes the image without a tag, and checks both signatures against the workflow's identity
before it tags the image or creates the release. A missing or wrong signature stops the release.

## Consequences

- Today the signing is done with cosign, keylessly through Sigstore, and each signature is recorded in Sigstore's public
  transparency log. The attestations are BuildKit's, attached to the image index, which the signature covers because it
  lists them.
- There is no signing key to store, rotate, leak or lose.
- The signing identity is the repository, the workflow file's path and the branch. Renaming any of them changes it, and
  anyone who checks against the old identity sees new releases fail until they check against the new one.
- Releases depend on the Sigstore service. When it cannot sign or verify, the run fails before the image or the commit
  is tagged or the GitHub release is created, and the next run tries again. No image tag, Git tag or GitHub release
  exists without both signatures. The failed run leaves an untagged image behind, and the next run publishes and signs a
  new one.
- The image of a release published before signing began can be signed later by the same workflow, so its signature
  carries the same identity. Its image keeps the attestations it was built with, because they cannot change without
  republishing it. Before signing, the workflow checks that the image's revision label names the release's commit. The
  check catches a mistake, not an attack, because whoever can publish the image can also set its label.
- Such a release's bill of materials stays unsigned, because the repository's GitHub releases are immutable and accept
  no new file once published. GitHub's own signed attestation of each immutable release covers its files, so the bill of
  materials can still be checked against it.
- A long-lived signing key was rejected because anyone who obtained it could sign anything, so a signature would not
  show that the release workflow published the release. GNU Privacy Guard (GPG) signatures were rejected for the same
  reason, and because container tooling does not check them, so few of the people pulling an image would check one.
  GitHub's build provenance attestation action was rejected because it would let nobody check anything the signed
  BuildKit attestations do not already let them check, and it would add a second signing mechanism with its own
  permission and its own verification tool.
