# 50. Anyone can read how a published container image was built, without container tools

Accepted on 2026-10-15.

## Context

Each published container image comes with provenance attestations, statements of where and how it was built, one for
each processor it is built for, which the image's signature covers
([record 44](0044-anyone-can-check-where-a-published-image-came-from.md)). Reading them needs container tools that fetch
an image's attestations from the registry, so someone who has only the image's GitHub release cannot see how the image
was built.

## Decision

The GitHub release of each published image, whether a new version or a container image rebuild
([record 49](0049-version-numbers-indicate-what-an-update-means-and-container-image-changes-are-published-as-rebuilds-without-a-new-version.md)),
includes the provenance records from those attestations as a file anyone can read. The release workflow takes the
records from the image as published, so the file contains exactly the records the image's signature covers. It signs the
file the way it signs the bill of materials, and checks the signature before it creates the release.

## Consequences

- Today the file is `provenance.intoto.jsonl`, with one in-toto statement for each processor, read from Docker Hub. Its
  signature is `provenance.intoto.jsonl.sigstore.json`, which has the same signing identity as the image's signature.
- Both files are attached when the release is created, because the repository's GitHub releases accept no new file once
  published. Releases published before this decision have neither, and their images' attestations can still be read with
  container tools.
- If the image for any processor has no provenance record, has more than one, or has one that names another image, the
  release stops.
- Relying on the attestations alone was rejected, because only someone with container tools could read them.
- Building the file from the build's own output was rejected, because only the records as published are the ones the
  image's signature covers.
