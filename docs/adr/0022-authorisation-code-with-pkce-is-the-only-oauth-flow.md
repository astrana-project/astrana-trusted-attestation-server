# 22. Authorisation code with PKCE is the only OAuth flow

Accepted on 2026-10-05.

## Context

Members sign in over OpenID Connect or SAML
([record 2](0002-members-sign-in-through-the-organisations-identity-and-access-management-system.md)). OAuth 2.0 offers
several ways to obtain a token, and the providers organisations run still support the older ones. Which flow the server
uses decides how a token can be stolen on the way.

## Decision

Over OpenID Connect the server uses the authorisation code flow with Proof Key for Code Exchange (PKCE), as a
confidential client with one fixed redirect address that the provider matches exactly. That is the profile OAuth 2.1
reduces OAuth 2.0 to. There is no implicit grant, no resource-owner password grant and no hybrid flow. Each
implementation asks for PKCE explicitly where its framework does not send a code challenge for a confidential client by
default.

## Consequences

- No token ever travels in a browser address. The implicit grant did that, which is why OAuth 2.1 removed it. A stolen
  authorisation code is useless without the verifier that only this server holds.
- A provider that only supports the implicit grant cannot be used. No provider an organisation is likely to run supports
  only the implicit grant.
- Exact redirect matching means a deployment's public address must be registered at the provider exactly, and a wrong
  trailing slash on the registered redirect address breaks it.
- The password grant was rejected because the server would then handle the member's credentials, and it holds none
  ([record 2](0002-members-sign-in-through-the-organisations-identity-and-access-management-system.md)). Letting each
  framework choose its default was rejected because one of the three would not have sent a code challenge at all.
