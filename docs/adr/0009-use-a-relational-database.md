# 9. Use a relational database

Accepted on 2026-10-05.

## Context

The server keeps its relationships and its audit log in small tables whose shape the shared schema files fix in advance.
The organisation's own systems must be able to reach them to grant and revoke
([record 1](0001-the-organisation-grants-revokes-and-extends-relationships-from-its-own-systems.md)), and the
access-control boundary has to be enforced by the database itself.

## Decision

The relationships and the audit log are kept in a relational database of the organisation's choosing.

## Consequences

- Relational databases are everywhere and mature, with several reliable open source options. Every organisation in the
  target environments already runs one, with staff already handling backup, failover, encryption, monitoring and audit.
  Its own tooling, such as human resources syncs, scheduled jobs and reporting, already reaches one through standard
  drivers in every language.
- The engine enforces constraints, roles with column-level grants, procedures with definer's or owner's rights and
  transactions, which are what key uniqueness, the access-control boundary and the audit log are built from. Typed
  columns hold what they are declared to hold.
- The attestation query is a single indexed lookup.
- A relational database ties the organisation to no cloud provider or vendor, so its choice stays its own, and a SQL
  dump outlives the software.
- Engine-level auditing, where the organisation turns it on, can record who called which procedure and when,
  independently of the server's own audit table.
- The schema is small and known upfront, so a database built for data whose shape changes offers nothing that is needed,
  and short SQL files, one per engine, say exactly what is stored and who may write which column, which a reviewer can
  read before approving a deployment.
- A document store was rejected because the role and procedure model does not exist there in the form the boundary
  needs. An embedded file database was rejected because the organisation's systems must reach the same data and it has
  no roles. Holding standing in the application behind an administrator interface was rejected for the reasons in
  [record 1](0001-the-organisation-grants-revokes-and-extends-relationships-from-its-own-systems.md).
