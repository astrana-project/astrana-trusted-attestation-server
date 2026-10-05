#!/usr/bin/env python3
"""Checks that the database actually enforces what decision record 16 in docs/adr says it enforces.

The design rests on a claim the application cannot make for itself: that organisation-only operations
are organisation-only *in the database*, not merely absent from the API. Keeping grant out of the HTTP
surface stops a member self-granting through the front door. What stops it through any other door is
that the application's own database principal has no privilege to insert a relationship row at all.

The grants exist in the schema files only as commented examples, so nothing else ever runs them. Comments
cannot be wrong in a way that fails. They can only be wrong in a way that gets copied into a deployment. These checks run the documented grants and
then try, as each principal, to do the things decision record 16 says that principal must not be able to.

    python shared/test/role-checks.py --database postgres \\
        --sql-command "docker exec -i trusted-attestation-dev-postgres-1 psql -q -t -A -U u -d d"

Every "cannot" is asserted on the resulting state, never on the client's exit code. Whether a failed
statement produces a non-zero exit depends on flags the caller supplies -- psql needs ON_ERROR_STOP,
sqlcmd needs -b -- so a check reading the exit code would be testing their invocation rather than the
database. Whether the row changed cannot be argued with.

Destructive: it creates and drops its own principals and rows, all namespaced. Point it at a development
database.

Standard library only.
"""

from __future__ import annotations

import argparse
import pathlib
import re
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from engines import Engine, NAMES, run_sql  # noqa: E402

# Namespaced so a run cannot collide with dev data or with a previous run of itself.
SUBJECT = "role-check-subject"

APPLICATION = "ata_check_application"
ORGANISATION = "ata_check_organisation"

# A password only MySQL and SQL Server require. PostgreSQL roles here are never logged into directly.
PASSWORD = "Role-Check-Password1"


class Checks:
    def __init__(self, sql_command: str, engine: Engine,
                 connections: dict[str, str] | None = None) -> None:
        self.sql_command = sql_command
        self.engine = engine
        self.connections = connections or {}
        self.passed = 0
        self.failures: list[str] = []
        self.skipped: list[str] = []

    def sql(self, statements: str) -> tuple[int, str]:
        """Runs a batch as the privileged connection the caller supplied."""
        return run_sql(self.sql_command, self.engine.prelude + statements)

    def as_user(self, user: str, statements: str) -> None:
        """Attempts a batch as a restricted principal. The outcome is read from the table afterwards.

        A connection the caller supplied for that principal is used in preference to in-session
        impersonation: a real second connection is what a deployment actually has, and it is the only
        option at all on an engine that cannot drop privileges mid-session.
        """
        command = self.connections.get(user)
        if command:
            run_sql(command, self.engine.prelude + statements)
            return

        wrapped = self.engine.as_user(user, statements)
        if wrapped is not None:
            self.sql(wrapped)

    def can_be(self, user: str) -> bool:
        return bool(self.connections.get(user)) or self.engine.as_user(user, "SELECT 1;") is not None

    def scalar(self, query: str) -> int:
        code, out = self.sql(query)
        if code != 0:
            return -1

        numbers = re.findall(r"^\s*(-?\d+)\s*$", out, re.M)
        return int(numbers[-1]) if numbers else -1

    def section(self, title: str) -> None:
        print()
        print(title)

    def check(self, description: str, actual, expected) -> None:
        ok = actual == expected
        print(f"  {'PASS' if ok else 'FAIL'}  {description}")
        if ok:
            self.passed += 1
        else:
            self.failures.append(f"{description}: expected {expected}, got {actual}")
            print(f"          expected {expected}, got {actual}")

    def skip(self, title: str, reason: str) -> None:
        """Announced, never silent. A section that skips quietly reads exactly like one that passed."""
        self.skipped.append(title)
        print()
        print(f"{title}: skipped -- {reason}")


# ---------------------------------------------------------------------------------------------------
# The grants under test
# ---------------------------------------------------------------------------------------------------
#
# Transcribed from the example grants in each file under shared/schema. That is the point: these checks
# exist to find out whether the guidance an operator will copy actually produces the separation
# decision record 16 claims. If a grant here has to be changed to make the checks pass, the schema comment is
# wrong and the deployment following it is wrong too.


def setup_statements(engine: Engine) -> str:
    if engine.name == "postgres":
        return f"""
CREATE ROLE {APPLICATION};
CREATE ROLE {ORGANISATION};

-- PostgreSQL grants EXECUTE on every routine to PUBLIC by default, so the grants below mean nothing
-- until this is revoked.
REVOKE EXECUTE ON PROCEDURE grant_member_relationship FROM PUBLIC;
REVOKE EXECUTE ON PROCEDURE revoke_member_relationship FROM PUBLIC;
REVOKE EXECUTE ON PROCEDURE extend_member_relationship FROM PUBLIC;
REVOKE EXECUTE ON PROCEDURE member_self_revoke_relationship FROM PUBLIC;
REVOKE EXECUTE ON PROCEDURE prune_audit_log FROM PUBLIC;

GRANT SELECT, DELETE, UPDATE (public_key) ON member_relationships TO {APPLICATION};
GRANT INSERT ON audit_log TO {APPLICATION};
-- INSERT alone is not enough for a BIGSERIAL id: the default reads the sequence.
GRANT USAGE ON SEQUENCE audit_log_id_seq TO {APPLICATION};
GRANT EXECUTE ON PROCEDURE member_self_revoke_relationship TO {APPLICATION};
-- The application prunes its own audit trail as itself; the procedure deletes only rows past the
-- retention window, so this is controlled retention, not the ad hoc DELETE it is denied elsewhere.
GRANT EXECUTE ON PROCEDURE prune_audit_log TO {APPLICATION};

GRANT EXECUTE ON PROCEDURE grant_member_relationship TO {ORGANISATION};
GRANT EXECUTE ON PROCEDURE revoke_member_relationship TO {ORGANISATION};
GRANT EXECUTE ON PROCEDURE extend_member_relationship TO {ORGANISATION};

-- Not part of the deployment guidance, and not under test: SET ROLE requires the connected user to be
-- a member of the role it is switching to. This is only how the checks get to *be* each principal.
GRANT {APPLICATION} TO CURRENT_USER;
GRANT {ORGANISATION} TO CURRENT_USER;
"""

    if engine.name == "mssql":
        return f"""
DROP USER IF EXISTS {APPLICATION};
DROP USER IF EXISTS {ORGANISATION};

CREATE USER {APPLICATION} WITHOUT LOGIN;
CREATE USER {ORGANISATION} WITHOUT LOGIN;

GRANT SELECT, DELETE ON member_relationships TO {APPLICATION};
GRANT UPDATE ON member_relationships(public_key) TO {APPLICATION};
GRANT INSERT ON audit_log TO {APPLICATION};
GRANT EXECUTE ON member_self_revoke_relationship TO {APPLICATION};
GRANT EXECUTE ON prune_audit_log TO {APPLICATION};

GRANT EXECUTE ON grant_member_relationship TO {ORGANISATION};
GRANT EXECUTE ON revoke_member_relationship TO {ORGANISATION};
GRANT EXECUTE ON extend_member_relationship TO {ORGANISATION};
"""

    if engine.name == "mysql":
        # Real accounts, because MySQL cannot drop privileges within a session. The caller connects as
        # each of these separately, see --as-application-command.
        return f"""
CREATE USER '{APPLICATION}'@'%' IDENTIFIED BY '{PASSWORD}';
CREATE USER '{ORGANISATION}'@'%' IDENTIFIED BY '{PASSWORD}';

GRANT SELECT, DELETE ON member_relationships TO '{APPLICATION}'@'%';
GRANT UPDATE (public_key) ON member_relationships TO '{APPLICATION}'@'%';
GRANT INSERT ON audit_log TO '{APPLICATION}'@'%';
GRANT EXECUTE ON PROCEDURE member_self_revoke_relationship TO '{APPLICATION}'@'%';
GRANT EXECUTE ON PROCEDURE prune_audit_log TO '{APPLICATION}'@'%';

GRANT EXECUTE ON PROCEDURE grant_member_relationship TO '{ORGANISATION}'@'%';
GRANT EXECUTE ON PROCEDURE revoke_member_relationship TO '{ORGANISATION}'@'%';
GRANT EXECUTE ON PROCEDURE extend_member_relationship TO '{ORGANISATION}'@'%';
"""

    return ""


def teardown_statements(engine: Engine) -> str:
    if engine.name == "postgres":
        # DROP OWNED BY drops the grants held by the role, which DROP ROLE will not do for it. Each is
        # its own statement so that one failing -- the role not existing yet -- does not skip the rest.
        return (f"DROP OWNED BY {APPLICATION};{chr(10)}"
                f"DROP ROLE IF EXISTS {APPLICATION};{chr(10)}"
                f"DROP OWNED BY {ORGANISATION};{chr(10)}"
                f"DROP ROLE IF EXISTS {ORGANISATION};")

    if engine.name == "mssql":
        return f"DROP USER IF EXISTS {APPLICATION}; DROP USER IF EXISTS {ORGANISATION};"

    if engine.name == "mysql":
        return (f"DROP USER IF EXISTS '{APPLICATION}'@'%';{chr(10)}"
                f"DROP USER IF EXISTS '{ORGANISATION}'@'%';")

    return ""


# ---------------------------------------------------------------------------------------------------
# The checks
# ---------------------------------------------------------------------------------------------------


def fixture(checks: Checks) -> bool:
    """One granted, keyed relationship for the restricted principals to be refused access to."""
    engine = checks.engine

    checks.sql(f"DELETE FROM member_relationships WHERE iam_subject_id = '{SUBJECT}';"
               f"DELETE FROM audit_log WHERE iam_subject_id = '{SUBJECT}';")

    code, out = checks.sql(engine.call("grant_member_relationship",
                                       f"'{SUBJECT}'", "'employee'", "NULL", "'role-checks'"))
    if code != 0:
        print("Could not create the fixture; is the schema present?")
        print("  " + out.strip()[:300])
        return False

    checks.sql(f"UPDATE member_relationships SET public_key = {engine.binary('cc' * 32)} "
               f"WHERE iam_subject_id = '{SUBJECT}';")
    return True


def application_role(checks: Checks) -> None:
    """What the running application may and may not do.

    The application services self-service requests, so it needs exactly one column of write access on
    member_relationships and nothing else. Every other column on that table is organisation-only, and
    organisation-only has to mean it here rather than only in the routing table.
    """
    engine = checks.engine
    checks.section("the application's principal")

    where = f"WHERE iam_subject_id = '{SUBJECT}'"

    # The one write it is supposed to have.
    checks.as_user(APPLICATION,
                   f"UPDATE member_relationships SET public_key = {engine.binary('dd' * 32)} {where};")
    checks.check("it can set a member's key",
                 checks.scalar(f"SELECT COUNT(*) FROM member_relationships {where} "
                               f"AND public_key = {engine.binary('dd' * 32)};"), 1)

    # The same key change written the way an object-relational mapper saves a whole entity: every column
    # named in SET, each but the key at its current value. The grant is per column named, not per column
    # changed, so all three engines refuse this shape and the key stays as it was. That is what the
    # documented grants do to an implementation whose mapper drifts back to full-row saves: its key
    # writes fail under the deployment role while passing every test run as the database owner. The
    # implementations write public_key alone for exactly this reason, and this check is the record of why.
    checks.as_user(APPLICATION,
                   f"UPDATE member_relationships SET iam_subject_id = '{SUBJECT}', "
                   f"public_key = {engine.binary('ee' * 32)}, relationship_type = 'employee', "
                   f"relationship_subtype = NULL, expires_at = NULL, revoked_at = NULL {where};")
    checks.check("a full-row update naming every column, the shape a mapper saving a whole entity emits, "
                 "is refused even when only the key changes",
                 checks.scalar(f"SELECT COUNT(*) FROM member_relationships {where} "
                               f"AND public_key = {engine.binary('ee' * 32)};"), 0)
    checks.check("...and the key it carried is not written",
                 checks.scalar(f"SELECT COUNT(*) FROM member_relationships {where} "
                               f"AND public_key = {engine.binary('dd' * 32)};"), 1)

    # The four it must not. A member must not be able to lift a revocation, change what the organisation
    # vouched for, or extend their own standing, and the application is the thing acting on their behalf.
    checks.as_user(APPLICATION, f"UPDATE member_relationships SET revoked_at = "
                                f"{engine.timestamp('2020-01-01 00:00:00')} {where};")
    checks.check("it cannot revoke a relationship by writing revoked_at",
                 checks.scalar(f"SELECT COUNT(*) FROM member_relationships {where} "
                               f"AND revoked_at IS NOT NULL;"), 0)

    checks.as_user(APPLICATION, f"UPDATE member_relationships SET expires_at = "
                                f"{engine.timestamp('2999-01-01 00:00:00')} {where};")
    checks.check("it cannot extend a relationship by writing expires_at",
                 checks.scalar(f"SELECT COUNT(*) FROM member_relationships {where} "
                               f"AND expires_at IS NOT NULL;"), 0)

    checks.as_user(APPLICATION, f"UPDATE member_relationships SET relationship_type = 'director' {where};")
    checks.check("it cannot change what the organisation vouched for",
                 checks.scalar(f"SELECT COUNT(*) FROM member_relationships {where} "
                               f"AND relationship_type = 'employee';"), 1)

    checks.as_user(APPLICATION, f"UPDATE member_relationships SET relationship_subtype = 'Fellow' {where};")
    checks.check("it cannot change the subtype either",
                 checks.scalar(f"SELECT COUNT(*) FROM member_relationships {where} "
                               f"AND relationship_subtype IS NULL;"), 1)

    # The one that matters most. If the application's principal can insert a row, then every guard in
    # the API against self-granting is a formality: anything able to run SQL as the application can hand
    # itself a relationship the organisation never granted.
    checks.as_user(APPLICATION,
                   "INSERT INTO member_relationships (iam_subject_id, relationship_type) "
                   f"VALUES ('{SUBJECT}', 'director');")
    checks.check("it cannot grant a relationship directly",
                 checks.scalar(f"SELECT COUNT(*) FROM member_relationships "
                               f"WHERE iam_subject_id = '{SUBJECT}' AND relationship_type = 'director';"), 0)

    checks.as_user(APPLICATION, engine.call("grant_member_relationship",
                                            f"'{SUBJECT}'", "'trustee'", "NULL", "'role-checks'"))
    checks.check("...nor by calling the procedure that does",
                 checks.scalar(f"SELECT COUNT(*) FROM member_relationships "
                               f"WHERE iam_subject_id = '{SUBJECT}' AND relationship_type = 'trustee';"), 0)

    checks.as_user(APPLICATION, engine.call("revoke_member_relationship",
                                            f"'{SUBJECT}'", "'employee'", "'role-checks'"))
    checks.check("...nor revoke one on the organisation's behalf",
                 checks.scalar(f"SELECT COUNT(*) FROM member_relationships {where} "
                               f"AND revoked_at IS NOT NULL;"), 0)

    # What it may do on the member's behalf: revoke the relationship, through the procedure that can only
    # move revoked_at one way. This is the whole reason self-revoke is a procedure and not a column grant.
    checks.as_user(APPLICATION, engine.call("member_self_revoke_relationship",
                                            f"'{SUBJECT}'", "'employee'"))
    checks.check("it can self-revoke on the member's behalf, through the procedure",
                 checks.scalar(f"SELECT COUNT(*) FROM member_relationships {where} "
                               f"AND revoked_at IS NOT NULL;"), 1)

    # It prunes its own audit trail, through the retention procedure. This is controlled deletion -- the
    # procedure removes only rows past the retention window -- not the ad hoc DELETE on audit_log it is
    # denied. The application does this itself (a scheduled or opportunistic task), so as itself it must be
    # able to. An ancient row, far outside any retention window, that it should be able to remove:
    checks.sql("INSERT INTO audit_log (event_type, iam_subject_id, relationship_type, actor, occurred_at) "
               f"VALUES ('ancient-app', '{SUBJECT}', 'employee', NULL, {engine.timestamp('2000-01-01 00:00:00')});")
    checks.as_user(APPLICATION, engine.call("prune_audit_log", "1825"))
    checks.check("it can prune the audit trail through the retention procedure",
                 checks.scalar(f"SELECT COUNT(*) FROM audit_log WHERE iam_subject_id = '{SUBJECT}' "
                               f"AND event_type = 'ancient-app';"), 0)


def organisation_role(checks: Checks) -> None:
    """What the organisation's principal may and may not do.

    Only EXECUTE, deliberately. Direct table access would let an organisation change standing with ad hoc
    SQL that skips the audit insert the procedures perform -- and an audit trail that a privileged user
    can quietly go around is not one.
    """
    engine = checks.engine
    checks.section("the organisation's principal")

    where = f"WHERE iam_subject_id = '{SUBJECT}'"

    checks.as_user(ORGANISATION, f"UPDATE member_relationships SET revoked_at = NULL {where};")
    checks.check("it cannot clear a revocation with ad hoc SQL",
                 checks.scalar(f"SELECT COUNT(*) FROM member_relationships {where} "
                               f"AND revoked_at IS NOT NULL;"), 1)

    checks.as_user(ORGANISATION, f"DELETE FROM member_relationships {where};")
    checks.check("it cannot delete a relationship directly",
                 checks.scalar(f"SELECT COUNT(*) FROM member_relationships {where} "
                               f"AND relationship_type = 'employee';"), 1)

    # What it may do, and the audit row that comes with it. extend clears revoked_at, which is the only
    # sanctioned way back from a revocation.
    before = checks.scalar(f"SELECT COUNT(*) FROM audit_log WHERE iam_subject_id = '{SUBJECT}';")
    checks.as_user(ORGANISATION, engine.call("extend_member_relationship",
                                             f"'{SUBJECT}'", "'employee'", "NULL", "'role-checks'"))
    checks.check("it can restore a relationship through the procedure",
                 checks.scalar(f"SELECT COUNT(*) FROM member_relationships {where} "
                               f"AND relationship_type = 'employee' AND revoked_at IS NULL;"), 1)
    checks.check("...and doing so wrote an audit row",
                 checks.scalar(f"SELECT COUNT(*) FROM audit_log WHERE iam_subject_id = '{SUBJECT}';"),
                 before + 1)

    # It cannot prune the audit trail. Retention is the application's job, through the one procedure it is
    # granted. The organisation principal has no EXECUTE on it, so an ancient row it tries to prune stays.
    checks.sql("INSERT INTO audit_log (event_type, iam_subject_id, relationship_type, actor, occurred_at) "
               f"VALUES ('ancient-org', '{SUBJECT}', 'employee', NULL, {engine.timestamp('2000-01-01 00:00:00')});")
    checks.as_user(ORGANISATION, engine.call("prune_audit_log", "1825"))
    checks.check("it cannot prune the audit trail",
                 checks.scalar(f"SELECT COUNT(*) FROM audit_log WHERE iam_subject_id = '{SUBJECT}' "
                               f"AND event_type = 'ancient-org';"), 1)


PROCEDURES = ("grant_member_relationship", "revoke_member_relationship", "extend_member_relationship",
              "member_self_revoke_relationship", "prune_audit_log")


def temporary_tables_cannot_shadow(checks: Checks) -> None:
    """A caller's temporary table must not capture what a procedure writes.

    The PostgreSQL procedures run as their owner (SECURITY DEFINER) but, unless they say otherwise, resolve
    table names through the caller's search_path, and the caller's temporary schema sits at the front of
    it. A caller who creates a temporary table named audit_log would have the procedure write its audit
    row there, into a table that vanishes with the session, and the real trail would never see it. The
    schema file pins each procedure's search_path to stop that. This creates the temporary tables as each
    principal, calls the procedure that principal is granted, and reads the real tables afterwards.

    PostgreSQL only. On MySQL the documented grants withhold CREATE TEMPORARY TABLES, so the account
    cannot create the shadow in the first place, and SQL Server names its temporary tables with a # prefix
    that no unqualified table name can collide with.
    """
    engine = checks.engine
    title = "a temporary table cannot capture what a procedure writes"

    if engine.name != "postgres":
        checks.skip(title, f"{engine.name} gives a caller no way to shadow a table the procedures name")
        return

    checks.section(title)

    where = f"WHERE iam_subject_id = '{SUBJECT}'"
    audit = f"SELECT COUNT(*) FROM public.audit_log {where}"

    # The procedures must say so in the catalogue: a procedure that runs as its owner without pinning its
    # search_path is the bug, named here before the behaviour below shows it.
    procedures = ", ".join(f"'{name}'" for name in PROCEDURES)
    checks.check("every procedure runs as its owner with its search_path pinned",
                 checks.scalar(f"SELECT COUNT(*) FROM pg_proc WHERE proname IN ({procedures}) AND prosecdef "
                               "AND EXISTS (SELECT 1 FROM unnest(proconfig) AS setting "
                               "WHERE setting LIKE 'search_path=%');"), len(PROCEDURES))

    # Temporary tables with the real ones' names and columns, empty, created by the caller in the same
    # session as the call. Each batch is one session, so they are gone before the real tables are read,
    # and the reads name the schema anyway.
    shadow = ("CREATE TEMPORARY TABLE audit_log (event_type TEXT, iam_subject_id TEXT, relationship_type TEXT, "
              "actor TEXT, occurred_at TIMESTAMPTZ DEFAULT now());\n"
              "CREATE TEMPORARY TABLE member_relationships (id BIGINT, iam_subject_id TEXT, public_key BYTEA, "
              "relationship_type TEXT, relationship_subtype TEXT, expires_at TIMESTAMPTZ, revoked_at TIMESTAMPTZ);\n")

    # The application, revoking on the member's behalf. The relationship is unrevoked here, the
    # organisation having restored it in the section before, so the procedure has a row to change and an
    # audit row to write, and both must land in the real tables.
    before = checks.scalar(f"{audit};")
    checks.as_user(APPLICATION, shadow + engine.call("member_self_revoke_relationship",
                                                     f"'{SUBJECT}'", "'employee'"))
    checks.check("the application's self-revoke changes the real relationship, not a temporary copy",
                 checks.scalar(f"SELECT COUNT(*) FROM public.member_relationships {where} "
                               f"AND revoked_at IS NOT NULL;"), 1)
    checks.check("...and its audit row lands in the real trail", checks.scalar(f"{audit};"), before + 1)

    # The organisation, restoring it. Same shadow, the procedure it is granted, the real tables again.
    before = checks.scalar(f"{audit};")
    checks.as_user(ORGANISATION, shadow + engine.call("extend_member_relationship",
                                                      f"'{SUBJECT}'", "'employee'", "NULL", "'role-checks'"))
    checks.check("the organisation's extend changes the real relationship, not a temporary copy",
                 checks.scalar(f"SELECT COUNT(*) FROM public.member_relationships {where} "
                               f"AND revoked_at IS NULL;"), 1)
    checks.check("...and its audit row lands in the real trail", checks.scalar(f"{audit};"), before + 1)


def audit_is_append_only(checks: Checks) -> None:
    """An audit trail that can be edited after the fact is not one.

    The application inserts into audit_log and must be able to do nothing else to it. This is the
    property that makes the trail worth reading: a change nobody could have quietly amended.
    """
    engine = checks.engine
    checks.section("the audit trail is append-only")

    where = f"WHERE iam_subject_id = '{SUBJECT}'"

    checks.as_user(APPLICATION,
                   "INSERT INTO audit_log (event_type, iam_subject_id, relationship_type, actor, occurred_at) "
                   f"VALUES ('key_registered', '{SUBJECT}', 'employee', NULL, "
                   f"{engine.timestamp('2026-08-27 00:00:00')});")
    checks.check("the application can append to it",
                 checks.scalar(f"SELECT COUNT(*) FROM audit_log {where} "
                               f"AND event_type = 'key_registered';"), 1)

    checks.as_user(APPLICATION, f"UPDATE audit_log SET event_type = 'nothing_happened' {where};")
    checks.check("it cannot rewrite an entry",
                 checks.scalar(f"SELECT COUNT(*) FROM audit_log {where} "
                               f"AND event_type = 'nothing_happened';"), 0)

    total = checks.scalar(f"SELECT COUNT(*) FROM audit_log {where};")
    checks.as_user(APPLICATION, f"DELETE FROM audit_log {where};")
    checks.check("it cannot erase one",
                 checks.scalar(f"SELECT COUNT(*) FROM audit_log {where};"), total)

    checks.as_user(ORGANISATION, f"DELETE FROM audit_log {where};")
    checks.check("neither can the organisation's principal",
                 checks.scalar(f"SELECT COUNT(*) FROM audit_log {where};"), total)


def pruning(checks: Checks) -> None:
    """Pruning removes entries by age and by nothing else.

    Retention is the one sanctioned way a row leaves this table, so the boundary matters: a procedure
    that took one day too many would be quietly destroying records an organisation is obliged to keep.
    """
    engine = checks.engine
    checks.section("pruning removes old entries, and only old ones")

    where = f"WHERE iam_subject_id = '{SUBJECT}'"
    checks.sql(f"DELETE FROM audit_log {where};")

    # Written as literals rather than computed in SQL: now() and its interval arithmetic are spelled
    # three different ways and none of that is what is being tested here.
    for label, occurred in (("ancient", "2000-01-01 00:00:00"),
                            ("recent", "2026-08-01 00:00:00")):
        checks.sql("INSERT INTO audit_log (event_type, iam_subject_id, relationship_type, actor, occurred_at) "
                   f"VALUES ('{label}', '{SUBJECT}', 'employee', NULL, {engine.timestamp(occurred)});")

    checks.check("two entries to prune between",
                 checks.scalar(f"SELECT COUNT(*) FROM audit_log {where};"), 2)

    # Five years. The ancient row is far outside it, the recent one is well inside.
    code, out = checks.sql(engine.call("prune_audit_log", "1825"))
    checks.check("prune_audit_log runs", code, 0)
    if code != 0:
        print("          " + out.strip()[:200])

    checks.check("the entry older than the retention window is gone",
                 checks.scalar(f"SELECT COUNT(*) FROM audit_log {where} AND event_type = 'ancient';"), 0)
    checks.check("the recent one is untouched",
                 checks.scalar(f"SELECT COUNT(*) FROM audit_log {where} AND event_type = 'recent';"), 1)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--sql-command", required=True,
                        help="Shell command that reads SQL on stdin and runs it as a privileged user.")
    parser.add_argument("--database", required=True, choices=sorted(NAMES))
    parser.add_argument("--as-application-command",
                        help="Shell command that runs SQL as the application's own principal. Needed on "
                             "MySQL, which cannot drop privileges within a session; optional elsewhere, "
                             "where a real second connection is used in preference to impersonation. The "
                             f"principal is {APPLICATION!r} with password {PASSWORD!r}.")
    parser.add_argument("--as-organisation-command",
                        help=f"The same for the organisation's principal, {ORGANISATION!r}.")
    args = parser.parse_args()

    engine = Engine(args.database)
    connections = {}
    if args.as_application_command:
        connections[APPLICATION] = args.as_application_command
    if args.as_organisation_command:
        connections[ORGANISATION] = args.as_organisation_command

    checks = Checks(args.sql_command, engine, connections)

    print(f"Checking the database enforces the role separation, on {args.database}")

    if not (checks.can_be(APPLICATION) and checks.can_be(ORGANISATION)):
        # Said plainly rather than passed over. MySQL has roles, but activating one adds privileges to
        # the connected account rather than replacing them, so there is no way to become less privileged
        # inside a session. Covering this on MySQL needs a second connection as each principal, which
        # the caller supplies with --as-application-command and --as-organisation-command.
        checks.skip("role separation",
                    f"{args.database} cannot restrict privileges within a session, and no "
                    "--as-application-command / --as-organisation-command were given")
        print()
        print(f"Nothing was measured. The grants in shared/schema/schema-{args.database}.sql remain unverified.")
        return 2

    # Teardown first and separately, so a leftover principal from an interrupted run does not make the
    # create below fail. Its result is deliberately ignored: on a clean database there is nothing to drop
    # and the statements error, which says nothing about anything.
    checks.sql(teardown_statements(engine))

    code, out = checks.sql(setup_statements(engine))
    if code != 0:
        print("Could not create the principals under test:")
        print("  " + out.strip()[:400])
        return 2

    try:
        if not fixture(checks):
            return 2

        application_role(checks)
        organisation_role(checks)
        temporary_tables_cannot_shadow(checks)
        audit_is_append_only(checks)
        pruning(checks)
    finally:
        checks.sql(f"DELETE FROM member_relationships WHERE iam_subject_id = '{SUBJECT}';"
                   f"DELETE FROM audit_log WHERE iam_subject_id = '{SUBJECT}';")
        checks.sql(teardown_statements(engine))

    print()
    print(f"{checks.passed} passed, {len(checks.failures)} failed")

    if checks.failures:
        print()
        print("Failures:")
        for failure in checks.failures:
            print(f"  - {failure}")
        print()
        print("A failure here means the grants documented in the schema file do not produce the "
              "separation decision record 16 describes -- so a deployment following them does not have it.")
        return 1

    return 0


if __name__ == "__main__":
    sys.exit(main())
