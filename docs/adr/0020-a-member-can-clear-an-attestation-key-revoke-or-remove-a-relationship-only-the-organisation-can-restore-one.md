# 20. A member can clear an attestation key, revoke or remove a relationship, only the organisation can restore one

Accepted on 2026-10-05.

## Context

The organisation owns standing
([record 1](0001-the-organisation-grants-revokes-and-extends-relationships-from-its-own-systems.md)) and the member owns
the key ([record 3](0003-one-attestation-keypair-per-relationship.md)). What the member may do to their own record,
without involving the organisation, had to be settled.

## Decision

On the self-service page a member can clear a relationship's key, by saving an empty value, which keeps the grant and
returns the relationship to waiting for a key unless it is revoked or expired. They can revoke a relationship from any
status except revoked, a one-way move through the member's own procedure. And they can remove a relationship, which
deletes its row. Registering a key never lifts a revocation. Only the organisation can restore a revoked relationship,
through extend, or grant a removed one again.

## Consequences

- A member can pause attestation by clearing a key and resume it by registering one, without asking the organisation,
  and can cut a relationship off at once if a key is compromised. Neither needs the organisation at the time, and the
  organisation's decision is never overridden, since nothing a member does can re-establish a relationship.
- Removing is the member's right-to-erasure action. The audit log keeps the subject identifier for the retention period
  ([record 17](0017-the-audit-log-is-append-only-apart-from-age-based-retention.md)). An organisation that grants from a
  scheduled sync grants a removed relationship again on the next run, unless its sync knows about the removal.
- Revoke and remove sit behind a disclosure on the self-service page, so that they are reachable but not used
  carelessly. Saving, revoking and removing all need the browser to run script.
- Three ways to stop can confuse. Clearing is undone by the member saving a key, revoking only by the organisation and
  removing only by a new grant. The wording on the page explains the difference.
- Only an empty or blank key clears it. A missing, null or non-string key is refused with HTTP 400 - Bad Request, so
  that a garbled request can never wipe a key.
- A separate endpoint for clearing was rejected in favour of an empty value on the existing one. A soft delete for
  remove was rejected because it would keep what the member asked to erase.
