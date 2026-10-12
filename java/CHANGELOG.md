# Changelog (Java)

All notable changes to the Java implementation of the Astrana Trusted Attestation Server are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). [Versions and releases](../docs/versions.md) says what each
number means and how the versions of the three implementations relate.

## [1.0.4] - 2026-10-13

### Fixed

- In every language other than English, the revoke and remove buttons, the paste button, the error for an invalid key
  and the warning above the buttons now mean what the English means. The product name Astrana Trusted Attestation is now
  written as it is in English, and each language uses one word for each thing and one form of address throughout, the
  one usual in that language.
- The note on an expired relationship, the questions before revoking and before removing a relationship, and the error
  for a relationship that was never granted now name the organisation instead of saying "the organisation", and mean
  what the English means in every language.
- The question before removing a relationship now says in every language, not only in English, that only the
  organisation can grant the relationship again.

## [1.0.3] - 2026-10-10

### Fixed

- With no SAML signing key configured, a logout request from the identity system is now answered with HTTP 404 - Not
  Found, and the member stays signed in, instead of the server signing the member out and then failing with HTTP 500 -
  Internal Server Error.
- With no SAML signing key configured, the server's metadata no longer offers single logout, even with the single logout
  address set.

## [1.0.2] - 2026-10-10

### Fixed

- A SAML logout request from the identity system that has expired is now refused, and the member stays signed in,
  instead of signing the member out.

## [1.0.1] - 2026-10-08

### Fixed

- Sign-in over OpenID Connect now always asks for the `openid`, `profile` and `email` scopes, as the .NET and PHP
  implementations do, so the `scope` setting no longer needs to list them.

## [1.0.0] - 2026-10-05

### Added

- First release of the Java implementation. It includes:
  - An attestation endpoint that tells anyone holding an attestation key, without signing in, whether the key is
    registered and whether its relationship is active, revoked or expired.
  - A self-service page where members sign in through the organisation's own sign-in system, over OpenID Connect or
    SAML, to register their keys.
  - A public manifest describing the organisation, in each language it offers.
  - Support for PostgreSQL, MySQL and SQL Server, with relationships granted from the organisation's own systems.
  - Pages in 57 languages, in light and dark, built to the Web Content Accessibility Guidelines (WCAG) 2.1 AA.
