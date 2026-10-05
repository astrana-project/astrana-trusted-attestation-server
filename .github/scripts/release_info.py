#!/usr/bin/env python3
"""Reads what an implementation releases from its changelog, for the release workflow.

The top dated version heading in <implementation>/CHANGELOG.md is the release (decision record 38). This prints the version, its
MAJOR.MINOR, the tag it is released under and the title of its GitHub release, and writes that version's section of the
changelog, without the heading, to a file for the release notes. Under GitHub Actions the values go to $GITHUB_OUTPUT.

    python .github/scripts/release_info.py <dotnet|java|php> [notes-file]
"""

from __future__ import annotations

import os
import pathlib
import re
import sys

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


def main(argv: list[str]) -> int:
    if len(argv) not in (2, 3) or argv[1] not in NAMES:
        print(__doc__.strip(), file=sys.stderr)
        return 2

    implementation = argv[1]
    notes_file = pathlib.Path(argv[2] if len(argv) == 3 else "release-notes.md")

    try:
        info = release(implementation, (ROOT / implementation / "CHANGELOG.md").read_text(encoding="utf-8"))
    except ValueError as error:
        print(error, file=sys.stderr)
        return 1

    notes_file.write_text(info.pop("notes"), encoding="utf-8")
    lines = [f"{key}={value}" for key, value in info.items()] + [f"notes={notes_file}"]
    print("\n".join(lines))

    output = os.environ.get("GITHUB_OUTPUT")
    if output:
        with open(output, "a", encoding="utf-8") as handle:
            handle.write("\n".join(lines) + "\n")

    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
