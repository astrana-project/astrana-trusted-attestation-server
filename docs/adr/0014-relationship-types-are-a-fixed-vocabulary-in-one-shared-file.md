# 14. Relationship types are a fixed vocabulary in one shared file

Accepted on 2026-10-05.

## Context

A verifying Astrana instance shows the relationship type an organisation attests to, and "employee" has to mean the same
thing whichever organisation said it. Together with the member's subject identifier, the type is also what makes a
relationship row unique.

## Decision

The relationship types are a fixed vocabulary of English identifiers in `shared/contract/relationship-types.json`, with
a display label per locale kept alongside them. Every implementation loads the file and never retypes its values. The
contract's OpenAPI document, the manifest schema and the grant procedure in each schema file repeat the list, and a
script beside the suite, run by hand, checks that all of them agree. A relationship is one row per member and type, so a
member holds at most one of each type at an organisation, and the organisation may set an optional subtype on it. A
grant of a type outside the vocabulary is refused by the procedure and writes nothing, and a manifest that declares a
type outside the vocabulary is refused.

## Consequences

- The values are stable across organisations and across versions, so an Astrana instance can rely on them, and the
  labels are translated once rather than by each instance.
- Adding a type is a change to the shared file, the OpenAPI document, the manifest schema and the schema files together,
  and a feature release of all three implementations.
- A grant is checked against the vocabulary, not against the types the manifest declares, so a type in the vocabulary
  that the manifest leaves out can still be granted, keyed and attested.
- A free-text or per-organisation vocabulary was rejected because the same relationship would read differently depending
  on who issued it. Constraining the type in each implementation's router was rejected because an unknown type answers
  HTTP 404 - Not Found anyway, since nobody can be granted it.
