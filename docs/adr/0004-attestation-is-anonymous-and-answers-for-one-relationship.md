# 4. Attestation is anonymous and answers for one relationship

Accepted on 2026-10-05.

## Context

When a member proves a relationship to another Astrana instance, that instance needs to ask the organisation whether the
key is on record and, when it is, the type and status of its one relationship. How it asks, and what the organisation
learns from being asked, is the privacy model of the whole design.

## Decision

The attestation API endpoint takes an attestation key and nothing else, and needs no credentials. It answers whether
that key is on record. When it is, it also gives the type of the one relationship the key belongs to, its subtype where
the organisation set one and its status (active, revoked or expired). It gives nothing more. For any request body under
its 64-kilobyte limit that is not an on-record key, malformed input included, it answers HTTP 200 - OK and says only
that the key is not valid. Apart from HTTP 500 - Internal Server Error and the answer to a method the endpoint does not
take, the only other answers are HTTP 413 - Content Too Large for a body over that limit, and HTTP 421 - Misdirected
Request for a request that did not reach the server over TLS. The key travels in the request body, never in the address.
The application never writes a key or a lookup to its own log, and the key never appears in a request line that a web
server's access log records. The server never ranks, scores or weighs anything. How much to trust an answer is the
verifying Astrana instance's judgement.

## Consequences

- The organisation learns nothing about who asked beyond the network address the request came from and whatever the
  verifying instance's own client sends, and a verifying instance needs no account with any organisation.
- The HTTP status is the same whatever key is sent, and an unknown key gets the same response as a malformed one, so
  nobody can list the keys on record or test whether a particular key exists.
- Keeping the key out of the URL keeps it out of access logs, proxy logs and browser history.
- A live lookup makes revocation immediate. It also means attestation depends on the organisation being reachable, and a
  verifying instance treats an unreachable organisation as unknown rather than invalid.
- Having the organisation issue a signed token that the verifier would check offline, as in the World Wide Web
  Consortium's Verifiable Credentials model, was rejected because an issued credential cannot be withdrawn the moment a
  relationship ends, and because it makes the organisation a signer where a lookup makes it a record keeper.
