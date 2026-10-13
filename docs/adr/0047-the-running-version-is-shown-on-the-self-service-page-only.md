# 47. The running version is shown on the self-service page only

Accepted on 2026-10-13.

## Context

When a member reports a problem, the people who operate and support the server may need to confirm which version is
running, without needing access to the hosting. Each implementation is released under the version at the top of its own
changelog ([record 44](0044-version-numbers-say-what-an-update-means.md)), and security fixes ship in the latest release
only ([record 39](0039-security-fixes-ship-in-the-latest-release-only.md)). So anyone who knows a server's exact version
also knows which published vulnerabilities it still has. Every page has the same attribution footer
([record 33](0033-one-accessible-interface-shared-by-all-three-implementations.md)).

## Decision

The version is shown where someone signed in can read it, and nowhere anyone else can. The footer of the self-service
page, which needs a sign-in, ends with the full version of the running build, linked to the Astrana releases page. No
other page states the version, and neither does the manifest or any response header.

## Consequences

- An exact version on a public page would let anyone find the servers running a release with a known vulnerability, with
  a search engine or a scan. Behind a sign-in, it is out of their reach. Anyone who can sign in can read it, but an
  attacker who has a member's session already has more than the version.
- The version comes from the top of the implementation's changelog. It is written `v` followed by the full version, such
  as `v1.0.1`, after the attribution, and links to `https://www.astrana.org/releases`.
- The version has no label, so there is no new string to translate, and the address it links to is fixed, so an
  organisation cannot point it anywhere else.
- Showing the version on every page was rejected because it publishes the version to anyone. Stating it in a response
  header or in the manifest was rejected for the same reason. Showing only the first two numbers was rejected because
  the version shown would not tell which patch release is running, and a patch release is where a fix to one
  implementation ships.
