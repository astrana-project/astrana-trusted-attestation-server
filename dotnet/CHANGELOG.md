# Changelog (.NET)

All notable changes to the .NET implementation of the Astrana Trusted Attestation Server are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). [Versions and releases](../docs/versions.md) says what each
number means and how the versions of the three implementations relate.

## [1.0.9] - 2026-10-15

### Changed

- ASP.NET Core's OpenID Connect library and Entity Framework Core's SQL Server provider are updated from 10.0.11 to
  10.0.13. Entity Framework Core 10.0.13 brings in the SQL Server driver 6.1.7 in place of 6.1.6.

## [1.0.8] - 2026-10-14

### Changed

- The code that answers SAML logout requests was restructured. The server behaves exactly as before.

## [1.0.7] - 2026-10-13

### Security

- Saving or clearing a key and removing a relationship now need the session's anti-forgery token in the `X-CSRF-TOKEN`
  header, as revoking already did. Without it a signed-in member's request is refused with HTTP 403 - Forbidden and
  changes nothing.
- The member API reads the anti-forgery token from the `X-CSRF-TOKEN` header and nowhere else. It used to accept a form
  field in the request body as well.

## [1.0.6] - 2026-10-13

### Fixed

- A request body with invalid UTF-8 anywhere in it, not only in the key, is now treated as invalid, as on the Java and
  PHP implementations. An attestation request gets the same answer as one with no valid key, and saving a key is refused
  with HTTP 400 - Bad Request.
- A request body that contains a number more than 1,000 digits long, or an escaped half of a two-part character with the
  other half missing, is now read as containing no key, as the contract sets out, so the three implementations give it
  the same answer.

## [1.0.5] - 2026-10-13

### Fixed

- The self-service page now shows the running version in its footer, linked to the Astrana releases page, so that the
  people who operate and support the server can confirm which version is running. No other page shows it.

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

## [1.0.3] - 2026-10-11

### Fixed

- A request body sent in chunks now counts only its own bytes towards the 64-kilobyte limit on the API, so it gets the
  same answer as the same body sent whole.
- Behind a reverse proxy, a large request body refused with HTTP 413 - Content Too Large, or with HTTP 401 -
  Unauthorized for a caller who isn't signed in, is now always answered with that status, instead of sometimes with the
  proxy's HTTP 502 - Bad Gateway.

## [1.0.2] - 2026-10-10

### Fixed

- With no SAML signing key configured, a logout request from the identity system is now answered with HTTP 404 - Not
  Found, and the member stays signed in, instead of the server failing with HTTP 500 - Internal Server Error.

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
