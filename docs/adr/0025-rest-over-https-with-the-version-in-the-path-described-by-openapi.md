# 25. REST over HTTPS with the version in the path, described by OpenAPI

Accepted on 2026-10-05.

## Context

The server has a handful of operations. They read a member's relationships, register or clear a key, revoke or remove a
relationship and check a key ([record 4](0004-attestation-is-anonymous-and-answers-for-one-relationship.md)). In all
three implementations ([record 5](0005-three-implementations-held-to-behavioural-parity.md)), they have to be reachable
from a member's browser, from any Astrana instance and through whatever an organisation puts in front of its servers.

## Decision

The API is a small set of HTTP endpoints in the Representational State Transfer (REST) style, with JSON bodies over
HTTPS, described in `shared/contract/openapi.yaml`, which all three implementations implement and the suite checks
against. The routes live under `/api/v1/`. The same routes are also served under `/api/` with no version segment, which
always means the latest version, so that a breaking change can ship as `/api/v2/` alongside the first without every
deployment moving at once. The manifest is not versioned this way. It sits at one fixed path and carries its version as
a field.

## Consequences

- Plain HTTPS works through shared hosting, old reverse proxies, API gateways and web application firewalls alike, with
  nothing for an organisation to enable.
- A caller that pins a version keeps working after a newer one exists. A caller on `/api/` never tracks version numbers,
  and takes every breaking change the day a new version ships.
- The manifest's attestation endpoint names the full address, so a verifying Astrana instance follows the manifest
  rather than guessing a path or a version.
- Two prefixes have to be routed and tested, and the differential part of the suite checks both.
- Anyone can call every endpoint with `curl`. The member endpoints need a session cookie taken from a signed-in browser,
  and revoke also needs its anti-forgery token. That matters because there is no administrator interface to inspect
  things through ([record 1](0001-the-organisation-grants-revokes-and-extends-relationships-from-its-own-systems.md)).
- OpenAPI has tooling in all three stacks and in whatever a fourth implementation might be written in, and one contract
  file is what the three are held to.
- The gRPC remote procedure call framework was rejected because it needs HTTP/2 end to end, which is not reliable across
  cheap hosting, older proxies and gateways. GraphQL was rejected because it exists for flexible queries over a graph,
  and the check here is one point lookup with no listing. The Simple Object Access Protocol (SOAP) was rejected because
  none of the environments the server is built for still requires it. An API this small gains nothing from a richer
  protocol that would justify the extra complexity. A version in a header or a query parameter was rejected because
  either is easy to drop along the way, and a header does not appear in access logs.
