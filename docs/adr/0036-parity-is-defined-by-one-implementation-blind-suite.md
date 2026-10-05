# 36. Parity is defined by one implementation-blind suite

Accepted on 2026-10-05.

## Context

Parity ([record 5](0005-three-implementations-held-to-behavioural-parity.md)) cannot be reviewed by eye across three
codebases in three languages. It has to be defined by something outside all of them.

## Decision

One suite in `shared/test`, in three parts, defines what parity means. Conformance checks one running instance over HTTP
against what the contract and the decision records require. Differential sends the same requests to all three and
compares the answers. For the API it compares status, content type and body. For the pages it compares the resolved
language and direction. It also compares where each redirect sends the browser and the cookie the language switcher
sets. Separately, it sends forwarded-scheme headers straight to each application, past the proxy, and holds each answer
to the one the decision records require. Accessibility drives the landing page and the self-service page in a real
browser and checks them against the rules of the Web Content Accessibility Guidelines (WCAG) 2.1 AA that a machine can
detect, with keyboard and reflow checks. The suite is written in Python, which is none of the three stacks. It uses only
the standard library, apart from Playwright to drive a browser and axe-core for the accessibility rules. It runs against
real identity providers rather than mocks, and it cannot tell which implementation it is talking to. Its results are
published to the repository's GitHub Pages site on every push to `master`.

## Consequences

- There is one suite because three per-stack suites could all be green while testing three different behaviours. It is
  written in a neutral language so that no implementation defines what correct looks like, and it shares code with none
  of them.
- Real providers expose behaviour that mocks hide. The identity-provider matrix, several providers over OpenID Connect
  and SAML, is the evidence behind the statement in
  [record 2](0002-members-sign-in-through-the-organisations-identity-and-access-management-system.md) that any provider
  that supports the standards works, and a provider is admitted to it only if it can present a subject identifier the
  test fixtures can set to a known value.
- The differential part exists because conformance asks whether one implementation obeys the contract, not whether the
  three obey it identically. Holding the forwarded-scheme answers to the required one means three implementations that
  agree on a wrong answer still fail. The accessibility part gathers checks that would otherwise be done piecemeal into
  one tool.
- The suite is not purely black-box. It seeds grants through the database before anyone signs in, and the database-level
  checks of roles and schema run alongside it locally.
- In the provider matrix each implementation has its own database, SQL Server for .NET, PostgreSQL for Java and MySQL
  for PHP, and the identity provider is shared read-only, so that no test can disturb another's data.
- Unit tests stay per stack, for each implementation's internals, with their own coverage bar measured apart from the
  suite.
- A suite per stack in its native test framework was rejected because three results cannot be compared. A single suite
  written in one of the three stacks was rejected because it would not be neutral.
