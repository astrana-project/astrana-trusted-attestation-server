# 42. The published container images run on amd64 and 64-bit ARM

Accepted on 2026-10-11.

## Context

Each implementation is also published as a container image, beside the other ways its stack is installed. An
organisation runs it on servers it already has, with its own database and identity provider
([record 9](0009-use-a-relational-database.md) and
[record 2](0002-members-sign-in-through-the-organisations-identity-and-access-management-system.md)). Those servers have
amd64 or 64-bit ARM processors, in the organisation's own data centre or rented from a cloud provider. An image built
for one processor runs on the other only under emulation, which is slower, or not at all.

Anything in the image beyond the server is something an organisation could leave in place in production without meaning
to, and something the project would have to answer for.

## Decision

The published image for each implementation, `astrana/ata-dotnet`, `astrana/ata-java` and `astrana/ata-php`, contains
the application and nothing else, with connection details supplied as configuration. It is published for amd64 and
64-bit ARM under the same tags. It is built in stages, runs as a user that is not root and carries no build or test
tooling, no development certificates or configuration and no realm files. The PHP image serves with PHP's built-in web
server, through the application's own router script.

Each implementation also ships a demonstration compose file in the repository. It builds the application's image from
the checkout and runs it with that stack's usual database, a TLS proxy and an identity provider in development mode with
a sample realm and users. Relationships are granted to those users at first start, so one command gives a working
demonstration. All three demonstrations start with the same command and offer the same members and relationships.

## Consequences

- Nothing bundled can become an insecure default left in place in production. The project answers for the security of
  the images and not the demonstrations.
- An organisation runs the published image natively on either kind of server, and Docker pulls the one for the host it
  runs on.
- A release takes longer. The release runner is amd64, so the steps of the arm64 image that have to run on arm64 run
  under emulation.
- The image does nothing on its own, so the demonstration is what shows a working server in one command.
- The demonstrations use SQL Server for .NET, PostgreSQL for Java and MySQL for PHP, with Keycloak as the identity
  provider.
- PHP's built-in web server handles one request at a time and is not intended for production, so the PHP image suits a
  small organisation's traffic behind a proxy. Beyond that, the organisation runs PHP under its own web server.
- The demonstration is disposable and insecure. It runs on fixed credentials and a development-mode identity provider,
  addressed as localhost but published on every network address of the computer it runs on. Each demonstration publishes
  its identity provider on the same host port, so only one runs at a time.
- Publishing for amd64 only was rejected because an organisation that runs 64-bit ARM servers would have to build and
  maintain its own image or run the server under emulation.
- Bundling a database or an identity provider into the published image was rejected because a bundled default could be
  left in place in production. Shipping the demonstrations as images was rejected because they are configuration that
  changes with the repository.
