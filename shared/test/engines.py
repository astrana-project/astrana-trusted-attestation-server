#!/usr/bin/env python3
"""What differs between PostgreSQL, MySQL and SQL Server, in one place.

Shared by four suites under shared/test, each of which has to reach the database directly.
schema-checks.py and role-checks.py test the schema and its grants themselves. conformance/check.py and
differential_auth.py need relationships to exercise, and relationships come into existence only through
grant_member_relationship, an organisation-only stored procedure with no API in front of it (the README,
How it works).

Kept apart from its callers because the differences are easy to get subtly wrong, the QUOTED_IDENTIFIER
rule below especially, and one copy is one place to get them right.

Standard library only.
"""

from __future__ import annotations

import subprocess

NAMES = ("postgres", "mysql", "mssql")


class Engine:
    """What differs between the three, and nothing that does not."""

    def __init__(self, name: str) -> None:
        self.name = name

    def call(self, procedure: str, *args: str) -> str:
        # MySQL procedures in the authoritative schema declare no defaults, so every argument is passed
        # explicitly on every engine rather than relying on one engine's optional-argument behaviour.
        if self.name == "mssql":
            return f"EXEC {procedure} {', '.join(args)};"
        return f"CALL {procedure}({', '.join(args)});"

    def binary(self, hex_key: str) -> str:
        return {
            "postgres": f"decode('{hex_key}', 'hex')",
            "mysql": f"UNHEX('{hex_key}')",
            "mssql": f"CONVERT(varbinary(32), '{hex_key}', 2)",
        }[self.name]

    def timestamp(self, iso: str) -> str:
        return f"'{iso}'"

    @property
    def prelude(self) -> str:
        """SET options every batch needs before it can touch the table.

        SQL Server refuses any INSERT, UPDATE or DELETE against a table carrying a filtered index unless
        QUOTED_IDENTIFIER is ON, and sqlcmd connects with it OFF. Stored procedures are unaffected --
        SQL Server records the setting with the procedure when it is created -- so a grant succeeds while
        an ad-hoc UPDATE against the same table fails, with an error naming SET options rather than
        anything to do with the statement.

        This applies to anyone writing ad-hoc SQL against this table on SQL Server, not just to this
        tool; the schema file says so where an operator will see it.
        """
        return "SET QUOTED_IDENTIFIER ON;" + chr(10) if self.name == "mssql" else ""

    def as_user(self, user: str, statements: str) -> str | None:
        """Wraps statements so they run with only the named principal's privileges.

        Used to check that the role separation decision record 16 in docs/adr describes actually holds -- that the
        application's principal cannot write revoked_at, that the organisation's cannot touch the table
        at all. Testing that from a privileged connection would prove nothing, because a privileged
        connection can do everything.

        Returns None where the engine cannot do it in-session. MySQL is that case: it has roles, but
        activating one adds privileges to the connected account rather than replacing them, so there is
        no way to become *less* privileged without connecting as somebody else. A caller that wants that
        section covered on MySQL has to supply a second connection, as shared/test/role-checks.py does.
        """
        if self.name == "postgres":
            return f'SET ROLE "{user}";{chr(10)}{statements}{chr(10)}RESET ROLE;'

        if self.name == "mssql":
            return f"EXECUTE AS USER = '{user}';{chr(10)}{statements}{chr(10)}REVERT;"

        return None



def run_sql(sql_command: str, sql: str) -> tuple[int, str]:
    """Feeds one statement batch to the caller-supplied client. Returns (exit code, combined output).

    The caller supplies a command that already speaks to the right database -- a psql, mysql or sqlcmd
    invocation, containerised or not -- which keeps this dependency-free and keeps credentials out of it.
    """
    finished = subprocess.run(sql_command, shell=True, input=sql, text=True,
                              capture_output=True, timeout=60)
    return finished.returncode, (finished.stdout or "") + (finished.stderr or "")
