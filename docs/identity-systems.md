# Identity systems

The Astrana Trusted Attestation Server signs members in through the organisation's own identity and access management
system, over OpenID Connect or SAML 2.0. Any system that supports either standard can work, as long as it meets
[the one requirement](#the-one-requirement) below.

## Tested systems

Each system below has passed the conformance suite, run through the identity provider matrix, against all three
implementations, .NET, Java and PHP, at the version shown. Keycloak is also the identity provider in continuous
integration, so it is tested on every change to an implementation or to `shared/`.

| System                                                     | Version tested | Protocol       | How the member's identifier is presented                                                                                                                                                                                         |
| ---------------------------------------------------------- | -------------- | -------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| [Authelia](https://www.authelia.com)                       | 4.39.20        | OpenID Connect | In a custom `ata_subject` claim, read from an extra attribute on each member and released by a custom `ata` scope. Authelia generates its own `sub`, which cannot be set, so the server requests the scope and reads that claim. |
| [Authentik](https://goauthentik.io)                        | 2024.10        | OpenID Connect | In `sub`, set by a scope mapping.                                                                                                                                                                                                |
| Authentik                                                  | 2024.10        | SAML           | In the NameID, set by a NameID property mapping.                                                                                                                                                                                 |
| [Casdoor](https://casdoor.ai/)                             | 4.0.0          | OpenID Connect | In `sub`, which is the Casdoor user identifier, set when each member is created.                                                                                                                                                 |
| [Keycloak](https://www.keycloak.org)                       | 26.0           | OpenID Connect | In `sub`, which is the Keycloak user identifier, set when each member is created.                                                                                                                                                |
| [SimpleSAMLphp](https://simplesamlphp.org)                 | 2.3            | SAML           | In a persistent NameID taken from each member's `uid` attribute.                                                                                                                                                                 |
| [WSO2 Identity Server](https://github.com/wso2/product-is) | 7.1.0          | OpenID Connect | In `sub`, which the application takes from each member's `externalId`, the System for Cross-domain Identity Management (SCIM) field for an identifier held by another system.                                                    |
| WSO2 Identity Server                                       | 7.1.0          | SAML           | In the NameID, taken from each member's `externalId`.                                                                                                                                                                            |
| [Zitadel](https://zitadel.com)                             | 2.66.1         | OpenID Connect | In `sub`, which is the Zitadel user identifier, set when each member is created.                                                                                                                                                 |
| Zitadel                                                    | 2.66.1         | SAML           | In a `UserID` attribute, which the server is set to read, because Zitadel's NameID carries the login name.                                                                                                                       |

## Systems expected to work, not tested

The project has not tested these systems. They implement OpenID Connect or SAML, so they are expected to work if they
meet the requirement below.

- [Google Workspace](https://workspace.google.com) presents a `sub` that is an opaque code you cannot know in advance.
  Set the subject claim to `email` and grant relationships to members' addresses at your domain. Set the OAuth consent
  screen's user type to Internal, because the server does not check which domain a Google account belongs to.
- [Microsoft Entra ID](https://learn.microsoft.com/en-us/entra/fundamentals/what-is-entra), which
  [Microsoft 365](https://www.microsoft.com/en/microsoft-365) signs in through, presents an opaque `sub` too. Add the
  optional `email` claim to the ID token, or use another claim that carries an identifier your system of record holds,
  and set the subject claim to it. The issuer address contains the directory (tenant) ID, not your domain name. With
  `email`, anyone who can edit a user's email address can sign in as that member.
- For
  [Active Directory Federation Services](https://learn.microsoft.com/en-us/windows-server/identity/ad-fs/ad-fs-overview),
  [Okta](https://www.okta.com) and [PingFederate](https://docs.pingidentity.com/pingfederate/latest/), check that the
  system can send, at every sign-in, a claim or attribute that carries an identifier your system of record already
  holds, such as an employee number, a directory object identifier or a user principal name, and set the subject claim
  to it.

Use `email` only where members cannot change their own address. The server does not check whether the identity system
has verified it.

## The one requirement

At every sign-in, the identity system must present one stable identifier for the member, and the organisation must know
that identifier before the member first signs in.

The organisation grants a relationship to an identifier before the member has ever signed in. When the member signs in,
the server looks for relationships granted to the identifier the identity system presents. If the system presents a
different identifier, the member signs in and holds nothing.

By default the server reads the OpenID Connect `sub` claim or the SAML NameID. The subject claim setting points it at
another claim or attribute instead. If the configured claim is missing, blank or has more than one value, the sign-in
fails and no session is created. Under SAML the server first falls back to the assertion's NameID.

A system that can only issue an identifier of its own making, such as an opaque `sub` or a persistent NameID created at
first sign-in, does not fit on its own, because nobody can know that identifier in advance. It fits if it can also send
another claim or attribute that carries an identifier the organisation controls, as Authelia does in the tested list.
[Decision record 2](adr/0002-members-sign-in-through-the-organisations-identity-and-access-management-system.md) records
the rule and why.

## Adding a system to the tested list

A system joins the tested list by passing the conformance suite, run through the identity provider matrix, against all
three implementations. It needs its own folder of configuration in `shared/test` and a profile in
`integration-matrix.sh`, and its test members must present the known identifiers the test fixtures grant relationships
to. [The identity provider matrix](../shared/test/README.md#the-identity-provider-matrix) describes how to run it.
