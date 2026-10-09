# 42. Single logout needs a signing key

Accepted on 2026-10-10.

## Context

A member signs in at the organisation's identity provider
([record 2](0002-members-sign-in-through-the-organisations-identity-and-access-management-system.md)) and has one
session cookie here ([record 21](0021-one-session-cookie-identifies-the-member-and-it-never-crosses-sites.md)). A member
who signs out has to be signed out here, whatever the provider supports. Providers differ in whether they can be told
that a session has ended, and in how.

[Record 24](0024-sign-out-is-local-and-works-whatever-the-provider-supports.md) said single logout needs .NET to have
its signing certificate. Every message single logout sends has to be signed, in either direction, so all three
implementations need a signing key for it.

## Decision

Signing out is a POST that carries the framework's anti-forgery token. It clears the server's own session whatever the
provider supports, then takes the member to the landing page at `/signed-out`, which confirms they have been signed out.
There is one fixed landing path. Over OpenID Connect, Java and PHP register `/signed-out` itself, and .NET registers its
framework's callback, which forwards there.

If the provider advertises an end-session endpoint over OpenID Connect, or a single logout endpoint over SAML, the
browser is sent there first, carrying the ID token or a logout request so that the provider knows which session to end,
and returns to the same confirmation. When the provider advertises neither, the member still gets a clean local sign-out
and never an error.

SAML single logout runs only when the server has its signing key, and Java also needs its own single logout address
configured. Without a key the server does not advertise single logout in its metadata. A logout request the provider
sends anyway is answered as if the address were absent, with HTTP 404 - Not Found and nothing beyond the status
([record 26](0026-error-responses-reveal-nothing-beyond-the-status-code.md)). The member stays signed in, and the server
logs a warning that says no signing key is configured.

With a key, the server accepts a logout request the provider signs, and ends the member's session here when they sign
out elsewhere. A logout request that names a different member from the one signed in is refused, and the session is
kept.

A sign-out that arrives with no live session lands on the same confirmation whatever its anti-forgery token says. One
that arrives with a live session and a missing or wrong token answers HTTP 403 - Forbidden and ends nothing. Whether the
provider then ends the member's sessions in the organisation's other systems is the provider's business, and nothing
here relies on it.

## Consequences

- A member who signs out from the self-service page is signed out here, whatever the provider supports.
- The landing path is fixed, because providers match the address they may return to exactly.
- A sign-out with no live session is never refused over its anti-forgery token, because there is nothing to end.
- The member may remain signed in at the provider when it could not be told, so on a shared machine they also sign out
  there.
- A deployment without a signing key never sends an unsigned logout request or logout response, and a provider that
  imports its metadata is not told to send it logout requests.
- A genuine logout request for one member, replayed from another member's browser, signs nobody out.
- Ending the member's session on a signed logout request the server cannot answer was rejected, because the server could
  not send the signed logout response the provider expects, so the two would disagree about whether the member is signed
  out.
- An organisation-configured address to land on after sign-out was rejected because the confirmation is the point of the
  page, and a redirect to an intranet or a home page would hide it.
- Relying on a single logout across the organisation's other systems was rejected because support is uneven across the
  providers organisations run, and a sign-out that only works on some of them is worse than one that is plainly local
  and tells the provider where it can.
