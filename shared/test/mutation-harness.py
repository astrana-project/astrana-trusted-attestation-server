#!/usr/bin/env python3
"""Measures how much a test suite would actually notice.

A suite reports what it ran, never what it missed. Passing tells you the faults you thought of are
absent, and nothing about the faults you did not. This introduces faults deliberately -- one at a
time, each a plausible mistake rather than a syntax error -- and reports which ones the tests catch.
A fault that survives is a gap, named and located.

Knows nothing about any implementation. The mutations, the commands that run the tests, and the way to
reload a rebuilt app are all handed in, so this file stays as implementation-blind as the conformance
suite it measures. --repo names the implementation's directory in this repository (dotnet, java or php)
and --mutants the JSON file describing its faults. Only that directory is edited, checked for
uncommitted work, and restored.

    python shared/test/mutation-harness.py --repo dotnet --mutants <mutants.json> --unit-command "dotnet test"

Each mutant is run against the suite it names first, and against the other only if that one missed. So
"caught by unit" means the unit tests noticed first, not that the conformance suite would not have --
whereas SURVIVED does mean every suite in the run missed it, which is the direction that matters. The
alternative was running both suites for every mutant, which on a compiled implementation is a rebuild
and a restart each, and a harness slow enough not to get run measures nothing at all.
"""

from __future__ import annotations

import argparse
import atexit
import json
import os
import signal
import subprocess
import sys


class Mutant:
    def __init__(self, raw: dict) -> None:
        self.id = raw["id"]
        self.description = raw.get("description", "")
        self.path = raw["file"]
        self.before = raw["from"]
        self.after = raw["to"]

        # Which suite is supposed to catch this: "unit", "conformance", or "any".
        #
        # Some rules can only be checked against a running instance with a database behind it -- that a
        # key already registered to another member is refused, for one. Expressing that as a unit test
        # would mean rebuilding most of the stack to assert something the conformance suite already
        # asserts against all three implementations at once.
        #
        # So a mutant that names its catcher is skipped, not failed, when that suite is not part of the
        # run. Otherwise a unit-only run reports the same survivor every time, and a survivor that is
        # always there is one nobody reads.
        self.expect = raw.get("expect", "any")


def read_mutants(path: str) -> list[Mutant]:
    with open(path, encoding="utf-8") as handle:
        return [Mutant(raw) for raw in json.load(handle)]


def run(command: str, cwd: str, timeout: int = 900) -> int:
    """Runs a command, keeping its output out of the way. Only the exit code matters here."""
    return run_capturing(command, cwd, timeout)[0]


def run_capturing(command: str, cwd: str, timeout: int = 900) -> tuple[int, str]:
    """The same, but keeping the output for the one caller that needs to explain a failure.

    The reload is that caller. "RELOAD FAILED" on its own says nothing an operator can act on, and the
    output is the difference between a two-minute fix and an afternoon of running the command by hand
    trying to reproduce something that only happens inside the harness.
    """
    try:
        finished = subprocess.run(command, shell=True, cwd=cwd, capture_output=True,
                                  text=True, timeout=timeout)
    except subprocess.TimeoutExpired:
        return -1, f"timed out after {timeout}s"
    except subprocess.SubprocessError as error:
        return -1, f"{type(error).__name__}: {error}"

    return finished.returncode, (finished.stdout or "") + (finished.stderr or "")


def tree_is_clean(repo: str) -> bool:
    """Only the implementation's own directory has to be clean.

    The three implementations share one repository, and git status reports the whole of it from any
    subdirectory. Unscoped, work in progress on the Java side would stop a run against .NET, which edits
    and restores nothing outside its own directory. The pathspec limits the report to what the run touches.
    """
    finished = subprocess.run("git status --porcelain -- .", shell=True, cwd=repo,
                              capture_output=True, text=True)
    return finished.returncode == 0 and not finished.stdout.strip()


class AmbiguousAnchor(Exception):
    """The anchor matches more than one place, so which one gets mutated is arbitrary."""


def apply(mutant: Mutant, repo: str) -> bool:
    full = os.path.join(repo, mutant.path)

    try:
        with open(full, encoding="utf-8") as handle:
            source = handle.read()
    except OSError:
        return False

    occurrences = source.count(mutant.before)
    if occurrences == 0:
        return False

    # More than one match is refused rather than resolved. Taking the first is arbitrary -- the author
    # wrote the anchor with one site in mind and has no way to know which one they actually hit -- so a
    # mutant could be measuring a line nobody meant to test while its description claims otherwise. That
    # is worse than not running it: it reports a number about the wrong code.
    #
    # "if (row is null) { return NotFound(); }" appears four times in one file here. It nearly happened.
    if occurrences > 1:
        raise AmbiguousAnchor(
            f"{mutant.id}: the anchor matches {occurrences} places in {mutant.path}. "
            "Extend it with a neighbouring line until it identifies one.")

    with open(full, "w", encoding="utf-8", newline=chr(10)) as handle:
        handle.write(source.replace(mutant.before, mutant.after, 1))

    return True


def restore(repo: str) -> None:
    subprocess.run("git checkout -- .", shell=True, cwd=repo, capture_output=True)


MARKER = ".mutation-in-progress"


def marker_path(repo: str) -> str:
    return os.path.join(repo, MARKER)


def restore_on_exit(repo: str) -> None:
    """Puts the tree back on every ending this process can observe, and leaves a note for the one it
    cannot.

    A try/finally covers an exception and nothing else. This run leaves a deliberate fault in the
    working tree for as long as the tests take, and the ways it can be cut short mid-mutant are
    ordinary ones: a timeout from whatever launched it, Ctrl-C, a CI step giving up. Most of those are
    signals, and a signal skips the finally entirely -- hence the handlers below.

    That is not a tidiness problem. It leaves the repository holding a plausible-looking fault with
    nothing to say so -- which, on a tree that was clean a moment ago, is exactly how a mutation ends
    up committed.

    A hard kill -- SIGKILL, or a parent tearing down the whole process tree, which is what a tool-level
    timeout does on Windows -- runs no handler at all, and no amount of care here changes that. So
    the tree is not the only record: a marker file is written
    before the first mutation and removed on any clean ending. Finding it at startup is unambiguous
    evidence that a previous run was killed outright, and the message says so rather than leaving the
    next person to work it out from a dirty tree.
    """
    with open(marker_path(repo), "w", encoding="utf-8") as handle:
        handle.write(f"A mutation run (pid {os.getpid()}) is in progress. If this file outlives it, the "
                     "run was killed before it could restore the tree: git checkout -- . to put it back.")

    def clean_up(target: str) -> None:
        restore(target)
        try:
            os.remove(marker_path(target))
        except OSError:
            pass

    atexit.register(clean_up, repo)

    def on_signal(number, frame):  # noqa: ARG001 - signature fixed by the signal module
        clean_up(repo)
        print()
        print(f"Interrupted (signal {number}). The tree has been restored.")
        sys.exit(130)

    for name in ("SIGINT", "SIGTERM", "SIGBREAK"):
        if hasattr(signal, name):
            try:
                signal.signal(getattr(signal, name), on_signal)
            except (ValueError, OSError):
                # Not the main thread, or the platform will not have it. atexit still covers the
                # ordinary exits.
                pass


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--repo", required=True,
                        help="The implementation's directory in this repository (dotnet, java or php). "
                             "Mutant file paths are relative to it.")
    parser.add_argument("--mutants", required=True, help="JSON file describing the mutations.")
    parser.add_argument("--unit-command", help="Command that runs this implementation's unit tests.")
    parser.add_argument("--conformance-url",
                        help="Instance to run the shared conformance suite against. Needs "
                             "--reload-command for anything not interpreted from source per request.")
    parser.add_argument("--reload-command",
                        help="Command that rebuilds and restarts the running instance so it picks up a "
                             "mutation. Not needed where the app reads its source per request.")
    parser.add_argument("--sql-command", help="Passed through to the conformance suite.")
    parser.add_argument("--database", default="postgres",
                        help="Passed through to the conformance suite.")
    parser.add_argument("--protocol", default="oidc", choices=["oidc", "saml"],
                        help="Which IAM protocol the instance under test speaks. A mutant can require "
                             "it by naming 'conformance-saml' or 'conformance-oidc' as its expect, so "
                             "protocol-specific code does not report as a survivor in a run that could "
                             "never have reached it.")
    parser.add_argument("--only", help="Run a single mutant by id.")
    args = parser.parse_args()

    repo = os.path.abspath(args.repo)
    suite = os.path.join(os.path.dirname(os.path.abspath(__file__)), "conformance", "check.py")
    # The interpreter running this, not whatever "python" resolves to on PATH: on Linux that is often
    # nothing at all (only python3 is installed), and a suite that cannot start reports no failure, which
    # would be scored as every conformance mutant surviving.
    python = f'"{sys.executable}"'

    if not args.unit_command and not args.conformance_url:
        print("Nothing to measure: give --unit-command, --conformance-url, or both.")
        return 2

    # Refusing rather than risking it. Restoring is `git checkout -- .`, which would throw away
    # uncommitted work that happened to be in the tree when this started.
    # Refused before anything else, because two runs on one repository destroy each other quietly. One
    # applies a mutation while the other is between mutants, so the second sees a "stale anchor" that is
    # really the first one's edit, and a build broken by their combined state is scored as caught --
    # producing a flawless-looking result that measured nothing. That happened here: a killed run left a
    # process still working, a second run started, and the pair reported 19/19 with half the mutants
    # never applied.
    if os.path.exists(marker_path(repo)):
        with open(marker_path(repo), encoding="utf-8") as handle:
            print(handle.read().strip())
        print()
        print(f"Refusing to start a second run against {repo}.")
        print(f"If no such process is running, the last one was killed: git checkout -- . and delete "
              f"{MARKER}.")
        return 2

    if not tree_is_clean(repo):
        print(f"{repo} has uncommitted changes.")
        print("This works by editing files and reverting them, so it only runs on a clean tree.")

        # The difference between "you have work in progress" and "the last run was killed and left a
        # deliberate fault behind" is the whole point of the marker. Both look like a dirty tree, and
        # only one of them means the checkout is holding a bug nobody wrote on purpose.
        if os.path.exists(marker_path(repo)):
            print()
            print("A previous run was killed before it could restore the tree -- a hard kill runs no "
                  "handler, so this file is the only trace it leaves.")
            print("Those changes are mutations, not work: git checkout -- . to put the tree back.")

        return 2

    # Registered before the first mutation is written, so there is no window where a fault is in the
    # tree and nothing is arranged to take it out again.
    restore_on_exit(repo)

    mutants = read_mutants(args.mutants)
    if args.only:
        mutants = [m for m in mutants if m.id == args.only]
        if not mutants:
            print(f"No mutant with id {args.only!r}.")
            return 2

    # Everything below reads "the tests failed" as "the fault was caught". If they already fail on
    # unmutated code -- a locked output file, a stopped service, a database that is not up -- then every
    # mutant is scored as caught and the run reports a flawless result while measuring nothing at all.
    # A green baseline is what makes the rest of the numbers mean anything.
    print("checking the tests pass before anything is changed")

    if args.unit_command and run(args.unit_command, repo) != 0:
        print("  the unit tests fail on an unmutated tree; fix that first")
        return 2

    if args.conformance_url:
        baseline = f'{python} "{suite}" --base-url {args.conformance_url}'
        if args.sql_command:
            baseline += f' --sql-command "{args.sql_command}" --database {args.database}'
        if run(baseline, repo) != 0:
            print("  the conformance suite does not pass against the running instance; fix that first")
            return 2

    print("  baseline is green")
    print()

    print(f"{len(mutants)} mutants against {repo}")
    if args.conformance_url and not args.reload_command:
        print("note: no --reload-command, so conformance sees a mutation only if the app reads its "
              "source per request")
    print()

    survivors: list[Mutant] = []
    unapplied: list[Mutant] = []
    not_measured: list[Mutant] = []
    caught_by: dict[str, str] = {}

    available = set()
    if args.unit_command:
        available.add("unit")
    if args.conformance_url:
        available.add("conformance")

        # Some behaviour only exists on one protocol -- the assertion consumer service has no equivalent
        # on an OIDC deployment, and the conformance suite says so and skips that section. A mutant in
        # that code is therefore unmeasurable in an OIDC run, and reporting it as a survivor every single
        # time is how a real survivor stops being read. The caller says which protocol the instance
        # speaks, and a mutant can name it.
        available.add(f"conformance-{args.protocol}")

    reload_failed: str | None = None
    reload_output = ""

    try:
        for mutant in mutants:
            if reload_failed:
                break

            print(f"  {mutant.id:<30}", end="", flush=True)

            if mutant.expect != "any" and mutant.expect not in available:
                not_measured.append(mutant)
                print(f"not measured (needs the {mutant.expect} suite)")
                continue

            try:
                applied = apply(mutant, repo)
            except AmbiguousAnchor as ambiguous:
                # Reported alongside the stale ones, and for the same reason: neither measured anything,
                # and both are the mutant file falling behind the code it describes.
                unapplied.append(mutant)
                print(f"COULD NOT APPLY ({ambiguous})")
                continue

            if not applied:
                # Never counted as caught. A mutation that no longer matches its anchor tests nothing,
                # and scoring it as a pass would inflate the result exactly where the code has moved on
                # and the mutant has gone stale.
                unapplied.append(mutant)
                print("COULD NOT APPLY (stale anchor)")
                continue

            # Deliberately not reloaded here. Unit tests compile the mutated source themselves, so a
            # mutant the unit suite catches never needs the app rebuilt and restarted at all -- which on
            # a compiled implementation is most of what a mutant costs. The reload happens below, once,
            # and only if the run actually reaches the conformance suite.
            reloaded = False

            # The suite the mutant names goes first, because it is the one asserted to catch this. The
            # other only runs if the first missed -- so a survivor has genuinely been missed by
            # everything, while a mutant that is caught costs one suite instead of two.
            #
            # On a compiled implementation that difference is most of the runtime: every suite here is a
            # rebuild and a restart, and a harness slow enough not to get run measures nothing at all.
            order = ["unit", "conformance"]
            if mutant.expect in order:
                order.sort(key=lambda name: name != mutant.expect)

            caught: str | None = None
            for name in order:
                if name == "unit" and args.unit_command:
                    if run(args.unit_command, repo) != 0:
                        caught = "unit"
                elif name == "conformance" and args.conformance_url:
                    if args.reload_command and not reloaded:
                        # A failed reload is fatal to the whole run, not just this mutant. The suite
                        # then cannot reach the app, which is scored as "not caught" -- so every
                        # remaining conformance mutant reports SURVIVED and the result reads as a
                        # gaping hole in the tests rather than a broken reload command.
                        #
                        # A false survivor costs as much as a false pass, because it sends someone
                        # hunting for a gap that is not there.
                        code, output = run_capturing(args.reload_command, repo)
                        if code != 0:
                            print(f"RELOAD FAILED (exit {code})")
                            reload_failed = mutant.id
                            reload_output = output.strip()[-1500:]
                            break

                        reloaded = True

                    command = f'{python} "{suite}" --base-url {args.conformance_url}'
                    if args.sql_command:
                        command += f' --sql-command "{args.sql_command}" --database {args.database}'

                    # Exit 2 means the suite could not complete, which is not the same as detecting the
                    # fault. Only a completed run that failed counts as catching it.
                    if run(command, repo) == 1:
                        caught = "conformance"

                if caught:
                    break

            restore(repo)

            # Still no reload here. The next mutant that needs the app reloads it first, and the final
            # reload after the loop leaves the instance running what is in the tree. Reloading now would
            # rebuild and restart for a state nobody ever observes.

            if caught:
                caught_by[mutant.id] = caught
                print(f"caught by {caught}")
            else:
                survivors.append(mutant)
                print("SURVIVED")
    finally:
        restore(repo)

        # Once, at the end: leave the instance running the code that is actually in the tree, rather
        # than whichever mutation was applied last.
        if args.reload_command:
            run(args.reload_command, repo)

    # Reported before the numbers, because it changes what they mean: every mutant after the failure
    # was never measured, and the ones reported as survivors before it may not have been either.
    if reload_failed:
        print()
        print(f"THE RUN DID NOT COMPLETE. The reload command failed at {reload_failed}, so the "
              "instance the conformance suite talks to is not running the code under test.")
        print("Every result below is unreliable: a suite that cannot reach the app reports no failure, "
              "which is scored as the fault surviving.")
        if reload_output:
            print()
            print("What the reload command said:")
            for line in reload_output.splitlines():
                print(f"  {line}")

        print()
        print("Fix the reload command and run again.")
        return 2

    applied = len(mutants) - len(unapplied) - len(not_measured)
    killed = applied - len(survivors)

    print()
    print(f"{killed}/{applied} mutants caught"
          + (f", {len(not_measured)} not measured by this run" if not_measured else "")
          + (f", {len(unapplied)} could not be applied" if unapplied else ""))

    if not_measured:
        print()
        print("Not measured here, because the suite that covers them was not part of this run:")
        for mutant in not_measured:
            print(f"  - {mutant.id}: needs the {mutant.expect} suite")

    if unapplied:
        print()
        print("Stale, and therefore measuring nothing:")
        for mutant in unapplied:
            print(f"  - {mutant.id}: {mutant.path} no longer contains the text it patches")

    if survivors:
        print()
        print("Survived, i.e. the tests would not notice if this were true:")
        for mutant in survivors:
            print(f"  - {mutant.id}: {mutant.description or mutant.path}")

    # Stale mutants fail the run too. A harness quietly measuring fewer things than it claims is the
    # same problem it exists to find.
    return 1 if survivors or unapplied else 0


if __name__ == "__main__":
    sys.exit(main())
