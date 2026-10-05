# 10. The server stores no personal data beyond the subject identifier

Accepted on 2026-10-05.

## Context

The server sits next to the organisation's identity and access management system
([record 2](0002-members-sign-in-through-the-organisations-identity-and-access-management-system.md)), which already
holds every member's name and contact details. What the server keeps about a member decides what a breach of its
database exposes and what has to be kept in step with the directory.

## Decision

The relationships table holds the subject identifier from the identity system, the relationship type and subtype, the
expiry, the revocation, the public key and a sequential row identifier, and nothing else. The audit log holds the
subject identifier, the relationship type, the event, the actor, the time and a sequential row identifier. The server
itself writes no name, email address or other directory attribute to the database or the logs. The actor is whatever the
organisation's tooling passes, and whether it names the person who made a change is the organisation's choice. The
session keeps only the details from the sign-in that record 2 lists. Under OpenID Connect those include the identity
token as issued, with whatever claims the provider put in it. The display name shown on the self-service page lives only
as long as the session.

## Consequences

- There is no second directory to breach or to keep in step. A member's name changes in the identity system and nothing
  here notices.
- In PHP the session, and with it the display name, is a file on the server's disk rather than a row in the database.
- A breach of the database exposes identifiers that only the organisation can resolve to people, together with each
  member's standing and the history of changes to it. Where an organisation configures an email address as the subject
  claim, the identifier also names the person directly, and that is the organisation's choice to make.
- The audit log can say which identifier was affected and never who the person was, which is what the organisation's own
  records are for. In a small organisation the timing of entries could still be matched to a known event involving a
  particular person. That residual risk is accepted, since nothing short of not keeping the log removes it.
- Storing the name so that staff could read the table was rejected, because the server has no staff pages and the
  organisation's own systems already know the name. Storing the email address for notifications was rejected because the
  server sends none.
