# 13. Timestamps are UTC in storage and on the wire

Accepted on 2026-10-05.

## Context

A relationship's expiry and revocation are instants that decide the status an attestation reports, and they are written
by the organisation's procedures, read by three implementations
([record 5](0005-three-implementations-held-to-behavioural-parity.md)) and stored on three engines
([record 11](0011-support-postgresql-mysql-and-sql-server-one-schema-each.md)). On two of those engines the column types
used here store no time zone. A stack that converts through the host's local zone reads back a shifted instant on any
host not set to Coordinated Universal Time (UTC), while another stack reads the same row literally as UTC. PostgreSQL's
`TIMESTAMPTZ` is not affected by the host's zone, but a value written there without an offset is read in the database
session's zone. Hosts that run in UTC hide both problems.

## Decision

Every timestamp is a UTC instant. On SQL Server's `DATETIME2` and MySQL's `DATETIME`, which carry no zone, the stored
value is UTC wall-clock time, and every implementation reads and writes those columns as UTC whatever the host's own
zone is. Every implementation writes PostgreSQL's `TIMESTAMPTZ` values so that neither the host's zone nor the database
session's zone can shift them. On the wire every timestamp is ISO 8601 with an explicit trailing `Z`, never a zone-less
string a consumer would have to guess the zone of.

## Consequences

- The same row means the same instant on every engine and every stack, and a consumer never guesses a zone.
- Anyone reading the tables directly, or passing an expiry to `extend_member_relationship` on MySQL or SQL Server, has
  to treat the values as UTC, and the MySQL and SQL Server schema files say so in a comment on the columns.
- A zone-aware type on every engine, or a zone offset stored beside each value, was rejected because MySQL has no
  zone-aware type and nothing in the design needs the zone, only the instant.
