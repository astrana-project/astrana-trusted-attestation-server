# 6. Keep third-party dependencies to a minimum

Accepted on 2026-10-05.

## Context

The server joins the security posture of every organisation that runs it, in three package ecosystems. Every third-party
dependency widens the attack surface and the supply chain of every deployment, because code the project did not write
runs inside the server. Each one also has its own release schedule to keep up with, and a vulnerability in any of them
means a release here. The platforms already provide most of what the server needs. Attestation signs nothing and
verifies no signatures ([record 4](0004-attestation-is-anonymous-and-answers-for-one-relationship.md)), and the only
cryptography the server adds beyond the platform's own is in the sign-in protocols, which the protocol libraries carry.

## Decision

Use what the language runtime and the framework provide. Add a third-party dependency to the server only when it does
something they do not and writing it in the project would be the greater risk, which in practice means protocol
implementations, not conveniences. Never add a vendor-specific identity-provider library for sign-in. Every library
dependency is justified in the pull request that adds it, pinned and listed in the implementation's software bill of
materials. Each is open source under a licence compatible with the project's, apart from the Microsoft components that
SQL Server support needs. Platform versions are pinned to the current long-term-support release of .NET and Java and to
the newest release of PHP that the framework and the database extensions support, and move when the next qualifying
release ships.

The rule covers what runs in a deployment, Bootstrap's compiled styles included. Build and test tooling is not shipped,
but it is kept to a minimum in the same way.

## Consequences

- Every organisation gets a smaller attack surface and supply chain, and whoever maintains the server has less to keep
  up with.
- The libraries that implement OpenID Connect and SAML are the permitted kind.
- SQL Server support brings in Microsoft components that are not open source. The .NET image leaves out Microsoft's
  Windows sign-in broker, whose licence does not allow it to be distributed, and the SQL Server driver's files built for
  Windows alone. The image is built for Linux, and the server signs in to SQL Server with a username and password, so it
  needs neither. The published PHP image leaves out PHP's SQL Server extensions and the Microsoft Open Database
  Connectivity driver they need, which is not open source, so an organisation that runs SQL Server builds its own image.
- The only dependency under a strong copyleft licence is Oracle's MySQL driver in .NET and Java. Its licence is the GNU
  General Public License with the universal free and open source software exception, accepted because the exception
  permits it beside the project's licence and it is used only when an organisation selects MySQL.
- Some small utilities are written in the project and reviewed like any other code, and a contributor who reaches for a
  convenience library will be asked why.
- Adding dependencies freely was rejected because the surface grows faster than the value and every one becomes an
  operator's problem. Vendor identity-provider software development kits were rejected because they tie the server to
  one vendor ([record 2](0002-members-sign-in-through-the-organisations-identity-and-access-management-system.md)).
