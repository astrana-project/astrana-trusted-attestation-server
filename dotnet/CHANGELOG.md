# Changelog (.NET)

All notable changes to the .NET implementation of the Astrana Trusted Attestation Server are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). [Versions and releases](../docs/versions.md) says what each
number means and how the versions of the three implementations relate.

## [1.0.1] - 2026-10-09

### Fixed

- With a SAML signing key configured, a signed logout request from the identity system now ends the member's session and
  is answered, instead of leaving the member signed in and sending them to the landing page. A logout request is
  refused, and the member stays signed in, when it names a different member from the one signed in, when it does not
  give this server's single logout address as its destination, or when its expiry time has passed.

## [1.0.0] - 2026-10-05

### Added

- First release of the .NET implementation. It includes:
  - An attestation endpoint that tells anyone holding an attestation key, without signing in, whether the key is
    registered and whether its relationship is active, revoked or expired.
  - A self-service page where members sign in through the organisation's own sign-in system, over OpenID Connect or
    SAML, to register their keys.
  - A public manifest describing the organisation, in each language it offers.
  - Support for PostgreSQL, MySQL and SQL Server, with relationships granted from the organisation's own systems.
  - Pages in 57 languages, in light and dark, built to the Web Content Accessibility Guidelines (WCAG) 2.1 AA.
