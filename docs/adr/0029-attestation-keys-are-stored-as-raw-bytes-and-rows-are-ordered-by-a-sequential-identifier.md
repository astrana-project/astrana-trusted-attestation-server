# 29. Attestation keys are stored as raw bytes and rows are ordered by a sequential identifier

Accepted on 2026-10-05.

## Context

Every attestation is a lookup by public key
([record 4](0004-attestation-is-anonymous-and-answers-for-one-relationship.md)) in a table that a large organisation
fills with millions of rows, and the key is 32 bytes of what looks like random data
([record 27](0027-ed25519-for-attestation-keys.md)). How the key is stored sets the size of the one index every lookup
uses, and how the table is ordered sets where new rows land.

## Decision

The public key is stored as its 32 raw bytes in a binary column with a unique index, never as base64 or hexadecimal
text. The table's primary key, and its physical order where the engine has one, is a sequential integer identifier, not
the public key.

## Consequences

- Binary storage takes 32 bytes, against 44 for base64 text and 64 for hexadecimal. That sets the size of the one index
  every attestation and every periodic re-check hits, and how much of it stays in memory.
- On MySQL and SQL Server, which store the table in primary key order, a sequential identifier keeps new rows arriving
  at the end. A random identifier would land each new row at a random position as the table grew.
- The table is expected to carry millions of rows on ordinary hardware with correct indexing alone, with no sharding or
  partitioning.
- The application converts between the base64 of the contract and the bytes of the column at its boundary, and anyone
  reading the table directly sees bytes rather than the string a member pasted. PHP passes the key to the database as
  hexadecimal and has the engine convert it to bytes.
- Text storage was rejected for the index cost. Using the key as the primary key was rejected because it is null until
  the member registers one and changes when they replace it. A random identifier was rejected because it scatters new
  rows.
