# Security Policy

The Astrana Trusted Attestation Server is security-sensitive software. Astrana instances rely on it to check that a
relationship an organisation vouched for is genuine.

## Reporting a vulnerability

**Please do not open a public issue for a security vulnerability.** Public disclosure before a fix is available puts
every deployment at risk.

Report privately through either channel:

- GitHub private vulnerability reporting, from the **Security** tab via **Report a vulnerability**. This opens a private
  advisory visible only to you and the maintainer.
- Email to [security@astrana.org](mailto:security@astrana.org). You can encrypt to the address's OpenPGP key,
  fingerprint `F575 DF23 75AE 0F8F 5A7B 330C 6B57 B3E4 4B8F ACA4`, published on
  [Proton's key server](https://api.protonmail.ch/pks/lookup?op=get&search=security@astrana.org). Mail sent from another
  Proton Mail account is end-to-end encrypted automatically.

Please include enough detail to reproduce and assess the issue, such as the affected implementation or implementations
(.NET, Java, PHP) and version, the affected endpoint or component, the impact and a proof of concept or steps to
reproduce where possible.

If you have a fix, do not open a public pull request. Say so in the report, and we can work on it together in a private
advisory.

### What to expect

- Acknowledgement within a week. If you hear nothing by then, your report has not been seen. Follow up on the same
  report rather than opening a new one.
- An initial assessment and, if the report is accepted, a plan and rough timeline for a fix.
- Disclosure timing agreed with you. If we cannot agree, the default is 90 days from acknowledgement, or earlier if a
  fix has shipped or the issue is being exploited.
- There is no bug bounty. You will be credited in the advisory and in the affected implementation's `CHANGELOG.md`
  unless you prefer to remain anonymous.

## Scope

This project is responsible for the three implementations and the shared contract, schema and web interface in this
repository, and for the published `astrana/ata-*` images built from them.

### In scope

- Forging, bypassing or denying attestation.
- Reading or altering one member's record as another member, or a member granting, extending or restoring their own
  standing.
- Learning who is asking.
- Finding out which keys are on record by trying many.
- Learning more from `/api/v1/attest` than whether one key is on record and, if it is, that one relationship's type,
  subtype and status, including by timing the responses.
- Linking a member's relationships to one another.
- Leaking private configuration through the manifest.
- Altering or deleting audit log entries.
- A known vulnerability in a dependency or in a published image's base layer, where it has a plausible impact on this
  code.

### Out of scope

- The demonstration stacks under `dotnet/demo/`, `java/demo/` and `php/demo/`, and the identity provider fixtures and
  integration-matrix tooling under `shared/test/`. They are insecure, with fixed credentials and no TLS on the bundled
  identity provider, and they are not part of any deployment.
- Issues that require a misconfigured or non-conforming deployment rather than a defect in this code.
- Denial of service by flooding a deployment's network with traffic, as opposed to a flaw in how the application handles
  requests.
- How an Astrana instance generates the challenge or checks the member's signature. That belongs to Astrana itself
  ([astrana.org](https://astrana.org)).
- A vulnerability in an identity provider, database or web server that Astrana Trusted Attestation runs against. Report
  it to whoever makes that software.

## Where to test

Test against the project's code, its published images, its demonstration stacks or a deployment you run yourself. Never
test another organisation's Astrana Trusted Attestation Server without that organisation's permission.

## Security releases

A fix ships as a new release of the affected implementation, with an entry in its `CHANGELOG.md`. A fix in one
implementation alone is a patch release of that implementation, and a fix in a shared input is a patch release of each
of the three. The maintainer publishes a GitHub security advisory for the repository, requests a Common Vulnerabilities
and Exposures (CVE) identifier when the issue needs one, and publishes a new image of each affected implementation. To
hear about fixes, watch the repository's releases and security advisories on GitHub.

## Supported versions

Security issues are fixed in the latest release of each implementation, so to get a fix, update to the latest release.
Within a major version every release is backwards compatible, as [Versions and releases](docs/versions.md) describes, so
updating does not bring a breaking change. Older releases do not get fixes.

When a new major version is released, its release notes will say whether, and for how long, the previous major version
continues to receive security fixes.

This is free and open source software maintained by one person with other commitments, so the response times, disclosure
terms and fixes are what the maintainer aims to provide, not a guarantee.
