#!/usr/bin/env python3
"""Checks that the governed relationship_type vocabulary is identical everywhere it appears.

Decision record 14 in docs/adr makes extending the enum a feature release of all three implementations rather than
per-org configuration, which only
means anything if every artefact that states the vocabulary states the same one. A value added to the
contract file but forgotten in the OpenAPI enum would be accepted by an implementation and rejected by a
generated client, and nothing would notice until it did.

Three artefacts under shared/contract have to agree:

    relationship-types.json   the single source, which the implementations import at build time
    openapi.yaml              the RelationshipType enum a generated client validates against
    manifest.schema.json      what a manifest's relationship_types is checked against

Then the copies the implementations actually consume are compared byte for byte with the source. PHP
syncs a copy into php/contract at build time, Java packages one into target/classes/contract, and .NET
embeds the shared file by path, so for .NET it is the csproj's embed path that is checked. A copy that
has not been built yet is reported as skipped, not as drift. Each schema file under shared/schema is
checked too: grant_member_relationship refuses a type outside the vocabulary, so each file lists the
vocabulary once, and that list has to name exactly the values in the contract file.

Standard library only, so it runs anywhere the implementations do and needs nothing installed in CI.

    python shared/test/check-vocabulary.py
"""

from __future__ import annotations

import argparse
import json
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]

CONTRACT_DIR = ROOT / "shared" / "contract"
CONTRACT_FILE = CONTRACT_DIR / "relationship-types.json"
OPENAPI_FILE = CONTRACT_DIR / "openapi.yaml"
MANIFEST_SCHEMA_FILE = CONTRACT_DIR / "manifest.schema.json"
SCHEMA_DIR = ROOT / "shared" / "schema"


def from_contract() -> list[str]:
    document = json.loads(CONTRACT_FILE.read_text(encoding="utf-8"))
    return [entry["id"] for entry in document["types"]]


def from_openapi() -> list[str]:
    """Reads the RelationshipType enum without a YAML parser, so CI needs no dependencies."""
    text = OPENAPI_FILE.read_text(encoding="utf-8")

    start = text.index("    RelationshipType:")
    enum_start = text.index("enum:", start)

    values = []
    for line in text[enum_start:].splitlines()[1:]:
        match = re.match(r"^\s+- (\S+)\s*$", line)
        if not match:
            break

        values.append(match.group(1))

    return values


def from_manifest_schema() -> list[str]:
    document = json.loads(MANIFEST_SCHEMA_FILE.read_text(encoding="utf-8"))
    return document["properties"]["relationship_types"]["items"]["enum"]


SOURCES = {
    "shared/contract/relationship-types.json": from_contract,
    "shared/contract/openapi.yaml": from_openapi,
    "shared/contract/manifest.schema.json": from_manifest_schema,
}


# The copies an implementation reads at runtime, each produced by its build from the shared file. Both
# are git-ignored, so they exist only once that implementation has been built on this machine.
IMPLEMENTATION_COPIES = {
    "php (php/contract, synced by scripts/sync-shared-assets.php)":
        ROOT / "php" / "contract" / "relationship-types.json",
    "java (target/classes/contract, packaged by Maven)":
        ROOT / "java" / "target" / "classes" / "contract" / "relationship-types.json",
}

# .NET keeps no copy on disk: the csproj embeds the shared file by relative path at build time. The thing
# that can drift there is the path, so that is what is checked.
DOTNET_PROJECT = (ROOT / "dotnet" / "src" / "Astrana.TrustedAttestation.Server"
                  / "Astrana.TrustedAttestation.Server.csproj")


def check_implementation_copies() -> list[str]:
    """Byte-for-byte, not value-by-value: the labels matter as much as the identifiers."""
    canonical = CONTRACT_FILE.read_bytes()
    failures = []

    for name, copy in IMPLEMENTATION_COPIES.items():
        if not copy.exists():
            print(f"  SKIP   {name}: not built here")
            continue

        if copy.read_bytes() == canonical:
            print(f"  OK     {name}")
        else:
            print(f"  DRIFT  {name}")
            failures.append(f"{name}: differs from shared/contract/relationship-types.json, rebuild it")

    failures.extend(check_dotnet_embed())
    return failures


def check_dotnet_embed() -> list[str]:
    name = "dotnet (embedded from shared/contract at build time)"
    if not DOTNET_PROJECT.exists():
        print(f"  SKIP   {name}: {DOTNET_PROJECT.relative_to(ROOT).as_posix()} not found")
        return []

    text = DOTNET_PROJECT.read_text(encoding="utf-8")
    match = re.search(r'<EmbeddedResource\s+Include="([^"]*relationship-types\.json)"', text)
    if not match:
        print(f"  DRIFT  {name}")
        return [f"{name}: the csproj no longer embeds relationship-types.json"]

    embedded = (DOTNET_PROJECT.parent / match.group(1).replace("\\", "/")).resolve()
    if embedded == CONTRACT_FILE.resolve():
        print(f"  OK     {name}")
        return []

    print(f"  DRIFT  {name}")
    return [f"{name}: the csproj embeds {match.group(1)}, not the shared file"]


# A relationship type compared against a parenthesised list, with anything between the two short of a
# statement end or a parenthesis: "p_relationship_type NOT IN (" on PostgreSQL and MySQL, and
# "@relationship_type COLLATE Latin1_General_100_BIN2 NOT IN (" on SQL Server.
SCHEMA_LIST = re.compile(r"relationship_type\b[^;()]*?\bIN\s*\(([^)]*)\)", re.IGNORECASE)


def schema_lists(text: str) -> list[list[str]]:
    """Every list of relationship types in a schema file, with SQL comments removed first so prose cannot
    be mistaken for a list."""
    code = re.sub(r"--[^\n]*", "", text)
    return [re.findall(r"'([^']*)'", match.group(1)) for match in SCHEMA_LIST.finditer(code)]


def check_schema_procedures(reference: set[str]) -> list[str]:
    """grant_member_relationship lists the vocabulary in each schema file, so the database refuses a type
    the contract does not name. Each file must hold exactly one such list, and it must name the same values
    as the contract file, each once."""
    failures = []

    for schema in sorted(SCHEMA_DIR.glob("schema-*.sql")):
        name = f"shared/schema/{schema.name}"
        lists = schema_lists(schema.read_text(encoding="utf-8"))

        if len(lists) != 1:
            print(f"  DRIFT  {name} ({len(lists)} lists)")
            failures.append(f"{name}: expected one list of relationship types in grant_member_relationship, "
                            f"found {len(lists)}")
            continue

        values = lists[0]
        problems = list_problems(name, values, reference)
        print(f"  {'DRIFT' if problems else 'OK   '}  {name} ({len(values)} values)")
        failures.extend(problems)

    return failures


def list_problems(name: str, values: list[str], reference: set[str]) -> list[str]:
    """What differs between one statement of the vocabulary and the contract file, if anything."""
    problems = []

    duplicates = sorted({v for v in values if values.count(v) > 1})
    if duplicates:
        problems.append(f"{name}: lists {', '.join(duplicates)} more than once")

    missing = sorted(reference - set(values))
    if missing:
        problems.append(f"{name}: missing {', '.join(missing)}")

    extra = sorted(set(values) - reference)
    if extra:
        problems.append(f"{name}: has {', '.join(extra)}, which is not in the contract file")

    return problems


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.parse_args()

    vocabularies: dict[str, list[str]] = {}
    failures: list[str] = []

    for name, read in SOURCES.items():
        try:
            vocabularies[name] = read()
        except Exception as error:  # noqa: BLE001 - the reason is reported, whatever it is
            failures.append(f"{name}: could not be read ({error})")

    if failures:
        print("Could not read every artefact:")
        for failure in failures:
            print(f"  - {failure}")
        return 1

    reference_name = "shared/contract/relationship-types.json"
    reference = set(vocabularies[reference_name])

    print(f"{len(reference)} values in {reference_name}\n")

    for name, values in vocabularies.items():
        problems = list_problems(name, values, reference)
        print(f"  {'DRIFT' if problems else 'OK   '}  {name} ({len(values)} values)")
        failures.extend(problems)

    print("\nThe list grant_member_relationship refuses other types against, in each schema file:")
    failures.extend(check_schema_procedures(reference))

    print("\nImplementation copies of the contract file:")
    failures.extend(check_implementation_copies())

    if failures:
        print("\nThe governed vocabulary has drifted:")
        for failure in failures:
            print(f"  - {failure}")
        print(
            "\nExtending the enum is a feature release of all three implementations (decision record 14). A new value "
            "has to land in every artefact "
            "under shared/contract and in grant_member_relationship in each schema file together, or an "
            "implementation, a generated client and the database will disagree about what is valid."
        )
        return 1

    print("\nThe governed vocabulary is identical in every artefact.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
