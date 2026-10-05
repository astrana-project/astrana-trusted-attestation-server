# 2. Members sign in through the organisation's identity and access management system

Accepted on 2026-10-05.

## Context

A member has to reach a page to register the attestation key for a relationship. Who may reach it, and how they are
recognised there, is part of what the attestation proves.

## Decision

Members sign in through the organisation's own identity and access management system, over OpenID Connect (OIDC) or the
Security Assertion Markup Language (SAML). The server holds no accounts and no credentials. The member is identified by
a subject claim the organisation can configure. Under OpenID Connect it defaults to `sub`. Under SAML it falls back to
the assertion's NameID when the configured attribute is missing, blank or not a single value. An organisation can point
it at a different claim when that is where its provider exposes a stable identifier known in advance. From the sign-in
the server reads the subject, a display name and, where the provider sends one, a locale. Beyond those it keeps only
what signing out at the provider needs, the identity token or the SAML NameID and session index, and it stores nothing
that names another member. No route carries a member identifier. Every member operation works out whose record it is
from the session. A sign-in fails when the provider reports an error at the callback, the state does not match, the
token or assertion fails a check or the configured subject claim is missing, blank or not a single value. A failed
sign-in creates no session and sends the member to `/me?error=login`, the self-service address marked with the failure.

## Consequences

- Signing in is part of the proof. Only someone the organisation recognises at sign-in can reach the page to register or
  change a key, so someone with no account, a former member or a member whose account is disabled cannot sign in. A
  session already open when an account is disabled lasts until it ends, unless the provider ends it through SAML single
  logout.
- Registration asks for no signature over a challenge, because the member's Astrana instance proves possession of the
  private key at every connection it makes.
- A key already registered stays attestable until the organisation revokes or expires the relationship, whatever happens
  to the account. Disabling an account revokes nothing. Only the organisation can change a member's standing
  ([record 1](0001-the-organisation-grants-revokes-and-extends-relationships-from-its-own-systems.md)), and the state of
  the account does not.
- Any provider that supports the standards works, with no integration beyond the usual client registration.
- The identity provider is fully trusted. Whoever administers it, or controls its claim mapping, can present as any
  member and register or clear that member's keys and revoke or remove that member's relationships, though they cannot
  grant, extend or restore one, because nothing a member holds is ever read from a claim, a role or a group
  ([record 1](0001-the-organisation-grants-revokes-and-extends-relationships-from-its-own-systems.md)).
- A member cannot reach another member's record by changing an address, because no address names one.
- The server's own user accounts were rejected because they duplicate an identity the organisation already holds. A
  persistent SAML NameID, a pseudonym minted at first sign-in, was rejected, because a grant must address an identifier
  known before first sign-in, and a member would otherwise sign in and silently hold nothing. A hard-wired `sub` was
  rejected for the same reason on providers whose subject is opaque.
