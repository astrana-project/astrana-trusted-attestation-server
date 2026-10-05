# 37. Only behaviour the contract and the shared interface define must match

Accepted on 2026-10-05.

## Context

Parity ([record 5](0005-three-implementations-held-to-behavioural-parity.md)) is checked by sending the same requests to
all three implementations and comparing the answers
([record 36](0036-parity-is-defined-by-one-implementation-blind-suite.md)). A test that sends malformed and unusual
requests produces ones no conforming consumer sends, and three routers in three frameworks answer those differently by
default. Where the parity line is drawn decides how much of each framework has to be overridden.

## Decision

Every request the contract and the shared interface define, the API, the manifest, the pages and the language switcher,
must get the same answer, in the same shape, on every stack and every database engine. Requests outside them include an
unsupported method on an endpoint, a trailing slash, a path in the wrong case, a doubled slash and a syntactically
broken `Accept-Language` quality value. Each stack's router and framework answer these however they do by default, and
the three are not required to agree. The differential part of the suite lists the differences that exist.

## Consequences

- The differences lie at the framework edges, on inputs no conforming consumer sends, and are listed rather than hidden.
- Forcing three routers to agree on undefined input buys nothing a consumer can observe and would mean fighting each
  framework's routing, which is the kind of code that breaks on a framework upgrade.
- The list has to be maintained. A new difference on an undefined input is added to it, and a difference on a defined
  input is a defect.
- Matching on every input was rejected as a general rule. It is applied only where it matters, as for the error bodies
  ([record 26](0026-error-responses-reveal-nothing-beyond-the-status-code.md)) and the refusal of a forwarded scheme
  that is not exactly https
  ([record 31](0031-refuse-to-run-without-tls-the-identity-and-access-management-system-or-a-valid-manifest.md)).
