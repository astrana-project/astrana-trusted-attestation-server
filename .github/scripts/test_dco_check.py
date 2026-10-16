"""Tests for dco_check.py. Run from the repository root with `python -m unittest discover -s .github/scripts`."""

import os
import pathlib
import subprocess
import tempfile
import unittest

SCRIPT = pathlib.Path(__file__).resolve().parent / "dco_check.py"
DEPENDABOT = ("dependabot[bot]", "49699333+dependabot[bot]@users.noreply.github.com")
PERSON = ("Alex Example", "alex@example.org")
# The sign-off Dependabot adds, whose address differs from its commit author's.
DEPENDABOT_SIGN_OFF = "Signed-off-by: dependabot[bot] <support@github.com>"


class DcoCheckTest(unittest.TestCase):
    def setUp(self):
        self.repo = tempfile.TemporaryDirectory()
        self.git("init", "-q", "-b", "master")
        self.commit(PERSON, "base", signed=True)
        self.git("branch", "base")

    def tearDown(self):
        self.repo.cleanup()

    def git(self, *arguments, author=PERSON):
        name, email = author
        environment = dict(os.environ, GIT_AUTHOR_NAME=name, GIT_AUTHOR_EMAIL=email, GIT_COMMITTER_NAME=name,
                           GIT_COMMITTER_EMAIL=email)
        return subprocess.run(["git", *arguments], cwd=self.repo.name, env=environment, check=True,
                              capture_output=True, text=True)

    def commit(self, author, message, signed, trailer=None):
        path = pathlib.Path(self.repo.name) / "file.txt"
        path.write_text(path.read_text() + message + "\n" if path.exists() else message + "\n")
        self.git("add", "file.txt", author=author)
        extra = (["-s"] if signed else []) + (["-m", trailer] if trailer else [])
        self.git("commit", "-q", "-m", message, *extra, author=author)

    def dependabot_commit(self):
        self.commit(DEPENDABOT, "Bump a library", signed=False, trailer=DEPENDABOT_SIGN_OFF)

    def check(self, pull_request_author=""):
        environment = dict(os.environ, PULL_REQUEST_AUTHOR=pull_request_author)
        return subprocess.run(["python3" if os.name != "nt" else "python", str(SCRIPT), "base"], cwd=self.repo.name,
                              env=environment, capture_output=True, text=True)

    def test_a_signed_off_commit_passes(self):
        self.commit(PERSON, "change", signed=True)
        self.assertEqual(self.check().returncode, 0)

    def test_a_commit_without_a_sign_off_fails(self):
        self.commit(PERSON, "change", signed=False)
        self.assertEqual(self.check().returncode, 1)

    def test_dependabots_own_commit_passes_on_a_pull_request_dependabot_opened(self):
        self.dependabot_commit()
        self.assertEqual(self.check(pull_request_author="dependabot[bot]").returncode, 0)

    def test_a_commit_claiming_dependabots_identity_fails_on_anyone_elses_pull_request(self):
        self.dependabot_commit()
        self.assertEqual(self.check(pull_request_author="someone").returncode, 1)

    def test_a_persons_commit_on_a_dependabot_pull_request_still_needs_a_sign_off(self):
        self.dependabot_commit()
        self.commit(PERSON, "Add the changelog entry", signed=False)
        result = self.check(pull_request_author="dependabot[bot]")
        self.assertEqual(result.returncode, 1)
        self.assertIn("Alex Example", result.stdout)

    def test_a_persons_signed_off_commit_on_a_dependabot_pull_request_passes(self):
        self.dependabot_commit()
        self.commit(PERSON, "Add the changelog entry", signed=True)
        self.assertEqual(self.check(pull_request_author="dependabot[bot]").returncode, 0)


if __name__ == "__main__":
    unittest.main()
