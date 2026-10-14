#!/usr/bin/env python3
"""Writes the provenance record of each platform image in a published image index to a file, one in-toto statement per
line, for the Release workflow to sign and attach to the GitHub release.

Usage: provenance.py <repository> <index-digest> <output-file>, for example
       provenance.py astrana/ata-php sha256:0123... php.intoto.jsonl

BuildKit attaches each platform image's provenance record to the image index as an in-toto statement, in an attestation
manifest that names the platform image it describes. The statements are read from Docker Hub as published, so the file
contains exactly the records the image's signature covers, in the order the index lists the platform images.
"""
import json
import sys
import urllib.parse
import urllib.request

PROVENANCE_TYPE = "https://slsa.dev/provenance/"
REGISTRY = "https://registry-1.docker.io"
MANIFEST_TYPES = ", ".join((
    "application/vnd.oci.image.index.v1+json",
    "application/vnd.oci.image.manifest.v1+json",
    "application/vnd.docker.distribution.manifest.list.v2+json",
    "application/vnd.docker.distribution.manifest.v2+json",
))


class ProvenanceError(Exception):
    """The index does not have exactly one provenance record for each platform image."""


def statements(index_digest, manifest, blob):
    """The provenance statement of each platform image in the index, as compact JSON lines. manifest and blob fetch a
    manifest by digest and a blob by digest."""
    index = manifest(index_digest)
    attestations = {
        entry["annotations"]["vnd.docker.reference.digest"]: entry["digest"]
        for entry in index["manifests"]
        if entry.get("annotations", {}).get("vnd.docker.reference.type") == "attestation-manifest"
    }
    platforms = [entry for entry in index["manifests"] if entry.get("platform", {}).get("os") not in (None, "unknown")]
    return [_statement(entry, attestations.get(entry["digest"]), manifest, blob) for entry in platforms]


def _statement(platform_entry, attestation_digest, manifest, blob):
    platform = platform_entry["platform"]["os"] + "/" + platform_entry["platform"]["architecture"]
    layers = manifest(attestation_digest)["layers"] if attestation_digest else []
    records = [layer for layer in layers
               if layer.get("annotations", {}).get("in-toto.io/predicate-type", "").startswith(PROVENANCE_TYPE)]
    if len(records) != 1:
        raise ProvenanceError(f"the {platform} image has {len(records)} provenance records, not one")
    content = json.loads(blob(records[0]["digest"]))
    subjects = {"sha256:" + subject["digest"].get("sha256", "") for subject in content.get("subject", [])}
    if platform_entry["digest"] not in subjects:
        raise ProvenanceError(f"the {platform} image's provenance record names another image")
    return json.dumps(content, separators=(",", ":"))


def _token(repository):
    query = urllib.parse.urlencode({"service": "registry.docker.io", "scope": f"repository:{repository}:pull"})
    with urllib.request.urlopen(f"https://auth.docker.io/token?{query}") as response:
        return json.load(response)["token"]


def _fetcher(repository):
    token = _token(repository)

    def get(path, accept=None):
        headers = {"Authorization": f"Bearer {token}"}
        if accept:
            headers["Accept"] = accept
        request = urllib.request.Request(f"{REGISTRY}/v2/{repository}/{path}", headers=headers)
        with urllib.request.urlopen(request) as response:
            return response.read()

    return (lambda digest: json.loads(get(f"manifests/{digest}", MANIFEST_TYPES)),
            lambda digest: get(f"blobs/{digest}"))


def main(arguments):
    if len(arguments) != 3:
        sys.exit(__doc__)
    repository, index_digest, output = arguments
    manifest, blob = _fetcher(repository)
    lines = statements(index_digest, manifest, blob)
    with open(output, "w", encoding="utf-8", newline="\n") as file:
        file.write("\n".join(lines) + "\n")
    print(f"Wrote {len(lines)} provenance records to {output}.")


if __name__ == "__main__":
    main(sys.argv[1:])
