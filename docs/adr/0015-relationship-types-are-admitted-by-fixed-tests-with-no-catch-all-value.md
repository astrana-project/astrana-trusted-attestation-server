# 15. Relationship types are admitted by fixed tests, with no catch-all value

Accepted on 2026-10-05.

## Context

The relationship types are a fixed vocabulary
([record 14](0014-relationship-types-are-a-fixed-vocabulary-in-one-shared-file.md)) whose values mean the same thing
whichever organisation attests to them. A fixed list invites requests to add to it, and without a rule for what belongs,
the list drifts back towards every organisation's own labels.

## Decision

A value is added only if it passes six tests.

1. It carries legitimate standing, or evidence that a real person exists, when an authoritative issuer attests to it.
2. It is a distinct kind of relationship, not a level or designation within one.
3. At least some real institutions keep a checkable record of it.
4. It is a fact people normally disclose in civic or professional life, not one treated as private whoever could attest
   to it.
5. It neither overlaps an existing value in meaning nor needs distinguishing from a near neighbour by explanation.
6. It is named atomically, never as an existing value plus a modifier.

Nothing that fails them is given a vague home. There is no `other` value. A level or designation within a relationship
goes in the subtype, which the organisation sets as free text. The credibility of the issuer, how it is funded or
governed, is not part of the type.

## Consequences

- `patient` is excluded though a hospital is authoritative, because patient status is kept private by norm. A
  telecommunications subscriber is a `client`, the same as a bank's account holder, and whether that client attestation
  is worth anything is the verifying instance's judgement of the organisation and its jurisdiction. `board_member` would
  be `director`. `resident` already spans civic residency and living at a property and can never be reused for medical
  residency.
- An organisation whose relationship fits nothing has to pick the nearest value. That is a real cost, and the
  alternative, a catch-all with a free-text description, reopens the ungoverned vocabulary the list exists to close.
- A request to add a value is judged against the tests rather than against whether some organisation has such a
  relationship.
- Folding issuer credibility into the type was rejected because the verifying Astrana instance already knows which
  organisation it asked, and because it would have the server form an opinion about institutions, which it never does
  ([record 4](0004-attestation-is-anonymous-and-answers-for-one-relationship.md)).
