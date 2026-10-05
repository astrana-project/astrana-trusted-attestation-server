# 16. Granting, revoking and extending are enforced in the database, not in the application

Accepted on 2026-10-05.

## Context

The organisation grants from its own systems
([record 1](0001-the-organisation-grants-revokes-and-extends-relationships-from-its-own-systems.md)). That rule has to
be made true somewhere, and if it were only a check in application code it would be a check that a bug, a compromise or
a later convenience could relax.

## Decision

Granting, revoking and extending are stored procedures, and the documented grants keep the application's database role
from calling them. That role may read and delete relationship rows, update a relationship's key, insert into the audit
log and execute two procedures, the member's own revoke and the retention prune. The four columns that define standing,
the type, the subtype, the expiry and the revocation, are written only through procedures. The member's own revoke can
only set the revocation once. In every implementation the application updates a key with a statement that writes only
the `public_key` column, so the role is granted update on that one column alone. The organisation's tooling calls its
procedures under a separate role that holds execute on them and nothing else. Every procedure runs with its definer's
rights on PostgreSQL and MySQL, and through ownership chaining on SQL Server, where the procedures and the tables share
an owner. On PostgreSQL every procedure also pins its search path to the system catalogue, the schema the tables live in
and the temporary schema last. Every procedure that changes standing writes its own audit entry in the same transaction
and is called bare, never wrapped in an outer transaction. Registering a key never creates a relationship, and
registering one for a relationship that was never granted answers HTTP 404 - Not Found.

## Consequences

- The application has no permission to grant, extend or restore, so a bug or compromise in it cannot do those things
  through the application. That reduces the risk and the attack surface but does not remove them. The application can
  still revoke a relationship, which only ever lowers standing, and delete a row, which the database allows for any row
  and the application limits to the member's own. A compromise that reaches the credentials of the organisation's role,
  or the database itself, is outside the boundary.
- A full-row update from an object mapper can never reach the columns the role is denied, because the role may update
  the key column alone.
- The pinned search path means a caller cannot create a temporary table under a real table's name and have a
  definer-rights procedure write to it instead.
- The application can sit in a lower-trust, public-facing zone than the database, hosted separately, with only its
  limited role crossing between them.
- The rule lives in the shared schema, so it is the same in all three implementations whatever their handlers do. A
  role-check script beside the suite, which creates roles of its own, shows that the documented grants produce the
  boundary on each engine. A deployment's own roles are checked only by inspecting their permissions.
- The boundary exists only in a deployment that applies the documented grants
  ([record 12](0012-the-schema-is-applied-from-the-shared-sql-files-there-are-no-migrations.md)). A grant wider than the
  documented one, PostgreSQL's default execute grant to every role left in place or an application that still connects
  as the schema's owner weakens the boundary with no error. A missing grant or a missing SECURITY DEFINER on PostgreSQL
  shows as a refused statement instead, and the usual repair, a wider grant, is what removes the boundary.
- No procedure changes a subtype after the grant or deletes a relationship, so correcting a subtype or removing a
  relationship from the organisation's side needs the schema's owner.
- Granting through the API behind application checks was rejected because a check can be bypassed.
