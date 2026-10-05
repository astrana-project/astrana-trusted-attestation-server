# 24. Sign-out is local and works whatever the provider supports

Accepted on 2026-10-05.

## Context

A member signs in at the organisation's identity provider
([record 2](0002-members-sign-in-through-the-organisations-identity-and-access-management-system.md)) and holds one
session cookie here ([record 21](0021-one-session-cookie-identifies-the-member-and-it-never-crosses-sites.md)).
Providers differ in whether they can be told that a session has ended, and in how.

## Decision

Signing out is a POST that carries the framework's anti-forgery token. It clears the server's own session whatever the
provider supports, then takes the member to the landing page at `/signed-out`, which confirms they have been signed out.
There is one fixed landing path, because providers match the address they may return to exactly. Over OpenID Connect,
Java and PHP register `/signed-out` itself, and .NET registers its framework's callback, which forwards there. If the
provider advertises an end-session endpoint over OpenID Connect, or a single logout endpoint over SAML, the browser is
sent there first, carrying the ID token or a logout request so that the provider knows which session to end, and returns
to the same confirmation. When the provider advertises neither, the member still gets a clean local sign-out and never
an error. Single logout also needs .NET to hold its signing certificate and Java to have its own single logout address
configured. Over SAML the server also accepts a logout request the provider signs, and ends the member's session here
when they sign out elsewhere. A sign-out that arrives with no live session lands on the same confirmation whatever its
anti-forgery token says, because there is nothing to end. One that arrives with a live session and a missing or wrong
token answers HTTP 403 - Forbidden and ends nothing. Whether the provider then ends the member's sessions in the
organisation's other systems is the provider's business, and nothing here relies on it.

## Consequences

- A member who signs out from the self-service page is signed out here, whatever the provider supports.
- The member may remain signed in at the provider when it could not be told, so on a shared machine they also sign out
  there.
- An organisation-configured address to land on after sign-out was rejected because the confirmation is the point of the
  page, and a redirect to an intranet or a home page would hide it.
- Relying on a single logout across the organisation's other systems was rejected because support is uneven across the
  providers organisations run, and a sign-out that only works on some of them is worse than one that is plainly local
  and tells the provider where it can.
