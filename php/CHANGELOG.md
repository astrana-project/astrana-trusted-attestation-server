# Changelog (PHP)

All notable changes to the PHP implementation of the Astrana Trusted Attestation Server are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). [Versions and releases](../docs/versions.md) says what each
number means and how the versions of the three implementations relate.

## [1.0.12] - 2026-10-16

### Changed

- Laravel is updated from 13.34.0 to 13.36.0, with the Symfony components it brings in, and firebase/php-jwt from 7.1.0
  to 7.2.1.
- The code that answers SAML single logout requests was restructured. The server behaves exactly as before.

## [1.0.11] - 2026-10-14

### Changed

- The code that answers SAML logout requests, and the check on numbers in a request body, were restructured. The server
  behaves exactly as before.

## [1.0.10] - 2026-10-13

### Security

- The API no longer answers with `Access-Control-Allow-Origin: *` or accepts a CORS (Cross-Origin Resource Sharing)
  preflight from another site, so a script on another website can no longer call it from a visitor's browser or read the
  answers, the same as the .NET and Java implementations.

## [1.0.9] - 2026-10-13

### Security

- Saving or clearing a key and removing a relationship now need the session's anti-forgery token in the `X-CSRF-TOKEN`
  header, as revoking already did. Without it a signed-in member's request is refused with HTTP 403 - Forbidden and
  changes nothing.
- The member API reads the anti-forgery token from the `X-CSRF-TOKEN` header and nowhere else. It used to accept a
  `_token` field in the query string or the request body, and the `X-XSRF-TOKEN` header, as well.

## [1.0.8] - 2026-10-13

### Fixed

- The answers from the attestation endpoint and the member API, and the manifest, now write slashes and characters
  outside ASCII as they are, and control characters with upper-case letters, as the .NET and Java implementations write
  them, instead of as `\/` and `\u` escapes.
- A request body nested more than 64 levels deep, or that contains a number more than 1,000 digits long, is now read as
  containing no key, as the contract sets out, so the three implementations give it the same answer.

## [1.0.7] - 2026-10-13

### Fixed

- The self-service page now shows the running version in its footer, linked to the Astrana releases page, so that the
  people who operate and support the server can confirm which version is running. No other page shows it.

## [1.0.6] - 2026-10-13

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
