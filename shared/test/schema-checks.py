#!/usr/bin/env python3
"""Checks the data model behaves as the schema files under shared/schema and decision records 3, 10, 13 and 29 in
docs/adr describe, on whichever engine is given.

Everything else here checks an application's behaviour through HTTP, which exercises the schema only
incidentally and only through whatever the application happens to do, so a constraint that is missing, too strict, or spelled differently on one engine shows up as a puzzling
application failure much later, if at all.

Two engine differences of exactly that shape, neither visible by reading the scripts:

  - SQL Server treats NULLs as equal for uniqueness, so a plain UNIQUE (public_key) allows exactly one
    unkeyed relationship in the whole table and rejects the second. After a grant, unkeyed is the normal
    state, so this would break the second member to be granted anything.
  - The filtered index that handles it needs QUOTED_IDENTIFIER ON, which sqlcmd disables by default.
    Without it the batch aborts, audit_log is never created, and the first symptom is an unrelated
    "invalid object name" from inside a procedure.

Engine-parameterised rather than engine-blind. The three engines genuinely differ here -- in NULL
uniqueness semantics, in how a procedure is called, in how a literal timestamp is written -- and the
point of this file is to check that those differences have been accommodated, not to pretend they do not
exist.

    python shared/test/schema-checks.py --database postgres \\
        --sql-command "docker exec -i trusted-attestation-dev-postgres-1 psql -q -U u -d d"

Destructive: it writes and deletes rows for its own test subjects. Those subjects are namespaced so a
run cannot disturb anything else, but point it at a development database, not a real one.
"""

from __future__ import annotations

import argparse
import pathlib
import re
import sys
import threading

# Namespaced so a run cannot collide with dev data, another engine's run, or a previous run of itself.
SUBJECT = "schema-check-subject"
OTHER_SUBJECT = "schema-check-other"

# A key is 32 bytes. These are written as hex and converted by whichever expression the engine needs.
KEY_A = "aa" * 32
KEY_B = "bb" * 32

# The subject the vocabulary and exact-matching checks use, kept apart so the audit counts above are not
# disturbed. PADDED_SUBJECT is the same identifier with a trailing space, which MySQL and SQL Server ignore
# when they compare two strings unless the procedure says otherwise. LEADING_SUBJECT has a leading space,
# which no engine ignores, but which a grant refuses all the same.
EXACT_SUBJECT = "schema-check-exact"
PADDED_SUBJECT = EXACT_SUBJECT + " "
LEADING_SUBJECT = " " + EXACT_SUBJECT
EXACT_SUBJECTS = f"iam_subject_id IN ('{EXACT_SUBJECT}', '{PADDED_SUBJECT}', '{LEADING_SUBJECT}')"

# Words from the errors the procedures raise, identical on all three engines. The checks look for them in
# the client's output rather than at its exit code, for the reason given at the first state check below.
NOT_IN_VOCABULARY = "is not in the vocabulary"
SPACES_AROUND_SUBJECT = "has leading or trailing spaces"
NO_MATCH = "No relationship matches"


# Engine differences and the SQL runner live in engines.py alongside, shared with the conformance suite:
# both need to reach the database directly, and the SET-option rules in particular are not worth
# discovering twice.
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from engines import Engine, NAMES, run_sql  # noqa: E402


class Checks:
    def __init__(self, sql_command: str, engine: Engine) -> None:
        self.sql_command = sql_command
        self.engine = engine
        self.passed = 0
        self.failures: list[str] = []

    def sql(self, statements: str) -> tuple[int, str]:
        return run_sql(self.sql_command, self.engine.prelude + statements)

    def count(self, where: str) -> int:
        """Runs a COUNT and reads the number back, whatever the client wraps it in."""
        code, out = self.sql(f"SELECT COUNT(*) FROM member_relationships WHERE {where};")
        if code != 0:
            return -1

        numbers = re.findall(r"^\s*(\d+)\s*$", out, re.M)
        return int(numbers[-1]) if numbers else -1

    def audit_count(self, where: str) -> int:
        code, out = self.sql(f"SELECT COUNT(*) FROM audit_log WHERE {where};")
        if code != 0:
            return -1

        numbers = re.findall(r"^\s*(\d+)\s*$", out, re.M)
        return int(numbers[-1]) if numbers else -1

    def check(self, description: str, actual, expected) -> None:
        ok = actual == expected
        print(f"  {'PASS' if ok else 'FAIL'}  {description}")
        if ok:
            self.passed += 1
        else:
            self.failures.append(f"{description}: expected {expected}, got {actual}")
            print(f"          expected {expected}, got {actual}")

    def check_that(self, description: str, ok: bool, detail: str = "") -> None:
        print(f"  {'PASS' if ok else 'FAIL'}  {description}")
        if ok:
            self.passed += 1
        else:
            self.failures.append(f"{description}{': ' + detail if detail else ''}")
            if detail:
                print(f"          {detail}")


def vocabulary_is_enforced(checks: Checks) -> None:
    """grant_member_relationship refuses a type outside the vocabulary, and writes nothing when it does.

    The type is compared exactly, so a type in the wrong case or with a trailing space is outside the
    vocabulary too, on every engine, whatever the column's collation would let through.
    """
    engine = checks.engine
    print()
    print("granting only what the vocabulary names")

    for label, relationship_type in (("a type outside the vocabulary", "not_a_type"),
                                     ("a type in the wrong case", "EMPLOYEE"),
                                     ("a type with a trailing space", "employee ")):
        code, out = checks.sql(engine.call("grant_member_relationship",
                                           f"'{EXACT_SUBJECT}'", f"'{relationship_type}'", "NULL", "'checks'"))
        checks.check_that(f"granting {label} raises an error", NOT_IN_VOCABULARY in out, out.strip()[:160])
        checks.check("...and creates no relationship", checks.count(EXACT_SUBJECTS), 0)
        checks.check("...and writes no audit entry", checks.audit_count(EXACT_SUBJECTS), 0)


def padded_subjects_are_refused(checks: Checks) -> None:
    """grant_member_relationship refuses a subject identifier with leading or trailing spaces, and writes
    nothing when it does.

    The same rule as for a type with spaces around it. Such an identifier is almost certainly a mistake in
    the organisation's own system, and on MySQL and SQL Server a relationship granted to "subject " would
    also block a later grant of the same type to "subject", because their unique index ignores the
    trailing space.
    """
    engine = checks.engine
    print()
    print("granting only to a subject identifier without spaces around it")

    for label, subject in (("a leading space", LEADING_SUBJECT), ("a trailing space", PADDED_SUBJECT)):
        code, out = checks.sql(engine.call("grant_member_relationship",
                                           f"'{subject}'", "'employee'", "NULL", "'checks'"))
        checks.check_that(f"granting to a subject identifier with {label} raises an error",
                          SPACES_AROUND_SUBJECT in out, out.strip()[:160])
        checks.check("...and creates no relationship", checks.count(EXACT_SUBJECTS), 0)
        checks.check("...and writes no audit entry", checks.audit_count(EXACT_SUBJECTS), 0)

        # Back to nothing, whatever the grant did, so a grant wrongly accepted here does not also fail the
        # checks that follow.
        checks.sql(f"DELETE FROM member_relationships WHERE {EXACT_SUBJECTS};"
                   f"DELETE FROM audit_log WHERE {EXACT_SUBJECTS};")


def matching_is_exact(checks: Checks) -> None:
    """Every procedure matches the subject identifier and the type exactly, trailing spaces included.

    MySQL's utf8mb4_bin and SQL Server's Latin1_General_100_BIN2 both ignore trailing spaces when they
    compare, so without a further comparison a call naming "subject " would act on "subject". PostgreSQL
    compares exactly already, and runs the same checks.
    """
    engine = checks.engine
    print()
    print("matching the subject and type exactly, trailing spaces included")

    checks.sql(engine.call("grant_member_relationship", f"'{EXACT_SUBJECT}'", "'employee'", "NULL", "'checks'"))
    checks.check("a grant of a type in the vocabulary still succeeds",
                 checks.count(f"iam_subject_id = '{EXACT_SUBJECT}' AND relationship_type = 'employee'"), 1)

    untouched = (f"iam_subject_id = '{EXACT_SUBJECT}' AND relationship_type = 'employee' "
                 "AND revoked_at IS NULL AND expires_at IS NULL")
    future = engine.timestamp("2999-01-01 00:00:00")

    for label, subject, relationship_type in (("a subject with a trailing space", PADDED_SUBJECT, "employee"),
                                              ("a type with a trailing space", EXACT_SUBJECT, "employee ")):
        calls = (
            ("an organisation revoke", True,
             engine.call("revoke_member_relationship", f"'{subject}'", f"'{relationship_type}'", "'checks'")),
            ("an extend", True,
             engine.call("extend_member_relationship", f"'{subject}'", f"'{relationship_type}'", future,
                         "'checks'")),
            ("a member's own revoke", False,
             engine.call("member_self_revoke_relationship", f"'{subject}'", f"'{relationship_type}'")),
        )
        for action, raises, call in calls:
            # Back to the state a match would visibly change, whatever the previous call did.
            checks.sql(f"UPDATE member_relationships SET revoked_at = NULL, expires_at = NULL "
                       f"WHERE {EXACT_SUBJECTS};")
            before = checks.audit_count(EXACT_SUBJECTS)

            code, out = checks.sql(call)
            checks.check(f"{action} naming {label} leaves the relationship untouched",
                         checks.count(untouched), 1)
            checks.check("...and writes no audit entry", checks.audit_count(EXACT_SUBJECTS), before)
            if raises:
                checks.check_that("...and raises an error, because nothing matched", NO_MATCH in out,
                                  out.strip()[:160])


def missing_relationships_are_refused(checks: Checks) -> None:
    """Revoking or extending a relationship that does not exist raises an error and writes no audit entry.

    An audit entry recording a revoke that changed nothing would tell a reader something happened that did
    not. A member's own revoke stays a quiet no-op, so a repeated click on the self-service page is harmless.
    """
    engine = checks.engine
    print()
    print("refusing to revoke or extend a relationship that does not exist")

    future = engine.timestamp("2999-01-01 00:00:00")
    missing = (
        ("an organisation revoke", True,
         engine.call("revoke_member_relationship", f"'{EXACT_SUBJECT}'", "'tenant'", "'checks'")),
        ("an extend", True,
         engine.call("extend_member_relationship", f"'{EXACT_SUBJECT}'", "'tenant'", future, "'checks'")),
        ("a member's own revoke", False,
         engine.call("member_self_revoke_relationship", f"'{EXACT_SUBJECT}'", "'tenant'")),
    )
    for action, raises, call in missing:
        before = checks.audit_count(EXACT_SUBJECTS)
        code, out = checks.sql(call)
        checks.check(f"{action} of a relationship the member does not hold writes no audit entry",
                     checks.audit_count(EXACT_SUBJECTS), before)
        if raises:
            checks.check_that("...and raises an error", NO_MATCH in out, out.strip()[:160])
        else:
            checks.check_that("...and raises no error, as before", NO_MATCH not in out and code == 0,
                              out.strip()[:160])

    # A match that changes nothing is still a match. Extending to the expiry the relationship already has,
    # while it is not revoked, must succeed and be recorded, on an engine that counts rows changed as much
    # as on one that counts rows matched.
    checks.sql(f"UPDATE member_relationships SET revoked_at = NULL, expires_at = NULL WHERE {EXACT_SUBJECTS};")
    before = checks.audit_count(EXACT_SUBJECTS)
    code, out = checks.sql(engine.call("extend_member_relationship",
                                       f"'{EXACT_SUBJECT}'", "'employee'", "NULL", "'checks'"))
    checks.check_that("extending a relationship to the expiry it already has succeeds", NO_MATCH not in out,
                      out.strip()[:160])
    checks.check("...and writes its audit entry", checks.audit_count(EXACT_SUBJECTS), before + 1)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--sql-command", required=True,
                        help="Shell command that reads SQL on stdin and runs it against the database.")
    parser.add_argument("--database", required=True, choices=sorted(NAMES))
    args = parser.parse_args()

    engine = Engine(args.database)
    checks = Checks(args.sql_command, engine)

    print(f"Checking the data model on {args.database}")

    # Leave nothing behind from a previous run, so a re-run means the same thing as a first run.
    subjects = f"'{SUBJECT}', '{OTHER_SUBJECT}', '{EXACT_SUBJECT}', '{PADDED_SUBJECT}', '{LEADING_SUBJECT}'"
    cleanup = (f"DELETE FROM member_relationships WHERE iam_subject_id IN ({subjects});"
               f"DELETE FROM audit_log WHERE iam_subject_id IN ({subjects});")
    code, out = checks.sql(cleanup)
    if code != 0:
        print("Could not reach the database, or the schema is not present:")
        print("  " + out.strip()[:300])
        return 2

    # -- Granting ------------------------------------------------------------------------------------
    print()
    print("granting")

    code, out = checks.sql(engine.call("grant_member_relationship",
                                       f"'{SUBJECT}'", "'employee'", "NULL", "'checks'"))
    checks.check_that("a grant succeeds", code == 0, out.strip()[:160])
    checks.check("it creates exactly one relationship",
                 checks.count(f"iam_subject_id = '{SUBJECT}'"), 1)

    # The point of making public_key nullable: the organisation grants, the member keys it later.
    checks.check("the new relationship has no key yet",
                 checks.count(f"iam_subject_id = '{SUBJECT}' AND public_key IS NULL"), 1)

    code, out = checks.sql(engine.call("grant_member_relationship",
                                       f"'{SUBJECT}'", "'client'", "'Premier'", "'checks'"))
    checks.check_that("a second, different relationship type is allowed", code == 0, out.strip()[:160])
    checks.check("the member now holds two relationships",
                 checks.count(f"iam_subject_id = '{SUBJECT}'"), 2)

    # This is the SQL Server NULL-uniqueness trap. On an engine that treats NULLs as equal, the second
    # grant above fails and this count is 1.
    checks.check("both relationships can be unkeyed at the same time",
                 checks.count(f"iam_subject_id = '{SUBJECT}' AND public_key IS NULL"), 2)

    # Asserted on the resulting state, not on the client's exit code. Whether a command-line client
    # reports a failed statement as a non-zero exit depends on its flags -- psql needs ON_ERROR_STOP,
    # sqlcmd needs -b -- and the caller supplies that command, so a check reading the exit code would
    # be testing their invocation rather than the database. The row count cannot be argued with.
    checks.sql(engine.call("grant_member_relationship",
                           f"'{SUBJECT}'", "'employee'", "NULL", "'checks'"))
    checks.check("granting the same type twice creates no second row",
                 checks.count(f"iam_subject_id = '{SUBJECT}' AND relationship_type = 'employee'"), 1)

    # -- Keys ----------------------------------------------------------------------------------------
    print()
    print("keys")

    key_a = engine.binary(KEY_A)
    code, out = checks.sql(
        f"UPDATE member_relationships SET public_key = {key_a} "
        f"WHERE iam_subject_id = '{SUBJECT}' AND relationship_type = 'employee';")
    checks.check_that("a key can be set on one relationship", code == 0, out.strip()[:160])

    # Setting one relationship's key must not touch another's. This is what makes selective disclosure
    # real rather than nominal.
    checks.check("the member's other relationship is still unkeyed",
                 checks.count(f"iam_subject_id = '{SUBJECT}' AND relationship_type = 'client' "
                              "AND public_key IS NULL"), 1)

    checks.sql(engine.call("grant_member_relationship",
                           f"'{OTHER_SUBJECT}'", "'employee'", "NULL", "'checks'"))
    checks.sql(f"UPDATE member_relationships SET public_key = {key_a} "
               f"WHERE iam_subject_id = '{OTHER_SUBJECT}';")

    # Again on state: the key must still belong to exactly one relationship. This is the constraint that
    # stops someone who observed another member's key registering it as their own.
    checks.check("a key already in use cannot be taken by another relationship",
                 checks.count(f"public_key = {key_a}"), 1)

    key_b = engine.binary(KEY_B)
    checks.sql(f"UPDATE member_relationships SET public_key = {key_b} "
               f"WHERE iam_subject_id = '{OTHER_SUBJECT}';")
    checks.check("a key nobody else holds is accepted",
                 checks.count(f"iam_subject_id = '{OTHER_SUBJECT}' AND public_key = {key_b}"), 1)

    # -- Revoking and extending ----------------------------------------------------------------------
    print()
    print("revoking and extending, one relationship at a time")

    checks.sql(engine.call("revoke_member_relationship",
                           f"'{SUBJECT}'", "'employee'", "'checks'"))
    checks.check("the named relationship is revoked",
                 checks.count(f"iam_subject_id = '{SUBJECT}' AND relationship_type = 'employee' "
                              "AND revoked_at IS NOT NULL"), 1)
    checks.check("the member's other relationship is untouched",
                 checks.count(f"iam_subject_id = '{SUBJECT}' AND relationship_type = 'client' "
                              "AND revoked_at IS NULL"), 1)
    checks.check("another member's relationship of the same type is untouched",
                 checks.count(f"iam_subject_id = '{OTHER_SUBJECT}' AND revoked_at IS NULL"), 1)

    checks.sql(engine.call("extend_member_relationship",
                           f"'{SUBJECT}'", "'employee'", engine.timestamp("2999-01-01 00:00:00"),
                           "'checks'"))
    checks.check("extending clears the revocation",
                 checks.count(f"iam_subject_id = '{SUBJECT}' AND relationship_type = 'employee' "
                              "AND revoked_at IS NULL"), 1)
    checks.check("and sets the new expiry",
                 checks.count(f"iam_subject_id = '{SUBJECT}' AND relationship_type = 'employee' "
                              "AND expires_at IS NOT NULL"), 1)

    # -- The audit trail -----------------------------------------------------------------------------
    print()
    print("the audit trail")

    code, out = checks.sql(
        f"SELECT COUNT(*) FROM audit_log WHERE iam_subject_id = '{SUBJECT}';")
    numbers = re.findall(r"^\s*(\d+)\s*$", out, re.M)
    written = int(numbers[-1]) if numbers else -1

    # Two grants, one revoke, one extend. Every organisation-initiated call writes exactly one row, in
    # the same call as the change it records.
    checks.check("every organisation action wrote exactly one audit row", written, 4)

    code, out = checks.sql(
        f"SELECT COUNT(*) FROM audit_log WHERE iam_subject_id = '{SUBJECT}' AND actor IS NULL;")
    numbers = re.findall(r"^\s*(\d+)\s*$", out, re.M)
    checks.check("each names the actor that made the change",
                 int(numbers[-1]) if numbers else -1, 0)

    # A self-revoke and an organisation revoke are different events, and the trail has to say which
    # happened. The distinction is what an audit reader needs most: whether the member stepped back or
    # the organisation withdrew its word. Conflating them would make the log describe neither.
    checks.sql(engine.call("member_self_revoke_relationship", f"'{OTHER_SUBJECT}'", "'employee'"))

    checks.check("a member's own revoke is recorded under its own event type",
                 checks.audit_count(f"iam_subject_id = '{OTHER_SUBJECT}' "
                                    "AND event_type = 'relationship_self_revoked'"), 1)
    checks.check("...with no actor, because the member acted for themselves",
                 checks.audit_count(f"iam_subject_id = '{OTHER_SUBJECT}' "
                                    "AND event_type = 'relationship_self_revoked' AND actor IS NULL"), 1)
    checks.check("an organisation revoke is a different event type",
                 checks.audit_count(f"iam_subject_id = '{SUBJECT}' "
                                    "AND event_type = 'relationship_revoked_by_org'"), 1)
    checks.check("...and names who did it",
                 checks.audit_count(f"iam_subject_id = '{SUBJECT}' "
                                    "AND event_type = 'relationship_revoked_by_org' AND actor IS NOT NULL"), 1)

    # Idempotent, and quiet about it. Revoking twice must not put a second entry in a log that cannot be
    # corrected afterwards.
    checks.sql(engine.call("member_self_revoke_relationship", f"'{OTHER_SUBJECT}'", "'employee'"))
    checks.check("revoking again writes no second entry",
                 checks.audit_count(f"iam_subject_id = '{OTHER_SUBJECT}' "
                                    "AND event_type = 'relationship_self_revoked'"), 1)

    # -- Two grants at once --------------------------------------------------------------------------
    #
    # The uniqueness constraint is what actually decides this, not the check any caller might make first.
    # Two organisation systems syncing at the same moment is an ordinary event, and a duplicate row would
    # leave PUT, revoke and extend unable to say which of two identical relationships they meant.
    print()
    print("two grants of the same relationship at once")

    racer = "schema-check-racer"
    checks.sql(f"DELETE FROM member_relationships WHERE iam_subject_id = '{racer}';"
               f"DELETE FROM audit_log WHERE iam_subject_id = '{racer}';")

    grant = engine.call("grant_member_relationship", f"'{racer}'", "'employee'", "NULL", "'checks'")

    # Separate client processes, so these are genuinely two connections rather than two statements
    # sharing one. A single session would serialise them and prove nothing.
    threads = [threading.Thread(target=checks.sql, args=(grant,)) for _ in range(2)]
    for thread in threads:
        thread.start()
    for thread in threads:
        thread.join()

    checks.check("exactly one of the two grants created a row",
                 checks.count(f"iam_subject_id = '{racer}'"), 1)

    # The audit row is written inside the same call as the change, so a grant that lost the race must
    # not have left an entry claiming it happened.
    checks.check("...and only the one that won wrote an audit entry",
                 checks.audit_count(f"iam_subject_id = '{racer}'"), 1)

    checks.sql(f"DELETE FROM member_relationships WHERE iam_subject_id = '{racer}';"
               f"DELETE FROM audit_log WHERE iam_subject_id = '{racer}';")

    # -- The vocabulary, padded subjects, exact matching, and relationships that do not exist ------------
    vocabulary_is_enforced(checks)
    padded_subjects_are_refused(checks)
    matching_is_exact(checks)
    missing_relationships_are_refused(checks)

    # -- Tidy up -------------------------------------------------------------------------------------
    checks.sql(cleanup)

    print()
    print(f"{checks.passed} passed, {len(checks.failures)} failed")

    if checks.failures:
        print()
        print("Failures:")
        for failure in checks.failures:
            print(f"  - {failure}")
        return 1

    return 0


if __name__ == "__main__":
    sys.exit(main())
