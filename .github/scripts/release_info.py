#!/usr/bin/env python3
"""Decides what the release workflow publishes for an implementation, and prints it.

The top dated version heading in <implementation>/CHANGELOG.md is the version (decision record 49). When it has no Git
tag yet, the workflow releases it. When it is released and <implementation>/Dockerfile has changed since its last build,
the workflow publishes a container image rebuild of it, named with build metadata, such as 1.0.10+dr1. Otherwise there
is nothing to publish. This prints the kind of publication, the version, its MAJOR.MINOR, the Git tag, the version
label for the image, the title of the GitHub release and whether the image gets its full-version tag, and writes the
release notes to a file. Under GitHub Actions the values go to $GITHUB_OUTPUT.

    python .github/scripts/release_info.py <dotnet|java|php> [notes-file]
"""

from __future__ import annotations

import os
import pathlib
import re
import subprocess
import sys
from typing import Callable, Iterable

ROOT = pathlib.Path(__file__).resolve().parents[2]
NAMES = {"dotnet": ".NET", "java": "Java", "php": "PHP"}
# The same pattern changelog_check.py accepts, carriage return included, so a changelog that passed the check
# is one this can release.
HEADING = re.compile(r"^## \[(\d+)\.(\d+)\.(\d+)\] - (\d{4}-\d{2}-\d{2})[ \t]*\r?$", re.M)
NEXT_HEADING = re.compile(r"^## \[", re.M)


def release(implementation: str, changelog: str) -> dict[str, str]:
    match = HEADING.search(changelog)
    if match is None:
        raise ValueError(f"{implementation}/CHANGELOG.md has no dated version heading such as ## [1.0.0] - 2026-10-05")

    # One heading per version. A second heading for the same version would make the release notes whichever
    # section came first and leave the other unpublished, with no error to say so.
    seen: set[str] = set()
    for heading in HEADING.finditer(changelog):
        candidate = ".".join(heading.groups()[:3])
        if candidate in seen:
            raise ValueError(f"{implementation}/CHANGELOG.md has two headings for version {candidate}. Merge them into one.")
        seen.add(candidate)

    major, minor, patch, _ = match.groups()
    version = f"{major}.{minor}.{patch}"
    following = NEXT_HEADING.search(changelog, match.end())
    notes = changelog[match.end() : following.start() if following else len(changelog)].strip()

    return {
        "version": version,
        "minor": f"{major}.{minor}",
        "tag": f"{implementation}-v{version}",
        "title": f"{NAMES[implementation]} {version}",
        "notes": notes + "\n",
    }


REBUILD = re.compile(r"\+dr(\d+)$")


def plan(
    implementation: str, changelog: str, tags: Iterable[str], dockerfile_changed_since: Callable[[str], bool]
) -> dict:
    """What to publish for the version at the top of the changelog. tags are the repository's Git tags, and
    dockerfile_changed_since tells whether the implementation's Dockerfile has changed since a given tag."""
    info = release(implementation, changelog)
    version, tag = info["version"], info["tag"]
    builds = {}
    for name in tags:
        if name == tag:
            builds[0] = name
        elif name.startswith(tag + "+"):
            rebuild = REBUILD.search(name)
            if rebuild:
                builds[int(rebuild.group(1))] = name

    if not builds:
        return {**info, "kind": "version", "label": version, "image_tags": [version, info["minor"], "latest"]}

    last = max(builds)
    if not dockerfile_changed_since(builds[last]):
        return {**info, "kind": "none", "label": version, "image_tags": []}

    label = f"{version}+dr{last + 1}"
    notes = (
        f"The application is unchanged from {version}. This is a rebuild of its container image, after a change to "
        f"{implementation}/Dockerfile such as a newer base container image. The changelog lists what {version} "
        "changed.\n"
    )
    return {
        **info,
        "kind": "rebuild",
        "tag": f"{implementation}-v{label}",
        "label": label,
        "title": f"{NAMES[implementation]} {label}",
        "notes": notes,
        "image_tags": [info["minor"], "latest"],
    }


def _git(*arguments: str) -> subprocess.CompletedProcess:
    return subprocess.run(["git", *arguments], cwd=ROOT, capture_output=True, text=True)


def main(argv: list[str]) -> int:
    if len(argv) not in (2, 3) or argv[1] not in NAMES:
        print(__doc__.strip(), file=sys.stderr)
        return 2

    implementation = argv[1]
    notes_file = pathlib.Path(argv[2] if len(argv) == 3 else "release-notes.md")

    tags = _git("tag", "--list", f"{implementation}-v*").stdout.split()
    dockerfile = f"{implementation}/Dockerfile"

    def dockerfile_changed_since(tag: str) -> bool:
        return _git("diff", "--quiet", tag, "HEAD", "--", dockerfile).returncode != 0

    try:
        info = plan(implementation, (ROOT / dockerfile).parent.joinpath("CHANGELOG.md").read_text(encoding="utf-8"),
                    tags, dockerfile_changed_since)
    except ValueError as error:
        print(error, file=sys.stderr)
        return 1

    notes_file.write_text(info.pop("notes"), encoding="utf-8")
    info["full_tag"] = "true" if info.pop("image_tags")[:1] == [info["version"]] else "false"
    lines = [f"{key}={value}" for key, value in info.items()] + [f"notes={notes_file}"]
    print("\n".join(lines))

    output = os.environ.get("GITHUB_OUTPUT")
    if output:
        with open(output, "a", encoding="utf-8") as handle:
            handle.write("\n".join(lines) + "\n")

    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
