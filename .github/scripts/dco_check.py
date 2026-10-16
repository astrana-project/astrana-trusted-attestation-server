#!/usr/bin/env python3
"""Fails when a commit in the pull request lacks a Signed-off-by trailer matching its author.

Usage: dco_check.py <base-ref>

Merge commits are skipped, because pull requests are squash-merged and a merge from the base branch into a
working branch is not the contributor's own change.

Dependabot signs off its commits with an address that differs from their author's, so on a pull request a bot opened,
given in the PULL_REQUEST_AUTHOR environment variable, that bot's own commits are skipped too. Anyone else's commit on
such a pull request still needs a sign-off, and no other pull request can claim a bot's identity to skip the check.
"""

import os
import subprocess
import sys

RECORD = "\x1e"
FIELD = "\x1f"
# The commit author each bot that opens pull requests uses, by its GitHub login.
BOTS = {"dependabot[bot]": "dependabot[bot] <49699333+dependabot[bot]@users.noreply.github.com>"}


def main() -> int:
    base = sys.argv[1] if len(sys.argv) > 1 else "origin/master"
    fmt = FIELD.join(["%H", "%an", "%ae", "%(trailers:key=Signed-off-by,valueonly)"]) + RECORD
    log = subprocess.run(
        ["git", "log", "--no-merges", f"--format={fmt}", f"{base}..HEAD"],
        capture_output=True,
        text=True,
        check=True,
    ).stdout

    bot = BOTS.get(os.environ.get("PULL_REQUEST_AUTHOR", ""), "").lower()
    unsigned = []
    for record in filter(str.strip, log.split(RECORD)):
        sha, name, email, trailers = (record.strip("\n").split(FIELD) + [""])[:4]
        author = f"{name} <{email}>".lower()
        signers = {line.strip().lower() for line in trailers.splitlines() if line.strip()}
        if author != bot and author not in signers:
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
