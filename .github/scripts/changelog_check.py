#!/usr/bin/env python3
"""Fails when the per-implementation changelogs do not match the change in a pull request (docs/adr/0044).

Usage: changelog_check.py <base-ref>

Rules:
- No changelog has an Unreleased section.
- A changelog's first version heading, when it has one, is `## [X.Y.Z] - YYYY-MM-DD` with a valid date.
- The three implementations' MAJOR.MINOR versions are equal.
- Where a version is stated outside the changelogs, it is the implementation's top changelog version. These are
  java/pom.xml's project <version> and, in each sbom.json, metadata.component's version, bom-ref and purl.
- A change to a released file under shared/contract, shared/schema or shared/ui needs a new version in all three
  changelogs. New or materially different behaviour bumps the same new MINOR or MAJOR in all three. A defect fix or
  other change that is not functional may instead bump each changelog's PATCH, each one step above its own base version.
  Bumping PATCH in some and MINOR or MAJOR in others is an error.
- A change to one implementation's code needs a new PATCH version in that changelog only.
- A change to all three implementations' code may instead step all three changelogs to the same new MINOR or
  MAJOR, which is how an observable change that touches no shared file is released.
- A change to nothing released (documentation, demo stacks, an implementation's tests, the test harness,
  repository tooling, build files that never ship) needs no changelog change, and a version bump without a
  corresponding change is an error. Build files that never ship are the Dockerfiles, the stylesheet's npm files and
  Sass source, the PHP build scripts, the bills of materials, .NET restore settings and lock files that change no
  package version, and changes to java/pom.xml made only of comments, layout, the project's own version and
  build-only plugins (JaCoCo without offline instrumentation, and Spotless). Development-only dependencies never ship
  either. These are require-dev in php/composer.json, packages-dev in php/composer.lock and test-scope dependencies
  in java/pom.xml, and php/composer.lock's content hash changes with any change to php/composer.json.
"""

import datetime
import json
import re
import subprocess
import sys
import xml.etree.ElementTree as ET

IMPLS = ("dotnet", "java", "php")
SHARED = ("shared/contract/", "shared/schema/", "shared/ui/")
# The optional carriage return accepts a changelog saved with Windows line endings, which `$` alone would
# not: in multiline mode it matches before the line feed, and the carriage return before that would be
# an unexpected character on the heading line.
HEADING = re.compile(r"^## \[(\d+)\.(\d+)\.(\d+)\] - (\d{4}-\d{2}-\d{2})[ \t]*\r?$", re.M)
ANY_HEADING = re.compile(r"^## \[", re.M)
UNRELEASED = re.compile(r"^## \[?unreleased\]?", re.M | re.I)


def git(*args: str) -> str:
    return subprocess.run(["git", *args], capture_output=True, text=True, encoding="utf-8", check=True).stdout


def file_at(ref: str, path: str) -> str:
    result = subprocess.run(
        ["git", "show", f"{ref}:{path}"], capture_output=True, text=True, encoding="utf-8", errors="replace"
    )
    return result.stdout if result.returncode == 0 else ""


def top_version(text: str):
    match = HEADING.search(text)
    return (int(match[1]), int(match[2]), int(match[3]), match[4]) if match else None


TEST_DIRS = ("/tests/", "/src/test/")
TEST_FILES = ("phpunit.xml", "coverage.runsettings", "sonar-project.properties")
# Development configuration, which .dockerignore keeps out of every image and the Java build keeps out of the jar.
DEV_FILES = ("appsettings.Development.json", "launchSettings.json", "application-dev.yml", "application-dev-saml.yml")
# Build files that never ship themselves. The images are infrastructure, so a Dockerfile change rides along with the
# next release. The stylesheet's npm files and Sass source only build shared/ui/dist, which is released. The PHP
# scripts run during the install or the image build, never in the running server, and a script not named here counts
# as released until someone checks what runs it. A bill of materials is attached to the release, not part of the
# software, and a dependency change that alters it is released through the project or lock file that made it.
BUILD_FILES = tuple(f"{impl}/{name}" for impl in IMPLS for name in ("Dockerfile", "sbom.json")) + (
    "shared/ui/package.json",
    "shared/ui/package-lock.json",
    "php/scripts/add-native-sbom-components.php",
    "php/scripts/generate-third-party-notices.php",
    "php/scripts/sync-shared-assets.php",
)
BUILD_DIRS = ("shared/ui/src/",)
# Project file settings that only steer the NuGet restore, which the lock file then records.
RESTORE_SETTINGS = re.compile(
    r"<(RestorePackagesWithLockFile|RestoreLockedMode|RuntimeIdentifiers)\b[^>]*>[^<]*</\1>"
)
XML_COMMENT = re.compile(r"<!--.*?-->", re.S)
EMPTY_PROPERTY_GROUP = re.compile(r"<PropertyGroup\b[^>]*>\s*</PropertyGroup>")
# Maven plugins that never change what goes into the jar. JaCoCo's agent records coverage while the tests run and its
# report reads the result. Its offline instrumentation rewrites the compiled classes, so a plugin element that uses it
# counts as released. Spotless formats and checks the source and compiles nothing.
BUILD_ONLY_PLUGINS = ("jacoco-maven-plugin", "spotless-maven-plugin")
POM = "java/pom.xml"
COMPOSER_JSON = "php/composer.json"
COMPOSER_LOCK = "php/composer.lock"
NUGET_LOCK = "/packages.lock.json"


def released_code(path: str) -> bool:
    """True for files that end up in a released artefact. Tests, test and development configuration and the build
    files that never ship are not."""
    unreleased = TEST_FILES + DEV_FILES
    if path.endswith(".md") or path.endswith(unreleased) or any(d in path for d in TEST_DIRS):
        return False
    if path in BUILD_FILES or path.startswith(BUILD_DIRS):
        return False
    for impl in IMPLS:
        if path.startswith(f"{impl}/"):
            return not path.startswith(f"{impl}/demo/")
    return path.startswith(SHARED)


def resolved_versions(lock_text: str) -> dict:
    """Every package a NuGet lock file resolves, by target, with its version."""
    targets = json.loads(lock_text)["dependencies"]
    return {
        (target, name): entry.get("resolved") for target, entries in targets.items() for name, entry in entries.items()
    }


def project_without_restore_settings(text: str) -> list[str]:
    """A project file's lines with comments, restore-only settings, blank lines and line endings left out."""
    text = EMPTY_PROPERTY_GROUP.sub("", RESTORE_SETTINGS.sub("", XML_COMMENT.sub("", text.lstrip("\ufeff"))))
    return [line.strip() for line in text.splitlines() if line.strip()]


def parse_pom(text: str):
    """A Maven project file's root element and the namespace its tags carry, if any."""
    root = ET.fromstring(text.lstrip(chr(0xFEFF)))
    return root, root.tag[: root.tag.index("}") + 1] if root.tag.startswith("{") else ""


def build_only_plugin(plugin, ns: str) -> bool:
    artifact = (plugin.findtext(f"{ns}artifactId") or "").strip()
    goals = {(goal.text or "").strip() for goal in plugin.iter(f"{ns}goal")}
    return artifact in BUILD_ONLY_PLUGINS and "instrument" not in goals


def in_test_scope(dependency, ns: str) -> bool:
    return (dependency.findtext(f"{ns}scope") or "").strip() == "test"


def elements(element, depth: int = 0):
    yield depth, element.tag, (element.text or "").strip(), sorted(element.attrib.items())
    for child in element:
        yield from elements(child, depth + 1)


def remove_children(root, tag: str, unreleased) -> None:
    for parent in list(root.iter(tag)):
        for child in [c for c in parent if unreleased(c)]:
            parent.remove(child)


def pom_without_unreleased_parts(text: str) -> list:
    """A Maven project file's elements, by depth, without comments, layout, build-only plugins, test-scope
    dependencies or the project's own version, which the changelog check compares with the changelog instead."""
    root, ns = parse_pom(text)
    own_version = root.find(f"{ns}version")
    if own_version is not None:
        root.remove(own_version)
    remove_children(root, f"{ns}plugins", lambda plugin: build_only_plugin(plugin, ns))
    remove_children(root, f"{ns}dependencies", lambda dependency: in_test_scope(dependency, ns))
    return list(elements(root))


def composer_json_without_development(text: str) -> dict:
    """A Composer project file without require-dev, which lists the development-only dependencies."""
    project = json.loads(text)
    project.pop("require-dev", None)
    return project


def composer_lock_packages(text: str) -> list:
    """The packages a Composer lock file installs in production. packages-dev holds the development-only ones, and the
    content hash changes with any change to the project file."""
    return json.loads(text)["packages"]


# The part of each content-checked file that ships. A change that leaves it the same releases nothing.
RELEASED_PART = {
    NUGET_LOCK: resolved_versions,
    ".csproj": project_without_restore_settings,
    POM: pom_without_unreleased_parts,
    COMPOSER_JSON: composer_json_without_development,
    COMPOSER_LOCK: composer_lock_packages,
}
CONTENT_CHECKED = tuple(RELEASED_PART)


def released_content(path: str, base_text: str, head_text: str) -> bool:
    """Whether a released file's change from base_text to head_text changes the software. A new NuGet lock file, or
    one that resolves the same versions, does not, and nor does a project file change made only of restore settings
    and comments, a Maven project file change made only of comments, layout, build-only plugins, test-scope
    dependencies and the project's own version, or a Composer change made only to development-only dependencies.
    Anything else does, a dependency version change included."""
    if path.endswith(NUGET_LOCK) and not base_text.strip():
        return False
    part = next((part for suffix, part in RELEASED_PART.items() if path.endswith(suffix)), None)
    if part is None or not base_text.strip() or not head_text.strip():
        return True
    try:
        return part(base_text) != part(head_text)
    except (ValueError, KeyError, AttributeError, TypeError, ET.ParseError):
        return True


# The bom-ref each implementation's generator writes for the application itself. Composer writes its normalised
# four-part version, so the release's three parts are followed by .0.
SBOM_REF = {
    "dotnet": "{name}@{version}",
    "java": "pkg:maven/{group}/{name}@{version}?type=jar",
    "php": "{group}/{name}-{version}.0",
}
PURL_VERSION = re.compile(r"@([^?#]+)")


def mismatch(path: str, field: str, found, expected: str, changelog: str) -> str:
    return f"{path}: {field} is {found}, expected {expected} from {changelog}."


def pom_errors(impl: str, text: str, version: str) -> list[str]:
    root, ns = parse_pom(text)
    found = root.findtext(f"{ns}version")
    found = found.strip() if found else "missing"
    return [] if found == version else [mismatch(POM, "the project <version>", found, version, f"{impl}/CHANGELOG.md")]


def sbom_errors(impl: str, text: str, version: str) -> list[str]:
    path, changelog = f"{impl}/sbom.json", f"{impl}/CHANGELOG.md"
    component = json.loads(text)["metadata"]["component"]
    expected = {
        "version": version,
        "bom-ref": SBOM_REF[impl].format(name=component.get("name"), group=component.get("group"), version=version),
    }
    if component.get("purl") is not None:
        expected["purl"] = PURL_VERSION.sub(f"@{version}", component["purl"], count=1)
    return [
        mismatch(path, f"metadata.component.{field}", component.get(field), value, changelog)
        for field, value in expected.items()
        if component.get(field) != value
    ]


def stated_version_errors(head, read) -> list[str]:
    """Where a version is stated outside the changelogs, in java/pom.xml and in each bill of materials, it must be the
    implementation's top changelog version. read returns a file's text, an empty string where it does not exist."""
    errors = []
    stated = [("java", POM, pom_errors)] + [(impl, f"{impl}/sbom.json", sbom_errors) for impl in IMPLS]
    for impl, path, compare in stated:
        text = read(path)
        if head[impl] is None or not text.strip():
            continue
        try:
            errors.extend(compare(impl, text, version_text(head[impl])))
        except (ValueError, KeyError, TypeError, AttributeError, ET.ParseError):
            errors.append(f"{path}: could not be read to compare its version with {impl}/CHANGELOG.md.")
    return errors


def released_changes(files, at_base, at_head):
    """Whether the change touches a released shared file, and which implementations' released code it changes.
    at_base and at_head return a file's text before and after the change, an empty string where it does not exist."""

    def released(path: str) -> bool:
        if not released_code(path):
            return False
        # Only these files are read, as the content of every other released file does not change the answer.
        return not path.endswith(CONTENT_CHECKED) or released_content(path, at_base(path), at_head(path))

    released_files = [f for f in files if released(f)]
    shared_change = any(f.startswith(SHARED) for f in released_files)
    impl_changes = {impl for impl in IMPLS for f in released_files if f.startswith(f"{impl}/")}
    return shared_change, impl_changes


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


def missing_shared_version(impl: str, base) -> str:
    hint = (f", {heading(next_version(base, 'minor'))} for new behaviour or {heading(next_version(base, 'patch'))} "
            f"for a fix, above {heading(base)}" if base else ", the same first version in all three")
    return f"{impl}/CHANGELOG.md: a shared change needs a new version in all three changelogs{hint}."


def shared_errors(before, head, changed, kind) -> list[str]:
    """What is wrong with the versions for a change to a released shared file. All three changelogs move, either to
    the same new MINOR or MAJOR, or each to a new PATCH above its own base version for a fix."""
    if any(changed[impl] and kind[impl] is None for impl in IMPLS):
        return []  # The step itself is already reported.
    missing = [missing_shared_version(impl, before[impl]) for impl in IMPLS if not changed[impl]]
    if missing:
        return missing
    kinds = {kind[impl] for impl in IMPLS}
    summary = ", ".join(f"{i} {version_text(v)}" for i, v in head.items())
    if "patch" in kinds and kinds != {"patch"}:
        return [f"A shared change bumps PATCH in all three changelogs or MINOR or MAJOR in all three, not a mix: "
                f"{summary}."]
    if "patch" not in kinds and len({v[:3] for v in head.values()}) > 1:
        return [f"A shared change that bumps MINOR or MAJOR writes the same version to all three changelogs: "
                f"{summary}."]
    return []


def main() -> int:
    base = sys.argv[1] if len(sys.argv) > 1 else "origin/master"
    files = [f for f in git("diff", "--name-only", f"{base}...HEAD").splitlines() if f]
    # The file list compares with the point the branch left the base, so file contents do too.
    fork = git("merge-base", base, "HEAD").strip()
    shared_change, impl_changes = released_changes(files, lambda p: file_at(fork, p), lambda p: file_at("HEAD", p))

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

    errors.extend(stated_version_errors(head, lambda p: file_at("HEAD", p)))

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

    for impl in IMPLS:
        path = f"{impl}/CHANGELOG.md"
        if changed[impl] and head[impl] is None:
            errors.append(f"{path}: the version heading was removed.")
        elif changed[impl] and kind[impl] is None:
            errors.append(
                f"{path}: {version_text(before[impl])} to {version_text(head[impl])} is not a single "
                "MAJOR, MINOR or PATCH step."
            )
        elif shared_change or all_three_feature:
            continue  # A shared change is checked across the three below, and a feature in all three is valid.
        elif impl in impl_changes:
            if not changed[impl]:
                hint = (
                    f", add {heading(next_version(before[impl], 'patch'))} above {heading(before[impl])} "
                    "with your entry under it"
                    if before[impl]
                    else ", add a first version heading"
                )
                errors.append(f"{path}: {impl}/ changed, so it needs a new PATCH version{hint}.")
            elif kind[impl] not in ("patch", "first"):
                errors.append(
                    f"{path}: an implementation-only change bumps PATCH, not {kind[impl].upper()}. A MINOR or "
                    "MAJOR is accepted only when all three implementations change and all three changelogs move "
                    "to the same new version."
                )
        elif changed[impl]:
            errors.append(f"{path}: version changed but nothing released under {impl}/ or shared/ did.")

    if shared_change:
        errors.extend(shared_errors(before, head, changed, kind))

    if errors:
        print("Changelog check failed:")
        for error in errors:
            print(f"  {error}")
        print("\nSee CONTRIBUTING.md, Changelog and version.")
        return 1

    if shared_change:
        what = "a shared change"
    elif all_three_feature:
        what = "an observable change in all three implementations"
    else:
        what = ", ".join(sorted(impl_changes)) or "nothing released"
    print(f"Changelogs match the change ({what}).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
