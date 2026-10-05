# 17. The audit log is append-only, apart from age-based retention

Accepted on 2026-10-05.

## Context

Changes to standing have to be accounted for, and an audit record that could be edited would be worth nothing.

## Decision

Every change to a relationship writes an entry to `audit_log` in the same transaction as the change. The procedures
write their own
([record 16](0016-granting-revoking-and-extending-are-enforced-in-the-database-not-in-the-application.md)), and the
application writes its own for registering a key, clearing a key and removing a relationship. The organisation's revoke
and extend refuse a call that matches no relationship, with an error and no entry. Under the documented grants neither
database role may update or delete audit rows. The one exception is `prune_audit_log`, which deletes entries older than
a retention period the organisation sets, five years by default. Neither a key nor an attestation lookup is ever written
to the log ([record 4](0004-attestation-is-anonymous-and-answers-for-one-relationship.md)). So that an entry, a lookup
or a procedure never matches the wrong row, the subject identifier and the relationship type are compared exactly on
every engine, in this table and in the relationship table, and the grant procedure refuses a subject identifier with
leading or trailing spaces, with an error and no entry.

## Consequences

- There is no path where standing changes and the record of it does not, and no path to rewrite the record.
- Pruning means an entry older than the retention period cannot be recovered from the server.
- Each change records its own event type, so a member's own revoke (`relationship_self_revoked`) and an organisation's
  revoke (`relationship_revoked_by_org`) are told apart, and the actor is empty for self-service events.
- The subject identifier remains in the log after a member removes their relationship, for as long as retention keeps
  it. Privacy law, the General Data Protection Regulation included, distinguishes erasing data that is being processed
  from keeping the record that an event happened, and the organisations this is built for are often required to keep the
  latter.
- Timestamps carry microseconds on every engine, but entries the application writes take the application host's clock
  and entries the procedures write take the database's clock, so the sequential row identifier is the reliable order.
- A mistyped identifier in a revoke or extend surfaces as an error, never as a silent entry.
- The table carries an index on its timestamp, `occurred_at`, so the prune finds the entries it removes through the
  index and touches only those, rather than scanning and locking the whole table while the procedures and the
  application wait to write.
- Exact comparison means no row matches another that differs only in case or trailing space. PostgreSQL compares text
  that way by default. The MySQL and SQL Server schema files pin a binary collation on those columns, which settles
  case, and because both engines still ignore trailing spaces, the procedures and the implementations compare the values
  again after the lookup. Refusing surrounding spaces at grant means no stored identifier can differ from another only
  in that space.
- A mutable audit table was rejected as not an audit trail.
