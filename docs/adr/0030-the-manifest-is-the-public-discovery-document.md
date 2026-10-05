# 30. The manifest is the public discovery document

Accepted on 2026-10-05.

## Context

A verifying Astrana instance is given an organisation's address and a key. It needs to find the attestation endpoint and
see what the organisation declares about itself, and it must be able to do that without credentials.

## Decision

Each server publishes a manifest at `/.well-known/ata-manifest.json`, and an organisation may point to it from its own
website with a link whose relation is `ata-manifest`. It always carries the organisation's name per locale, its default
locale, the relationship types it may attest to, the addresses of its landing page and its attestation endpoint, and the
manifest's own version. It may also carry a description, website, privacy notice and support page per locale, a logo
and, alongside it, a dark-mode variant, and the organisation's jurisdictions. It is built from an explicit list of
public fields, never by serialising the configuration object.

## Consequences

- Discovery needs nothing but the address, and what the verifying instance shows its owner about the organisation comes
  from the organisation. Finding the organisation's address in the first place, from its website or a directory, needs
  nothing from the server.
- Public and private configuration live in one file for the operator's convenience, so the allowlist is a security
  requirement. Serialising the configuration would publish the identity-provider secret and the database connection, and
  the suite checks the manifest for credential-shaped strings.
- The manifest is part of the contract, at a fixed path with a version of its own
  ([record 25](0025-rest-over-https-with-the-version-in-the-path-described-by-openapi.md)). The link relation's name is
  fixed as well, because an organisation's own website carries it.
- The logo is embedded as a `data:` Uniform Resource Identifier (URI) rather than linked, so that showing it puts no
  request on the organisation's server that the organisation could tie to whoever is viewing. The manifest schema caps
  the URI at 65,536 characters, about 48 kilobytes of image, since every consumer downloads it, and the server refuses
  to run with a larger logo.
