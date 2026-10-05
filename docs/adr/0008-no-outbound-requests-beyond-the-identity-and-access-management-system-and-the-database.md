# 8. No outbound requests beyond the identity and access management system and the database

Accepted on 2026-10-05.

## Context

The privacy model rests on the organisation learning nothing about who asks
([record 4](0004-attestation-is-anonymous-and-answers-for-one-relationship.md)) and on the server being something an
organisation can run inside its own estate. A server that calls out, to report usage, to check for updates, to fetch an
asset, tells someone outside the organisation that it is running and when, and gives a security reviewer another path to
trace.

## Decision

The only outbound connections the server makes are to the organisation's identity and access management system
([record 2](0002-members-sign-in-through-the-organisations-identity-and-access-management-system.md)), for its discovery
document or SAML metadata, its signing keys, its token endpoint and its user-information endpoint, and to the database.
There is no telemetry, no update check, no crash reporting, no fetch of a font, a script or an image at page view, and
no call to any Astrana service.

## Consequences

- Nothing the server does is visible outside the organisation except to the members, the verifying Astrana instances
  that call it and, where the identity system is a hosted service, its operator.
- A security reviewer can enumerate every outbound connection from the configuration alone, and a firewall that allows
  only the identity system and the database blocks nothing the server needs.
- The project learns nothing about deployments. There is no count of running servers, no version census and no error
  reports, and a vulnerability has to be announced rather than pushed.
- Everything the pages need ships with the server, which is why the stylesheet is built once and copied in and no font
  is loaded from the network, and why the dependency rule
  ([record 6](0006-keep-third-party-dependencies-to-a-minimum.md)) matters at run time as well as in the supply chain.
- An opt-in update check was rejected because an opt-in that reaches out is still a call home, and the organisations
  this is built for would have to audit and disable it. Loading shared assets from a content delivery network was
  rejected for the same reason and for the supply-chain risk.
