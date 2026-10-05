# 19. Rate limiting and operational logging are left to the host

Accepted on 2026-10-05.

## Context

The organisation runs the server inside its own estate
([record 5](0005-three-implementations-held-to-behavioural-parity.md)), and every estate already has its own way of
limiting traffic, detecting abuse and collecting logs, from nothing at all on a small host to a gateway, a web
application firewall and a logging platform at a bank.

## Decision

The server does no rate limiting, no throttling and no abuse detection, and its operational logging is its framework's
ordinary logging for the host to collect. An organisation that wants limits puts a gateway, a firewall or a proxy in
front. The audit log is separate from this and is mandatory
([record 17](0017-the-audit-log-is-append-only-apart-from-age-based-retention.md)).

## Consequences

- Each organisation applies the limits and the retention its environment and risk tolerance call for, with tooling it
  already runs, and nothing in the server has to be reconfigured for it.
- A deployment with nothing in front has no protection against a flood of attestation requests. Each is a cheap indexed
  lookup, but load is load, and a deployment on shared hosting or directly under a web server has only the protection
  its host provides.
- Limits built into the server were rejected because every organisation would retune them, and a limit applied inside
  the application is applied after the request has already reached it.
