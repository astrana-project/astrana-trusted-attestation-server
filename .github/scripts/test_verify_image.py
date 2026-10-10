"""Tests for verify-image.sh. Run from the repository root with `python -m unittest discover -s .github/scripts`."""

import os
import pathlib
import stat
import subprocess
import tempfile
import unittest

SCRIPT = pathlib.Path(__file__).resolve().parent / "verify-image.sh"
IMAGE = "astrana/ata-php@sha256:" + "0" * 64

# Plays one outcome per call from FAKE_COSIGN_OUTCOMES, and records each call's arguments.
FAKE_COSIGN = """#!/usr/bin/env bash
calls="$FAKE_COSIGN_DIR/calls"
count=$(( $(wc -l < "$calls" 2>/dev/null || echo 0) + 1 ))
echo "$*" >> "$calls"
IFS=, read -ra outcomes <<< "$FAKE_COSIGN_OUTCOMES"
outcome=${outcomes[$((count - 1))]:-${outcomes[-1]}}
case $outcome in
  ok) echo '[{"critical":{}}]'; exit 0 ;;
  missing) echo 'Error: no signatures found' >&2; exit 10 ;;
  mismatch) echo 'Error: none of the expected identities matched what was in the certificate' >&2; exit 1 ;;
esac
"""


class VerifyImageTest(unittest.TestCase):
    def run_script(self, outcomes, attempts="6"):
        with tempfile.TemporaryDirectory() as directory:
            cosign = pathlib.Path(directory, "cosign")
            cosign.write_text(FAKE_COSIGN, newline="\n")
            cosign.chmod(cosign.stat().st_mode | stat.S_IEXEC)
            env = {
                **os.environ,
                "PATH": directory + os.pathsep + os.environ["PATH"],
                "FAKE_COSIGN_DIR": directory,
                "FAKE_COSIGN_OUTCOMES": outcomes,
                "SIGNER_IDENTITY_REGEXP": "^https://example\\.test/release\\.yml$",
                "SIGNER_ISSUER": "https://issuer.example.test",
                "VERIFY_ATTEMPTS": attempts,
                "VERIFY_DELAY": "0",
            }
            result = subprocess.run(["bash", str(SCRIPT), IMAGE], env=env, capture_output=True, text=True)
            calls_file = pathlib.Path(directory, "calls")
            calls = calls_file.read_text().splitlines() if calls_file.exists() else []
            return result, calls

    def test_a_signature_found_at_once_passes_after_one_check(self):
        result, calls = self.run_script("ok")

        self.assertEqual(result.returncode, 0)
        self.assertEqual(len(calls), 1)

    def test_a_signature_that_appears_late_passes_once_found(self):
        result, calls = self.run_script("missing,missing,ok")

        self.assertEqual(result.returncode, 0)
        self.assertEqual(len(calls), 3)

    def test_a_signature_that_never_appears_fails_after_the_last_attempt(self):
        result, calls = self.run_script("missing", attempts="4")

        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(len(calls), 4)
        self.assertIn("no signatures found", result.stderr)

    def test_any_other_failure_fails_at_once(self):
        result, calls = self.run_script("mismatch,ok")

        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(len(calls), 1)
        self.assertIn("none of the expected identities matched", result.stderr)

    def test_it_checks_the_image_against_the_signer_identity_and_issuer(self):
        _, calls = self.run_script("ok")

        self.assertEqual(
            calls[0],
            "verify --certificate-identity-regexp ^https://example\\.test/release\\.yml$ "
            f"--certificate-oidc-issuer https://issuer.example.test {IMAGE}",
        )


if __name__ == "__main__":
    unittest.main()
