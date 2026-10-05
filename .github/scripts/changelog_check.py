#!/usr/bin/env python3
"""Fails when the per-implementation changelogs do not match the change in a pull request (docs/adr/0038).

Usage: changelog_check.py <base-ref>

Rules:
- No changelog has an Unreleased section.
- A changelog's first version heading, when it has one, is `## [X.Y.Z] - YYYY-MM-DD` with a valid date.
- The three implementations' MAJOR.MINOR versions are equal.
- A change under shared/contract, shared/schema or shared/ui needs a new version in all three changelogs,
  identical across them, that bumps MINOR or MAJOR from the base.
- A change to one implementation's code needs a new PATCH version in that changelog only.
- A change to all three implementations' code may instead step all three changelogs to the same new MINOR or
  MAJOR, which is how an observable change that touches no shared file is released.
- A change to nothing released (documentation, demo stacks, an implementation's tests, the test harness,
  repository tooling) needs no changelog change, and a version bump without a corresponding change is an error.
"""

import datetime
import re
import subprocess
import sys

IMPLS = ("dotnet", "java", "php")
SHARED = ("shared/contract/", "shared/schema/", "shared/ui/")
# The optional carriage return accepts a changelog saved with Windows line endings, which `$` alone would
# not: in multiline mode it matches before the line feed, and the carriage return before that would be
# an unexpected character on the heading line.
HEADING = re.compile(r"^## \[(\d+)\.(\d+)\.(\d+)\] - (\d{4}-\d{2}-\d{2})[ \t]*\r?$", re.M)
ANY_HEADING = re.compile(r"^## \[", re.M)
UNRELEASED = re.compile(r"^## \[?unreleased\]?", re.M | re.I)


def git(*args: str) -> str:
    return subprocess.run(["git", *args], capture_output=True, text=True, check=True).stdout


def file_at(ref: str, path: str) -> str:
    result = subprocess.run(["git", "show", f"{ref}:{path}"], capture_output=True, text=True)
    return result.stdout if result.returncode == 0 else ""


def top_version(text: str):
    match = HEADING.search(text)
    return (int(match[1]), int(match[2]), int(match[3]), match[4]) if match else None


TEST_DIRS = ("/tests/", "/src/test/")
TEST_FILES = ("phpunit.xml", "coverage.runsettings", "sonar-project.properties")
# Development configuration, which .dockerignore keeps out of every image and the Java build keeps out of the jar.
DEV_FILES = ("appsettings.Development.json", "launchSettings.json", "application-dev.yml", "application-dev-saml.yml")


def released_code(path: str) -> bool:
    """True for files that end up in a released artefact. Tests, test and development configuration are not."""
    unreleased = TEST_FILES + DEV_FILES
    if path.endswith(".md") or path.endswith(unreleased) or any(d in path for d in TEST_DIRS):
        return False
    for impl in IMPLS:
        if path.startswith(f"{impl}/"):
            return not path.startswith(f"{impl}/demo/")
    return path.startswith(SHARED)


def step(old, new) -> str | None:
    if old is None:
        return "first"
    major, minor, patch = old[:3]
    if new[:3] == (major + 1, 0, 0):
        return "major"
    if new[:3] == (major, minor + 1, 0):
        return "minor"
    if new[:3] == (major, minor, patch + 1):
        return "patch"
    return None


def version_text(v) -> str:
    return f"{v[0]}.{v[1]}.{v[2]}" if v else "none"


def heading(v) -> str:
    return f"`## [{version_text(v)}] - YYYY-MM-DD`"


def next_version(old, part: str):
    if old is None:
        return None
    major, minor, patch = old[:3]
    return (major, minor, patch + 1) if part == "patch" else (major, minor + 1, 0)


def main() -> int:
    base = sys.argv[1] if len(sys.argv) > 1 else "origin/master"
    files = [f for f in git("diff", "--name-only", f"{base}...HEAD").splitlines() if f]

    shared_change = any(f.startswith(SHARED) and released_code(f) for f in files)
    impl_changes = {impl for impl in IMPLS for f in files if f.startswith(f"{impl}/") and released_code(f)}

    errors = []
    head, before = {}, {}
    for impl in IMPLS:
        path = f"{impl}/CHANGELOG.md"
        text = file_at("HEAD", path)
        head[impl] = top_version(text)
        before[impl] = top_version(file_at(base, path))
        if UNRELEASED.search(text):
            errors.append(f"{path}: has an Unreleased section. Write the version that merges instead.")
        if ANY_HEADING.search(text) and head[impl] is None:
            errors.append(f"{path}: the first version heading must be `## [X.Y.Z] - YYYY-MM-DD`.")
        if head[impl]:
            try:
                datetime.date.fromisoformat(head[impl][3])
            except ValueError:
                errors.append(f"{path}: {head[impl][3]} is not a valid date.")

    present = {impl: v for impl, v in head.items() if v}
    if present and len(present) != len(IMPLS):
        missing = ", ".join(i for i in IMPLS if i not in present)
        errors.append(f"Every changelog needs a version once any has one. Missing: {missing}.")
    if len({v[:2] for v in present.values()}) > 1:
        summary = ", ".join(f"{i} {version_text(v)}" for i, v in present.items())
        errors.append(f"MAJOR.MINOR must be equal across the three implementations: {summary}.")

    changed = {impl: head[impl] != before[impl] for impl in IMPLS}
    kind = {impl: step(before[impl], head[impl]) if changed[impl] and head[impl] else None for impl in IMPLS}

    # An observable change made in all three implementations is a feature release even when nothing under
    # shared/ moved: all three changelogs step to the same new MINOR or MAJOR together.
    same_new_version = all(head.values()) and len({v[:3] for v in head.values()}) == 1
    all_three_feature = (
        impl_changes == set(IMPLS)
        and same_new_version
        and all(kind[impl] in ("minor", "major", "first") for impl in IMPLS)
    )
    feature_change = shared_change or all_three_feature
    feature_label = "a shared change" if shared_change else "an observable change in all three implementations"

    for impl in IMPLS:
        path = f"{impl}/CHANGELOG.md"
        if changed[impl] and head[impl] is None:
            errors.append(f"{path}: the version heading was removed.")
            continue
        if changed[impl] and kind[impl] is None:
            errors.append(
                f"{path}: {version_text(before[impl])} to {version_text(head[impl])} is not a single "
                "MAJOR, MINOR or PATCH step."
            )
            continue
        if feature_change:
            if not changed[impl]:
                hint = (f", for example {heading(next_version(before[impl], 'minor'))} above {heading(before[impl])}"
                        if before[impl] else ", the same first version in all three")
                errors.append(f"{path}: {feature_label} needs a new MINOR or MAJOR version in all three changelogs{hint}.")
            elif kind[impl] == "patch":
                errors.append(f"{path}: {feature_label} bumps MINOR or MAJOR, not PATCH.")
        elif impl in impl_changes:
            if not changed[impl]:
                hint = (f", add {heading(next_version(before[impl], 'patch'))} above {heading(before[impl])} with your entry under it"
                        if before[impl] else ", add a first version heading")
                errors.append(f"{path}: {impl}/ changed, so it needs a new PATCH version{hint}.")
            elif kind[impl] not in ("patch", "first"):
                errors.append(
                    f"{path}: an implementation-only change bumps PATCH, not {kind[impl].upper()}. A MINOR or "
                    "MAJOR is accepted only when all three implementations change and all three changelogs move "
                    "to the same new version."
                )
        elif changed[impl]:
            errors.append(f"{path}: version changed but nothing released under {impl}/ or shared/ did.")

    if shared_change and all(head.values()) and not same_new_version:
        summary = ", ".join(f"{i} {version_text(v)}" for i, v in head.items())
        errors.append(f"A shared change writes the same version to all three changelogs: {summary}.")

    if errors:
        print("Changelog check failed:")
        for error in errors:
            print(f"  {error}")
        print("\nSee CONTRIBUTING.md, Changelog and version.")
        return 1

    what = feature_label if feature_change else (", ".join(sorted(impl_changes)) or "nothing released")
    print(f"Changelogs match the change ({what}).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
