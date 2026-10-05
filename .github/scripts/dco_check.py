#!/usr/bin/env python3
"""Fails when a commit in the pull request lacks a Signed-off-by trailer matching its author.

Usage: dco_check.py <base-ref>

Merge commits are skipped, because pull requests are squash-merged and a merge from the base branch into a
working branch is not the contributor's own change.
"""

import subprocess
import sys

RECORD = "\x1e"
FIELD = "\x1f"


def main() -> int:
    base = sys.argv[1] if len(sys.argv) > 1 else "origin/master"
    fmt = FIELD.join(["%H", "%an", "%ae", "%(trailers:key=Signed-off-by,valueonly)"]) + RECORD
    log = subprocess.run(
        ["git", "log", "--no-merges", f"--format={fmt}", f"{base}..HEAD"],
        capture_output=True,
        text=True,
        check=True,
    ).stdout

    unsigned = []
    for record in filter(str.strip, log.split(RECORD)):
        sha, name, email, trailers = (record.strip("\n").split(FIELD) + [""])[:4]
        signers = {line.strip().lower() for line in trailers.splitlines() if line.strip()}
        if f"{name} <{email}>".lower() not in signers:
            unsigned.append(f"  {sha[:10]}  {name} <{email}>")

    if unsigned:
        print("These commits are not signed off by their author (git commit -s):")
        print("\n".join(unsigned))
        print("\nAmend or rebase to add a Signed-off-by line matching the author, then push again.")
        return 1

    print("Every commit is signed off by its author.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
