# Changelog (PHP)

All notable changes to the PHP implementation of the Astrana Trusted Attestation Server are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). [Versions and releases](../docs/versions.md) says what each
number means and how the versions of the three implementations relate.

## [1.0.5] - 2026-10-10

### Fixed

- A request body over 64 kilobytes sent in chunks as multipart form data is now answered with HTTP 413 - Content Too
  Large, as any other body over the limit is, instead of being read as an empty body.
- A request body large enough to use up PHP's memory is now answered with HTTP 413 - Content Too Large, as any other
  body over 64 kilobytes is, instead of with HTTP 500 - Internal Server Error.

## [1.0.4] - 2026-10-10

### Fixed

- PHP's own warnings and errors now go to the log and are never shown in an answer, in the container image and on a
  hosting account alike.
- No answer from the container image, or from a hosting account under Apache or IIS, names PHP's version any more.

## [1.0.3] - 2026-10-10

### Fixed

- With no SAML signing key configured, a logout request from the identity system is now answered with HTTP 404 - Not
  Found, and the member stays signed in, instead of the server signing the member out and replying with an unsigned
  logout response.
- With no SAML signing key configured, signing out now ends the session at the server only and lands on the signed-out
  page, instead of also sending an unsigned logout request to an identity system that offers single logout.
- With no SAML signing key configured, the server's metadata no longer offers single logout.

## [1.0.2] - 2026-10-10

### Fixed

- A SAML logout request from the identity system is now refused, and the member stays signed in, when it names a
  different member from the one signed in or does not say which server it is for, instead of signing the member out.
- A SAML logout request over the redirect binding is now refused, and the member stays signed in, when it is signed with
  SHA-1 or names no signature algorithm, instead of signing the member out.
- The identity system now gets a refusal in reply to a SAML logout request that is for another server or has expired,
  instead of no reply.

## [1.0.1] - 2026-10-09

### Fixed

- When the identity system asks for signed SAML sign-in requests and no SAML signing key is configured, the server no
  longer sends it an unsigned request it would refuse. The sign-in stops at the server with an error, and the log says
  the identity system wants signed requests and no signing key is configured.

## [1.0.0] - 2026-10-05

### Added

- First release of the PHP implementation. It includes:
  - An attestation endpoint that tells anyone holding an attestation key, without signing in, whether the key is
    registered and whether its relationship is active, revoked or expired.
  - A self-service page where members sign in through the organisation's own sign-in system, over OpenID Connect or
    SAML, to register their keys.
  - A public manifest describing the organisation, in each language it offers.
  - Support for PostgreSQL, MySQL and SQL Server, with relationships granted from the organisation's own systems.
  - Pages in 57 languages, in light and dark, built to the Web Content Accessibility Guidelines (WCAG) 2.1 AA.
