# 48. All three give the same answer to unusual JSON

Accepted on 2026-10-13.

## Context

A member or a verifying peer gets the same answer whichever implementation serves a request, and where the three differ
on a security posture the strictest becomes the shared behaviour
([record 5](0005-three-implementations-held-to-behavioural-parity.md)).

The key endpoint and attestation read their request body as JSON by hand in all three implementations
([record 28](0028-attestation-keys-are-checked-for-format-only-in-the-handler-in-all-three-implementations.md)). JSON
(RFC 8259) leaves some of its reading to the parser: how deep arrays and objects may nest, how long a number may be,
whether a `\u` escape of half a surrogate pair is text, and which value counts when a name appears twice in an object.
The three parsers settled the first three differently. A key on record beside a deeply nested array, a long number or a
lone surrogate escape was answered with its relationship by one implementation and with `{"valid":false}` by another, so
a verifying peer could tell them apart.

## Decision

Where the parsers differ, all three implementations give the answer the strictest one gives. A request body is malformed
when, anywhere in it:

- arrays and objects are nested more than 64 deep, counting the outermost,
- a number has more than 1,000 digits, counting those of its fraction and exponent, or
- a string or a name contains a `\u` escape of a surrogate that is not half of a pair.

A malformed body carries no key, so attestation answers it with `{"valid":false}` and the key endpoint with HTTP 400 -
Bad Request. When a name appears more than once in an object, its last value counts, in all three.

## Consequences

- Each limit is the strictest one a parser applied by default, the .NET reader's 64 levels, Jackson's 1,000 digits on
  Java and PHP's refusal of a lone surrogate escape. An anonymous caller learns nothing from a refusal
  ([record 4](0004-attestation-is-anonymous-and-answers-for-one-relationship.md)).
- A real body is one object with a 44-character key, so no request a member or a peer makes comes near any limit.
- Every name and value is checked, including those the server never uses and an earlier value under a name used twice,
  because a parser that checks as it reads meets all of them.
- Each implementation enforces the limits its parser does not, so each carries a little code of its own. Java and PHP
  set their parser's depth limit to 64, because their defaults are deeper. .NET decodes every string and counts the
  digits of every number. Java checks every string for a lone surrogate, and lifts Jackson's limit on the length of a
  name, which neither of the other two parsers has. PHP counts the digits of every number.
- Accepting what the most lenient parser accepted was rejected, because it would mean lifting limits two parsers apply
  by default, and an implementation that leaves its parser's default in place would then diverge again unnoticed.
