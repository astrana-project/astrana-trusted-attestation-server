#!/usr/bin/env python3
"""Completes the .NET or Java software bill of materials after the CycloneDX generator has written it.

    python3 scripts/complete-sbom.py <dotnet|java> [sbom.json]

The generators read only the package manager's dependency graph, so two things are missing or imprecise in what they
write. Bootstrap, compiled into the shared stylesheet that every page loads, is no package of either ecosystem, so this
adds it as a component the application depends on, at the version shared/ui/package.json pins. And some packages state
no licence, only a licence address, or a licence the generator records imprecisely, so this sets the licence of every
component that shared/third-party/component-facts.tsv gives one for. For Java it also adds Spring Boot's loader, whose
classes the build copies into every executable jar, at the Spring Boot version java/pom.xml names.

Running it again replaces its own entries, so it is safe to run after every regeneration. The path defaults to the
implementation's own sbom.json.
"""

from __future__ import annotations

import json
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
FACTS = ROOT / "shared" / "third-party" / "component-facts.tsv"

# How each generator lays out its file, kept so a regenerated file differs only where something changed.
SEPARATORS = {"dotnet": (",", ": "), "java": (",", " : ")}
# Every reference this script adds starts with one of these, which is how a second run finds its own entries.
OWN_REFS = ("pkg:npm/bootstrap@", "pkg:maven/org.springframework.boot/spring-boot-loader@")


def read_facts() -> dict[str, dict[str, str]]:
    facts: dict[str, dict[str, str]] = {}
    for line in FACTS.read_text(encoding="utf-8").splitlines():
        if line and not line.startswith("#"):
            component, field, value = line.split("\t")
            facts.setdefault(component, {})[field] = value
    return facts


def licence_entry(fact: dict[str, str]) -> list[dict] | None:
    if "licence" in fact:
        expression = fact["licence"]
        if re.search(r" (WITH|OR|AND) ", expression):
            return [{"expression": expression}]
        return [{"license": {"id": expression}}]
    if "licence-name" in fact:
        licence = {"name": fact["licence-name"]}
        if "licence-url" in fact:
            licence["url"] = fact["licence-url"]
        return [{"license": licence}]
    return None


def component_key(implementation: str, component: dict) -> str:
    if implementation == "java":
        return f"{component.get('group', '')}:{component['name']}"
    return component["name"]


def bootstrap() -> dict:
    version = json.loads((ROOT / "shared" / "ui" / "package.json").read_text(encoding="utf-8"))["devDependencies"][
        "bootstrap"
    ]
    return {
        "type": "library",
        "bom-ref": f"pkg:npm/bootstrap@{version}",
        "name": "bootstrap",
        "version": version,
        "description": "Bootstrap, compiled into the shared stylesheet trusted-attestation.css that every page loads",
        "licenses": [{"license": {"id": "MIT"}}],
        "copyright": "Copyright 2011-2025 The Bootstrap Authors",
        "purl": f"pkg:npm/bootstrap@{version}",
        "externalReferences": [
            {"type": "website", "url": "https://getbootstrap.com"},
            {"type": "vcs", "url": "https://github.com/twbs/bootstrap"},
        ],
    }


def spring_boot_loader() -> dict:
    pom = (ROOT / "java" / "pom.xml").read_text(encoding="utf-8")
    version = re.search(r"<artifactId>spring-boot-starter-parent</artifactId>\s*<version>([^<]+)</version>", pom)[1]
    reference = f"pkg:maven/org.springframework.boot/spring-boot-loader@{version}?type=jar"
    return {
        "type": "library",
        "bom-ref": reference,
        "group": "org.springframework.boot",
        "name": "spring-boot-loader",
        "version": version,
        "description": "Spring Boot's launcher, whose classes the build copies into the executable jar",
        "licenses": [{"license": {"id": "Apache-2.0"}}],
        "purl": reference,
        "externalReferences": [
            {"type": "website", "url": "https://spring.io/projects/spring-boot"},
            {"type": "vcs", "url": "https://github.com/spring-projects/spring-boot"},
        ],
    }


def complete(implementation: str, bom: dict) -> list[str]:
    facts = read_facts()
    changed = []
    for component in bom["components"]:
        entry = licence_entry(facts.get(component_key(implementation, component), {}))
        if entry is not None and component.get("licenses") != entry:
            component["licenses"] = entry
            changed.append(component_key(implementation, component))
        # CycloneDX's "excluded" scope marks a component the dependency graph names but the published output does not
        # contain, such as a package whose files the build leaves out.
        scope = facts.get(component_key(implementation, component), {}).get("scope")
        if scope is not None and component.get("scope") != scope:
            component["scope"] = scope
            changed.append(component_key(implementation, component))

    added = [bootstrap()] + ([spring_boot_loader()] if implementation == "java" else [])
    ours = lambda ref: ref.startswith(OWN_REFS)  # noqa: E731
    root = bom["metadata"]["component"]["bom-ref"]

    bom["components"] = [c for c in bom["components"] if not ours(c["bom-ref"])] + added
    bom["dependencies"] = [d for d in bom["dependencies"] if not ours(d["ref"])]
    for dependency in bom["dependencies"]:
        if dependency["ref"] == root:
            kept = [ref for ref in dependency.get("dependsOn", []) if not ours(ref)]
            dependency["dependsOn"] = kept + [c["bom-ref"] for c in added]
    bom["dependencies"] += [{"ref": c["bom-ref"]} for c in added]

    return changed + [c["bom-ref"] for c in added]


def main(argv: list[str]) -> int:
    if len(argv) not in (2, 3) or argv[1] not in SEPARATORS:
        print(__doc__.strip().splitlines()[2].strip(), file=sys.stderr)
        return 2
    implementation = argv[1]
    path = pathlib.Path(argv[2]) if len(argv) == 3 else ROOT / implementation / "sbom.json"

    bom = json.loads(path.read_text(encoding="utf-8"))
    touched = complete(implementation, bom)
    text = json.dumps(bom, indent=2, ensure_ascii=False, separators=SEPARATORS[implementation])
    if implementation == "java":
        text = text.replace(" : []", " : [ ]")
    path.write_text(text + "\n", encoding="utf-8", newline="\n")

    print(f"complete-sbom: set or added {', '.join(touched)}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
