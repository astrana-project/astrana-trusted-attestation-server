# Architecture decision records

Why the Astrana Trusted Attestation Server is the way it is, one decision per record, in the order a reader should meet
them.

All of them rest on one premise. An organisation vouches for its own relationships. A member proves one of them to
another Astrana instance, and that instance asks the organisation, live and anonymously, whether the member's key is on
record and, when it is, the type and status of its one relationship.

A record covers one decision that could be reversed on its own. A correction that changes no behaviour is made in place.
To change a decision, write a new record and change the old one's status line to `Superseded by record N on YYYY-MM-DD.`
Never edit a record into a different decision. The old record is the history of why things were as they were.

## The trust model

|     | Record                                                                                                                                                                      | Derives from |
| --: | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------ |
|   1 | [The organisation grants, revokes and extends relationships from its own systems](0001-the-organisation-grants-revokes-and-extends-relationships-from-its-own-systems.md)   | the premise  |
|   2 | [Members sign in through the organisation's identity and access management system](0002-members-sign-in-through-the-organisations-identity-and-access-management-system.md) | 1            |
|   3 | [One attestation keypair per relationship](0003-one-attestation-keypair-per-relationship.md)                                                                                | the premise  |
|   4 | [Attestation is anonymous and answers for one relationship](0004-attestation-is-anonymous-and-answers-for-one-relationship.md)                                              | the premise  |

## How it is built

|     | Record                                                                                                                                                                                   | Derives from |
| --: | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------ |
|   5 | [Three implementations held to behavioural parity](0005-three-implementations-held-to-behavioural-parity.md)                                                                             | the premise  |
|   6 | [Keep third-party dependencies to a minimum](0006-keep-third-party-dependencies-to-a-minimum.md)                                                                                         | 2, 4, 5      |
|   7 | [One repository, with the shared inputs consumed at build time](0007-one-repository-with-the-shared-inputs-consumed-at-build-time.md)                                                    | 5            |
|   8 | [No outbound requests beyond the identity and access management system and the database](0008-no-outbound-requests-beyond-the-identity-and-access-management-system-and-the-database.md) | 2, 4, 6      |

## Data and standing

|     | Record                                                                                                                                                                                                                               | Derives from |
| --: | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------ |
|   9 | [Use a relational database](0009-use-a-relational-database.md)                                                                                                                                                                       | 1            |
|  10 | [The server stores no personal data beyond the subject identifier](0010-the-server-stores-no-personal-data-beyond-the-subject-identifier.md)                                                                                         | 2, 9         |
|  11 | [Support PostgreSQL, MySQL and SQL Server, one schema each](0011-support-postgresql-mysql-and-sql-server-one-schema-each.md)                                                                                                         | 6, 9         |
|  12 | [The schema is applied from the shared SQL files, there are no migrations](0012-the-schema-is-applied-from-the-shared-sql-files-there-are-no-migrations.md)                                                                          | 11           |
|  13 | [Timestamps are UTC in storage and on the wire](0013-timestamps-are-utc-in-storage-and-on-the-wire.md)                                                                                                                               | 5, 11        |
|  14 | [Relationship types are a fixed vocabulary in one shared file](0014-relationship-types-are-a-fixed-vocabulary-in-one-shared-file.md)                                                                                                 | 1            |
|  15 | [Relationship types are admitted by fixed tests, with no catch-all value](0015-relationship-types-are-admitted-by-fixed-tests-with-no-catch-all-value.md)                                                                            | 4, 14        |
|  16 | [Granting, revoking and extending are enforced in the database, not in the application](0016-granting-revoking-and-extending-are-enforced-in-the-database-not-in-the-application.md)                                                 | 1, 9, 12     |
|  17 | [The audit log is append-only, apart from age-based retention](0017-the-audit-log-is-append-only-apart-from-age-based-retention.md)                                                                                                  | 4, 16        |
|  18 | [Audit retention needs nothing but the running application](0018-audit-retention-needs-nothing-but-the-running-application.md)                                                                                                       | 16, 17       |
|  19 | [Rate limiting and operational logging are left to the host](0019-rate-limiting-and-operational-logging-are-left-to-the-host.md)                                                                                                     | 1, 5, 17     |
|  20 | [A member can clear an attestation key, revoke or remove a relationship, only the organisation can restore one](0020-a-member-can-clear-an-attestation-key-revoke-or-remove-a-relationship-only-the-organisation-can-restore-one.md) | 1, 3, 16, 17 |

## The member

|     | Record                                                                                                                                                                       | Derives from |
| --: | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------ |
|  21 | [One session cookie identifies the member, and it never crosses sites](0021-one-session-cookie-identifies-the-member-and-it-never-crosses-sites.md)                          | 2            |
|  22 | [Authorisation code with PKCE is the only OAuth flow](0022-authorisation-code-with-pkce-is-the-only-oauth-flow.md)                                                           | 2            |
|  23 | [The same token and assertion checks in all three, beyond the frameworks' defaults](0023-the-same-token-and-assertion-checks-in-all-three-beyond-the-frameworks-defaults.md) | 2, 5         |
|  24 | [Sign-out is local and works whatever the provider supports](0024-sign-out-is-local-and-works-whatever-the-provider-supports.md)                                             | 2, 21        |

## The API

|     | Record                                                                                                                                          | Derives from |
| --: | ----------------------------------------------------------------------------------------------------------------------------------------------- | ------------ |
|  25 | [REST over HTTPS with the version in the path, described by OpenAPI](0025-rest-over-https-with-the-version-in-the-path-described-by-openapi.md) | 1, 4, 5      |
|  26 | [Error responses reveal nothing beyond the status code](0026-error-responses-reveal-nothing-beyond-the-status-code.md)                          | 4, 5         |

## Keys

|     | Record                                                                                                                                                                                         | Derives from  |
| --: | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------- |
|  27 | [Ed25519 for attestation keys](0027-ed25519-for-attestation-keys.md)                                                                                                                           | 3, 4, 6       |
|  28 | [Attestation keys are checked for format only, in the handler, in all three implementations](0028-attestation-keys-are-checked-for-format-only-in-the-handler-in-all-three-implementations.md) | 4, 20, 26, 27 |
|  29 | [Attestation keys are stored as raw bytes and rows are ordered by a sequential identifier](0029-attestation-keys-are-stored-as-raw-bytes-and-rows-are-ordered-by-a-sequential-identifier.md)   | 4, 27         |

## Discovery and running

|     | Record                                                                                                                                                                                      | Derives from |
| --: | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------ |
|  30 | [The manifest is the public discovery document](0030-the-manifest-is-the-public-discovery-document.md)                                                                                      | 4, 14, 25    |
|  31 | [Refuse to run without TLS, the identity and access management system or a valid manifest](0031-refuse-to-run-without-tls-the-identity-and-access-management-system-or-a-valid-manifest.md) | 2, 30        |
|  32 | [The manifest is the health probe](0032-the-manifest-is-the-health-probe.md)                                                                                                                | 30, 31       |

## The interface

|     | Record                                                                                                                                    | Derives from    |
| --: | ----------------------------------------------------------------------------------------------------------------------------------------- | --------------- |
|  33 | [One accessible interface shared by all three implementations](0033-one-accessible-interface-shared-by-all-three-implementations.md)      | 2, 5, 6, 20, 30 |
|  34 | [Organisation-configured localisation, resolved in a fixed order](0034-organisation-configured-localisation-resolved-in-a-fixed-order.md) | 6, 21, 30, 33   |
|  35 | [A fixed set of security headers, and no Content-Security-Policy](0035-a-fixed-set-of-security-headers-and-no-content-security-policy.md) | 5, 31, 33       |

## Delivery

|     | Record                                                                                                                                                   | Derives from     |
| --: | -------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------- |
|  36 | [Parity is defined by one implementation-blind suite](0036-parity-is-defined-by-one-implementation-blind-suite.md)                                       | 2, 5             |
|  37 | [Only behaviour the contract and the shared interface define must match](0037-only-behaviour-the-contract-and-the-shared-interface-define-must-match.md) | 5, 26, 31, 36    |
|  38 | [Shared feature versions, independent patches, release on merge](0038-shared-feature-versions-independent-patches-release-on-merge.md)                   | 5, 7, 36         |
|  39 | [Security fixes ship in the latest release only](0039-security-fixes-ship-in-the-latest-release-only.md)                                                 | 12, 38           |
|  40 | [Published images are application-only, demonstrations are bundled](0040-published-images-are-application-only-demonstrations-are-bundled.md)            | 2, 5, 9          |
|  44 | [Version numbers say what an update means](0044-version-numbers-say-what-an-update-means.md)                                                             | 5, 7, 12, 36, 38 |
|  45 | [Anyone can check where a published image came from](0045-anyone-can-check-where-a-published-image-came-from.md)                                         | 44               |

## Dates and names

The first 40 records were written down when the project moved into this repository, from its earlier design documents
and its history, and are dated 2026-10-05, the day that set was completed. Later records carry the date they were
accepted. Paths and names are as they are now.

From the first public release on, numbers are fixed. A new record takes the next number and is added to the group it
belongs to in this list, and this list, not the numbering, is the reading order. The "derives from" column names the
decisions a record rests on, whether or not its text cites them. A record cites only lower-numbered records.
