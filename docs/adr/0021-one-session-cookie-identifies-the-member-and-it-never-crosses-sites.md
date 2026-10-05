# 21. One session cookie identifies the member, and it never crosses sites

Accepted on 2026-10-05.

## Context

Once a member has signed in
([record 2](0002-members-sign-in-through-the-organisations-identity-and-access-management-system.md)), something has to
carry who they are from one request to the next, and whatever carries it is what an attacker would try to use.

## Decision

One browser-session cookie, `ata_session`, is the only credential. It carries identity only and is HttpOnly, Secure and
SameSite=Lax. It has no expiry of its own, so it ends when the browser closes, and the session behind it ends after
eight hours idle. No access or refresh token is kept beyond what signing out at the provider needs. Apart from the
sign-in exchange, anonymous endpoints set no cookie except `ata_locale`, which the page's language switcher sets to the
chosen locale. That cookie carries no identity, is HttpOnly, Secure and SameSite=Lax, lasts a year and is set only by a
POST of a value from the offered list. The member API relies on SameSite for protection against cross-site requests, and
its key writes and removals carry no anti-forgery token. The member's own revoke and sign-out do carry one, each
framework's own, and revoke sends it in an `X-CSRF-TOKEN` header. On a request with a live session, a missing or wrong
token answers HTTP 403 - Forbidden. The SAML authentication request identifier lives in its own short-lived
SameSite=None cookie in all three, never in the session, and Java keeps its SAML logout request the same way. In .NET
the OpenID Connect correlation value, which ties the provider's reply to the sign-in that started it, lives in a cookie
of its own too, and in Java and PHP it is kept in the session.

## Consequences

- Nothing authenticated outlasts the browser session or eight hours idle on a shared machine, and re-authentication is a
  redirect to the provider.
- Key writes and removals need no token, because a browser sends a cross-origin PUT or DELETE only after first asking
  permission in a preflight request, and no implementation gives that permission to a request that carries credentials.
- A cross-site POST cannot ride the session. A page on another subdomain of the same registrable domain is same-site and
  could send a plain form POST with the session attached, which is why the member's own revoke carries a token.
- The SAML response arrives as a cross-site POST, and a Lax session cookie is not sent with it, so the request
  correlation rides in its own cookie. Inside the session it would fail on every legitimate sign-in. The .NET OpenID
  Connect callback is also a POST, so its correlation needs its own cookie for the same reason. In Java and PHP that
  callback is a top-level redirect, which the browser sends a Lax cookie with.
- The language switcher's POST carries no anti-forgery token because the public landing page offers the switcher too and
  has no session to hold one. A cross-site form can still set the value, and the most it can do is change the display
  language to one the organisation offers.
- Loosening the whole session to SameSite=None was rejected, because a separate short-lived cookie exposes only the
  request correlation to cross-site requests, never the session.
