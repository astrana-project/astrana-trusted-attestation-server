"""Tests for release_info.py. Run from the repository root with `python -m unittest discover -s .github/scripts`."""

import pathlib
import sys
import unittest

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))

import release_info  # noqa: E402

CHANGELOG = """# Changelog (PHP)

## [1.0.11] - 2026-10-14

### Changed

- The code that answers SAML logout requests was restructured.

## [1.0.10] - 2026-10-13

### Fixed

- Something.
"""


def plan(tags, changed_since=()):
    """The plan for the PHP implementation, where changed_since lists the tags since which its Dockerfile changed."""
    return release_info.plan("php", CHANGELOG, tags, lambda tag: tag in changed_since)


class NewVersion(unittest.TestCase):
    def test_a_version_with_no_tag_is_released(self):
        result = plan(["php-v1.0.10"])
        self.assertEqual(result["kind"], "version")
        self.assertEqual(result["tag"], "php-v1.0.11")
        self.assertEqual(result["label"], "1.0.11")
        self.assertEqual(result["title"], "PHP 1.0.11")
        self.assertEqual(result["image_tags"], ["1.0.11", "1.0", "latest"])
        self.assertIn("The code that answers SAML logout requests was restructured.", result["notes"])

    def test_a_new_version_is_released_whatever_the_dockerfile_did(self):
        self.assertEqual(plan([], changed_since=["php-v1.0.10"])["kind"], "version")


class Rebuild(unittest.TestCase):
    def test_a_dockerfile_change_after_the_release_publishes_the_first_rebuild(self):
        result = plan(["php-v1.0.10", "php-v1.0.11"], changed_since=["php-v1.0.11"])
        self.assertEqual(result["kind"], "rebuild")
        self.assertEqual(result["tag"], "php-v1.0.11+dr1")
        self.assertEqual(result["label"], "1.0.11+dr1")
        self.assertEqual(result["title"], "PHP 1.0.11+dr1")

    def test_a_rebuild_moves_only_the_major_minor_and_latest_tags(self):
        result = plan(["php-v1.0.11"], changed_since=["php-v1.0.11"])
        self.assertEqual(result["image_tags"], ["1.0", "latest"])

    def test_the_next_rebuild_counts_on_from_the_last_one(self):
        tags = ["php-v1.0.11", "php-v1.0.11+dr1", "php-v1.0.11+dr2"]
        result = plan(tags, changed_since=["php-v1.0.11", "php-v1.0.11+dr1", "php-v1.0.11+dr2"])
        self.assertEqual(result["tag"], "php-v1.0.11+dr3")

    def test_the_last_build_is_compared_by_number_not_by_text(self):
        tags = ["php-v1.0.11", "php-v1.0.11+dr2", "php-v1.0.11+dr10", "php-v1.0.11+dr9"]
        self.assertEqual(plan(tags, changed_since=["php-v1.0.11+dr10"])["tag"], "php-v1.0.11+dr11")
        self.assertEqual(plan(tags, changed_since=["php-v1.0.11+dr9"])["kind"], "none")

    def test_the_notes_say_the_application_is_unchanged(self):
        notes = plan(["php-v1.0.11"], changed_since=["php-v1.0.11"])["notes"]
        self.assertIn("The application is unchanged from 1.0.11", notes)
        self.assertNotIn("The code that answers SAML logout requests was restructured.", notes)

    def test_a_rebuild_of_another_version_is_not_counted(self):
        tags = ["php-v1.0.10", "php-v1.0.10+dr1", "php-v1.0.11"]
        self.assertEqual(plan(tags, changed_since=["php-v1.0.11"])["tag"], "php-v1.0.11+dr1")


class Nothing(unittest.TestCase):
    def test_no_dockerfile_change_since_the_last_build_publishes_nothing(self):
        self.assertEqual(plan(["php-v1.0.11"])["kind"], "none")

    def test_a_dockerfile_change_before_the_last_rebuild_publishes_nothing(self):
        tags = ["php-v1.0.11", "php-v1.0.11+dr1"]
        self.assertEqual(plan(tags, changed_since=["php-v1.0.11"])["kind"], "none")

    def test_another_implementations_tags_are_ignored(self):
        self.assertEqual(plan(["php-v1.0.11", "dotnet-v1.0.11+dr1"], changed_since=["php-v1.0.11"])["tag"],
                         "php-v1.0.11+dr1")


class Workflow(unittest.TestCase):
    """The release build has to install the base container images' operating system updates afresh, so a rebuild
    does not reuse a cached layer that installed the old ones."""

    WORKFLOW = (pathlib.Path(__file__).resolve().parents[1] / "workflows" / "release.yml").read_text(encoding="utf-8")

    def test_the_release_build_does_not_read_the_build_cache(self):
        self.assertNotIn("cache-from:", self.WORKFLOW)

    def test_the_release_build_turns_the_cache_off(self):
        self.assertIn("no-cache: true", self.WORKFLOW)


if __name__ == "__main__":
    unittest.main()
