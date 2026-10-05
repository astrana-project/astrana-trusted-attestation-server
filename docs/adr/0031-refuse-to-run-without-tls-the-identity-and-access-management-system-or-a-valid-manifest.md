# 31. Refuse to run without TLS, the identity and access management system or a valid manifest

Accepted on 2026-10-05.

## Context

A server missing any of three things cannot do its job safely. Without TLS, sessions and keys travel in the clear.
Without the identity and access management system, nobody can sign in. Without a complete manifest, the organisation
cannot be discovered. Each could be allowed to fail quietly at the moment it is needed.

## Decision

The server refuses to run without all three. TLS must be served by the server itself or terminated by a proxy the
operator declares with an explicit setting. Leaving the setting out never permits plain HTTP. Where the server
terminates TLS itself, which .NET and Java can and PHP cannot, it serves TLS 1.2 or later, and an operator who sets
Java's protocol list explicitly keeps that list. Behind the proxy, the server takes the scheme only from
`X-Forwarded-Proto`, never from `Forwarded` or `X-Forwarded-Ssl`, and also applies `X-Forwarded-Host`, with Java and PHP
also applying `X-Forwarded-Port` and `X-Forwarded-Prefix`. It refuses a request with HTTP 421 - Misdirected Request and
no body unless `X-Forwarded-Proto` holds one value of exactly `https`. It refuses a list of values, whether on one line
or several, a trailing comma, an empty or blank value and any other spelling, capitals included. A request with no
`X-Forwarded-Proto` is served. Where the server can hold the certificate, it checks every endpoint it is configured to
listen on, however that endpoint was configured, and if even one is plain HTTP it refuses to run unless the proxy
setting is on. In .NET that includes every way Kestrel can be given an endpoint. PHP never holds the certificate, so
without the proxy setting it requires its configured address to start with https and refuses any request that did not
arrive over TLS with HTTP 421 - Misdirected Request. Under OpenID Connect the identity provider's discovery document
must be fetched successfully at start-up. Under SAML, Java fetches the metadata at start-up, and .NET and PHP fetch it
through their SAML library when it is first needed. The manifest must pass every rule of the manifest schema, including
that every relationship type it declares is in the vocabulary. .NET and Java refuse at start-up. PHP has no start-up
step, so it runs the checks on every request and refuses the request when they fail. Console commands skip the checks.

## Consequences

- A misconfigured deployment is loud at once rather than degraded and silent. In .NET and Java a container
  orchestrator's restart policy covers an identity provider that comes up late. PHP refuses requests until the identity
  provider answers.
- The identity provider check reads only the discovery document, or under SAML in Java the metadata. Under SAML in .NET
  and PHP a wrong metadata address is found at the first sign-in. A wrong client secret is not caught until the first
  sign-in, and nothing the server says at start-up may suggest otherwise.
- A request with no `X-Forwarded-Proto` is served because not every proxy sets one. Behind a proxy, .NET and Java apply
  `X-Forwarded-Proto` from whichever peer connects, and PHP can narrow it to listed proxy addresses, so the
  application's port must be reachable only by the proxy. The proxy then sets the minimum TLS version.
- Refusing a list of forwarded schemes, rather than picking one value from it, gives the same answer whichever framework
  receives the request, because ASP.NET Core applies the last value and Spring and Laravel the first. For the same
  reason none of the three judges or applies the `Forwarded` header of Request for Comments (RFC) 7239 or
  `X-Forwarded-Ssl`.
- Relaxing the server's validation of the identity provider's own certificate in code was rejected, even behind a
  development flag, because trusting a private certificate authority is the environment's job, not a code path waiting
  to be copied into production.
