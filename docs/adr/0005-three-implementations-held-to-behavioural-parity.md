# 5. Three implementations held to behavioural parity

Accepted on 2026-10-05.

## Context

Each organisation runs the server itself, and organisations run very different stacks. Many Microsoft-ecosystem
organisations, government departments and banks run .NET. Many finance, insurance and healthcare enterprises run Java.
Small organisations on cheap or shared hosting usually run PHP. A server for one stack would ask the others to run
infrastructure they do not have and cannot support.

## Decision

Build three real implementations of the same server, in .NET, Java and PHP, each in the idiom of its stack, and hold
them to behavioural parity. Every observable behaviour that the contract and the shared interface define is identical in
all three. That covers API responses, status codes, the manifest and the pages, so that a member or a verifying Astrana
instance gets the same answer whichever one served the request. Where the three diverge on a security posture, the
strictest becomes the shared behaviour.

## Consequences

- An organisation runs the server on the stack it already operates. These are production implementations for different
  environments, not reference implementations and not demonstrations.
- An observable change is made in all three, with the contract or the shared interface and the tests that check it, in
  one pull request.
- The cost is three toolchains, three sets of dependencies to watch and every observable change done three times.
- Three implementations also show where the contract is incomplete, which keeps it honest.
- One implementation shipped as a container was rejected because shared hosting cannot run containers, regulated
  organisations run approved stacks with their own operations teams, and asking them to run an unfamiliar runtime is the
  thing three implementations exist to avoid. Three implementations without enforced parity were rejected because they
  drift.
