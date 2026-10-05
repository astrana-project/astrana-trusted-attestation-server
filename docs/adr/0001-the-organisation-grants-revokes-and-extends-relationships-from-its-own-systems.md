# 1. The organisation grants, revokes and extends relationships from its own systems

Accepted on 2026-10-05.

## Context

An attestation is the organisation vouching for a relationship. That only holds if the organisation, and nobody else,
decides who is granted which relationship. Where and how that decision is made decides what the server has to do.

## Decision

The organisation grants, revokes and extends relationships by calling procedures in the server's database from its own
systems, such as a script, a scheduled job or a sync from the human resources or membership system it already runs. A
relationship exists, without a key, before the member has ever signed in. The server has no page and no endpoint through
which the organisation does any of it, and none through which a member can grant, extend or restore a relationship.

## Consequences

- Organisations already have approval workflows, bulk import and notification in the systems that know who their people
  are, and the procedures are called from those. The server does not need its own.
- With no administrator interface there is no administrative login to attack and no administrative tool for an operator
  to learn, and the public-facing application stays small.
- Granting needs an identifier the organisation knows before the member's first sign-in, which constrains how members
  are identified.
- An administrator page in the server, and a request-and-approve flow where a member asks and someone at the
  organisation confirms, were both rejected because each adds an administrative identity and authorisation model the
  server does not have. Reading the relationship type from an identity-provider claim was rejected, because whoever
  controls the provider's claim mapping could then hand out relationships nobody granted.
