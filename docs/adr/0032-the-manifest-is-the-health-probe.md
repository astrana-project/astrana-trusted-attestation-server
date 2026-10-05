# 32. The manifest is the health probe

Accepted on 2026-10-05.

## Context

An operations team that runs the server behind a load balancer, in a container orchestrator or under a monitoring system
needs an address to poll to decide whether to send the server traffic or restart it, a liveness and readiness probe.
Such probes usually call a dedicated health endpoint.

## Decision

The manifest at `/.well-known/ata-manifest.json` ([record 30](0030-the-manifest-is-the-public-discovery-document.md)) is
the liveness and readiness probe. It is anonymous and served by every implementation, and because the server refuses to
run when its configuration is incomplete
([record 31](0031-refuse-to-run-without-tls-the-identity-and-access-management-system-or-a-valid-manifest.md)), a server
that answers it with HTTP 200 - OK has passed every check that record makes. No separate health endpoint is added.

## Consequences

- A probe needs nothing but the address every deployment already publishes, and nothing else has to be exposed.
- Under SAML in .NET and PHP those checks do not include the identity provider's metadata, which is fetched at the first
  sign-in, and no implementation checks the client secret.
- In .NET and Java the manifest is built once at start-up, so a probe costs no more than sending it and touches neither
  the database nor the identity provider. It says the application is up, not that its dependencies are reachable, and a
  failed database shows up as an error on the next attestation, not on the probe. A deployment that needs a dependency
  check puts an attestation of a key registered for that purpose in its monitoring instead.
- In PHP every request runs the start-up checks, so the probe also fails when the database cannot be reached, and under
  OpenID Connect when the discovery document cannot be fetched once its hour-long cache has expired.
- The probe is a public address, so probing it from outside the network is indistinguishable from discovery, which is
  what anonymity requires.
- A dedicated endpoint that checked the database was rejected because it is one more surface to keep in parity across
  three implementations, and because in .NET and Java a check that opens a database connection on every probe adds load
  an idle server would not otherwise carry.
