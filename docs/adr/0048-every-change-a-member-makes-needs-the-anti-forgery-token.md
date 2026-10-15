# 48. Every change a member makes needs the anti-forgery token

Accepted on 2026-10-13.

## Context

Once a member has signed in
([record 2](0002-members-sign-in-through-the-organisations-identity-and-access-management-system.md)), something has to
identify them from one request to the next, and whatever identifies them is what an attacker would try to use. A change
to a member's relationships or session should happen only when the member asked for it on their own page.

[Record 21](0021-one-session-cookie-identifies-the-member-and-it-never-crosses-sites.md) made one session cookie the
member's only credential. It asked an anti-forgery token of the member's own revoke and sign-out only. Setting or
clearing a key and removing a relationship needed none.

A page on another site cannot use the session for those changes, because the cookie is SameSite=Lax and the browser does
not send it with a cross-site PUT or DELETE. A page on another subdomain of the same registrable domain is same-site, so
the browser does send the cookie with its requests. What then stops it is the preflight request a browser makes before a
cross-origin PUT or DELETE. None of the three implementations allows that preflight for a request that carries the
session cookie, but a proxy or gateway an organisation puts in front of the server can answer the preflight in its
place, and the server has no control over that answer. The cookie shows who the member is. It does not show that the
member asked for the change.

## Decision

One browser-session cookie, `ata_session`, is the only credential. It identifies the member and nothing else, and is
HttpOnly, Secure and SameSite=Lax. It has no expiry of its own, so it ends when the browser closes, and the session
behind it ends after eight hours idle. No access or refresh token is kept beyond what signing out at the provider needs.
Apart from the sign-in exchange, anonymous endpoints set no cookie except `ata_locale`, which the page's language
switcher sets to the chosen locale. That cookie identifies no one, is HttpOnly, Secure and SameSite=Lax, lasts a year
and is set only by a POST of a value from the offered list.

Every change a signed-in member makes needs the framework's anti-forgery token, a value only the member's own
self-service page has. The changes are setting or clearing a key and removing a relationship, which are PUT and DELETE
on the relationship's key, revoking a relationship, and signing out. Reads and the anonymous attestation lookup need no
token.

On the API the token travels in the `X-CSRF-TOKEN` header and is read from nowhere else. The sign-out form sends it in
the framework's own form field. With a live session, a request with a missing or wrong token is refused before any key
in it is read, with HTTP 403 - Forbidden, and nothing changes. Without a session, the API answers HTTP 401 -
Unauthorized whatever the token says, and a sign-out lands on the confirmation page
([record 41](0041-single-logout-needs-a-signing-key.md)).

The SAML authentication request identifier is kept in its own short-lived SameSite=None cookie in all three, never in
the session, and Java keeps its SAML logout request the same way. In .NET the OpenID Connect correlation value, which
ties the provider's reply to the sign-in that started it, is kept in a cookie of its own too, and in Java and PHP it is
kept in the session.

## Consequences

- Nothing authenticated outlasts the browser session or eight hours idle on a shared machine, and re-authentication is a
  redirect to the provider.
- No page on another site or on a sibling subdomain can make a change with the member's session, whatever a browser, a
  proxy or a gateway does with a preflight request.
- Calling the member endpoints with `curl`
  ([record 25](0025-rest-over-https-with-the-version-in-the-path-described-by-openapi.md)) needs the session cookie
  taken from a signed-in browser and, for every change, the anti-forgery token taken from the signed-in self-service
  page.
- Each framework would also accept the token from a form field of its own name. Reading it from the header alone means a
  forged request is answered before any key in it is read, and the three implementations agree.
- The SAML response arrives as a cross-site POST, and a Lax session cookie is not sent with it, so the request
  correlation is kept in its own cookie. Inside the session it would fail on every legitimate sign-in. The .NET OpenID
  Connect callback is also a POST, so its correlation needs its own cookie for the same reason. In Java and PHP that
  callback is a top-level redirect, which the browser sends a Lax cookie with.
- The language switcher's POST sends no anti-forgery token because the public landing page offers the switcher too and
  has no session, so there is no token to send. A cross-site form can still set the value, and the most it can do is
  change the display language to one the organisation offers.
- Keeping the key writes and removals free of the token, as record 21 did, was rejected because their safety would rest
  on how every proxy and gateway in front of the server answers a preflight request.
- Loosening the whole session to SameSite=None was rejected, because a separate short-lived cookie exposes only the
  request correlation to cross-site requests, never the session.
