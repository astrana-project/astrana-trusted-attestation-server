"""Tests for provenance.py. Run from the repository root with `python -m unittest discover -s .github/scripts`."""

import json
import pathlib
import sys
import unittest

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))

import provenance  # noqa: E402

AMD64 = "sha256:" + "a" * 64
ARM64 = "sha256:" + "b" * 64
PROVENANCE = "https://slsa.dev/provenance/v1"
SPDX = "https://spdx.dev/Document"


def statement(subject_digest, predicate_type=PROVENANCE):
    return {
        "_type": "https://in-toto.io/Statement/v1",
        "predicateType": predicate_type,
        "subject": [{"name": "pkg:docker/astrana/ata-php@latest", "digest": {"sha256": subject_digest[7:]}}],
        "predicate": {"buildDefinition": {"buildType": "https://mobyproject.org/buildkit@v1"}, "runDetails": {}},
    }


class Registry:
    """An image index for amd64 and arm64, as BuildKit pushes one, with an attestation manifest per platform image
    that holds a software bill of materials and a provenance record."""

    def __init__(self, provenance_for=None):
        provenance_for = provenance_for if provenance_for is not None else {AMD64: AMD64, ARM64: ARM64}
        self.manifests = {
            "sha256:index": {"mediaType": "application/vnd.oci.image.index.v1+json", "manifests": [
                {"digest": AMD64, "platform": {"os": "linux", "architecture": "amd64"}},
                {"digest": ARM64, "platform": {"os": "linux", "architecture": "arm64"}},
                self.attestation_entry("sha256:att-amd64", AMD64),
                self.attestation_entry("sha256:att-arm64", ARM64),
            ]},
        }
        self.blobs = {}
        for name, platform_digest in (("amd64", AMD64), ("arm64", ARM64)):
            layers = [self.layer(f"sha256:sbom-{name}", SPDX, statement(platform_digest, SPDX))]
            if platform_digest in provenance_for:
                layers.append(self.layer(f"sha256:prov-{name}", PROVENANCE, statement(provenance_for[platform_digest])))
            self.manifests[f"sha256:att-{name}"] = {"mediaType": "application/vnd.oci.image.manifest.v1+json",
                                                    "layers": layers}

    @staticmethod
    def attestation_entry(digest, platform_digest):
        return {"digest": digest, "platform": {"os": "unknown", "architecture": "unknown"},
                "annotations": {"vnd.docker.reference.type": "attestation-manifest",
                                "vnd.docker.reference.digest": platform_digest}}

    def layer(self, digest, predicate_type, content):
        self.blobs[digest] = json.dumps(content, indent=2).encode()
        return {"mediaType": "application/vnd.in-toto+json", "digest": digest,
                "annotations": {"in-toto.io/predicate-type": predicate_type}}

    def manifest(self, reference):
        return self.manifests[reference]

    def blob(self, digest):
        return self.blobs[digest]


class ProvenanceTest(unittest.TestCase):
    def test_writes_each_platform_images_provenance_record_on_its_own_line_in_index_order(self):
        registry = Registry()

        lines = provenance.statements("sha256:index", registry.manifest, registry.blob)

        self.assertEqual(len(lines), 2)
        self.assertEqual([json.loads(line)["subject"][0]["digest"]["sha256"] for line in lines],
                         [AMD64[7:], ARM64[7:]])
        self.assertTrue(all(json.loads(line)["predicateType"] == PROVENANCE for line in lines))

    def test_each_line_is_the_record_as_published_in_compact_json(self):
        registry = Registry()

        lines = provenance.statements("sha256:index", registry.manifest, registry.blob)

        self.assertEqual(json.loads(lines[0]), json.loads(registry.blob("sha256:prov-amd64")))
        self.assertNotIn("\n", lines[0])

    def test_a_platform_image_without_a_provenance_record_is_an_error(self):
        registry = Registry(provenance_for={AMD64: AMD64})

        with self.assertRaisesRegex(provenance.ProvenanceError, "arm64"):
            provenance.statements("sha256:index", registry.manifest, registry.blob)

    def test_a_record_that_names_another_image_is_an_error(self):
        registry = Registry(provenance_for={AMD64: AMD64, ARM64: AMD64})

        with self.assertRaisesRegex(provenance.ProvenanceError, "arm64"):
            provenance.statements("sha256:index", registry.manifest, registry.blob)


if __name__ == "__main__":
    unittest.main()
