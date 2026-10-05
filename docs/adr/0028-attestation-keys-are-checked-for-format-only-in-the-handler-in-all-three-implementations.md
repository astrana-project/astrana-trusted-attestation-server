# 28. Attestation keys are checked for format only, in the handler, in all three implementations

Accepted on 2026-10-05.

## Context

The server never signs and never verifies a signature, so a key is data to it. What it checks, and where, decides
whether the same key is accepted everywhere.

## Decision

A submitted key is accepted only if it is canonical standard base64 that decodes to exactly 32 bytes and re-encodes to
the same string, and is not the all-zero key. Nothing else is checked, not even that the key is a valid curve point, and
no cryptography library is used. The check runs in the request handler of each implementation, not in the framework's
request binding. The key endpoint refuses a request body over 64 kilobytes with HTTP 413 - Content Too Large and no
body, as attestation does ([record 4](0004-attestation-is-anonymous-and-answers-for-one-relationship.md)), and on both
the refusal comes before the key is looked at. Under that limit every request reaches the handler, which answers a
malformed key on the key endpoint with HTTP 400 - Bad Request, as it does a missing, null or non-string key
([record 20](0020-a-member-can-clear-an-attestation-key-revoke-or-remove-a-relationship-only-the-organisation-can-restore-one.md)).
A key that is empty or holds only spaces, tabs, carriage returns and line feeds is blank and clears the relationship's
key. Any other whitespace character makes the key malformed.

## Consequences

- The same key string is accepted or refused identically wherever it is presented. A divergence here would let one
  implementation store a key another rejects.
- Left to themselves, the frameworks' binding layers refuse different inputs with different statuses, so .NET and Java
  read the request body by hand rather than binding it, and PHP turns off Laravel's trimming of strings and its
  conversion of empty strings to null on the API, so that the handler decides the result.
- A valid body is under a hundred bytes, so the 64-kilobyte limit caps what an anonymous caller can make the server
  read, with ample room for every real request.
- Over-length keys are refused before they reach the schema, which matters because PostgreSQL's key column is unbounded
  where the other two cap it at 32 bytes.
- The self-service page trims surrounding whitespace of every kind before it sends a key, so only direct callers of the
  API meet the stricter rule, and a member who pastes a key with a stray non-breaking space at either end still
  registers it.
- Leaving validation to each framework was rejected because it produces three behaviours.
