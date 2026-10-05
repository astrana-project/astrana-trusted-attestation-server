# 40. Published images are application-only, demonstrations are bundled

Accepted on 2026-10-05.

## Context

Each implementation is also published as a container image, beside the other ways its stack is installed, and an
organisation brings its own database and identity provider ([record 9](0009-use-a-relational-database.md) and
[record 2](0002-members-sign-in-through-the-organisations-identity-and-access-management-system.md)). The security scope
and the deployment model both depend on what the image contains.

## Decision

The published image for each implementation, `astrana/ata-dotnet`, `astrana/ata-java` and `astrana/ata-php`, holds the
application and nothing else, with connection details supplied as configuration. It is built in stages on each stack's
official runtime image, for amd64 only, runs as a user that is not root and carries no build or test tooling, no
development certificates or configuration and no realm files. The PHP image serves with PHP's built-in web server,
through the application's own router script. Each implementation also ships a demonstration compose file in the
repository. It builds the application's image from the checkout and runs it with that stack's usual database, a TLS
proxy and Keycloak in development mode with a sample realm and users. The database is SQL Server for .NET, PostgreSQL
for Java and MySQL for PHP. Relationships are granted to those users at first start, so one command gives a working
demonstration. All three demonstrations start with the same command and offer the same members and relationships.

## Consequences

- Nothing bundled can become an insecure default left in place in production. The project answers for the security of
  the images and not the demonstrations.
- The image does nothing on its own, so the demonstration is what shows a working server in one command.
- The images do not run natively on an ARM host.
- PHP's built-in web server handles one request at a time and is not intended for production, so the PHP image suits a
  small organisation's traffic behind a proxy. Beyond that, the organisation runs PHP under its own web server.
- The demonstration is disposable and insecure. It runs on fixed credentials and a development-mode identity provider,
  addressed as localhost but published on every network address of the computer it runs on. Each demonstration publishes
  its identity provider on the same host port, so only one runs at a time.
- Bundling a database or an identity provider into the published image was rejected because a bundled default could be
  left in place in production. Shipping the demonstrations as images was rejected because they are configuration that
  changes with the repository.
