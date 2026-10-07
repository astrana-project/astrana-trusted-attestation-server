# Installation guide

Installing the Astrana Trusted Attestation Server has five steps:

1. Register it with your identity and access management system
2. Give it an empty database
3. Write its configuration
4. Run it
5. Grant, revoke and extend relationships from your own systems

The server creates its own schema on first run. There is no administrator interface to set up. Each implementation runs
either as a Docker container behind a TLS-terminating proxy or installed on a server, and the PHP implementation also on
ordinary shared hosting.

Each of the installation guides listed below outlines how to choose between them.

There are three implementations. All three behave identically and support all three databases, so pick the one that fits
what you already run, and follow its page from start to finish.

Each implementation is well suited to and aligned with particular kinds of organisation, and its page is written for
them.

The .NET and Java implementations are well suited to and aligned with enterprise and government environments, which
usually have a central identity system, a database team and change control.

The PHP implementation is well suited to and aligned with small organisations, such as clubs, community groups,
congregations and charities, which usually have a web hosting account and a Google Workspace or Microsoft 365
subscription.

| Implementation                                               | Runs on                                                                                          | Container image name |
| ------------------------------------------------------------ | ------------------------------------------------------------------------------------------------ | -------------------- |
| [Installing the .NET implementation](installation/dotnet.md) | ASP.NET Core 10, behind Internet Information Services (IIS) on Windows or Nginx on Linux         | `astrana/ata-dotnet` |
| [Installing the Java implementation](installation/java.md)   | Spring Boot on Java 25, as a standalone jar                                                      | `astrana/ata-java`   |
| [Installing the PHP implementation](installation/php.md)     | Laravel on PHP 8.4.1 or later, on Linux or Windows shared hosting, or under Apache, Nginx or IIS | `astrana/ata-php`    |

Each release is published on Docker Hub under the name in the table, for 64-bit Intel and AMD processors (amd64) only.
Every image carries three tags, its full version such as `1.0.0`, its major and minor version such as `1.0`, and
`latest`. Pull the full version, so that the image changes only when you choose to update. On any other processor, or to
build it yourself, build the image from this repository under the same name. Each page shows both. The published PHP
image supports MySQL and PostgreSQL only. For SQL Server, build the PHP image yourself, as its page describes.

## What you need

- An identity and access management system that supports OpenID Connect or SAML 2.0. Keycloak, Microsoft Entra ID, Okta,
  Active Directory Federation Services, Authentik, Zitadel and others all qualify. Members sign in through it, so it
  must already know every person you intend to grant a relationship to. The [identity systems page](identity-systems.md)
  lists the systems tested with the server, and what to check in any other.

- A database server of one of three kinds, PostgreSQL, MySQL or Microsoft SQL Server, with an empty database on it.

- Somewhere to run the implementation you chose, with a TLS certificate for the address that members and verifying
  Astrana instances will use, or a reverse proxy that terminates TLS in front of it.

- A stable identifier for each member that your identity system presents at sign-in and that you know before the member
  ever signs in. Each page's first step says why.

- To build it, the toolchain for the implementation you chose, or Docker. That is the .NET 10 software development kit,
  a Java 25 development kit with the Maven wrapper in `java/`, or Composer with PHP 8.4.1 or later.
