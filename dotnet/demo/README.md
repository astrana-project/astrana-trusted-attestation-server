# Astrana Trusted Attestation demonstration (.NET and SQL Server)

The self-contained demonstration stack for this implementation. It bundles the server, SQL Server, a proxy that
terminates TLS and a throwaway Keycloak identity provider. Nothing else needs to be checked out or installed.

The SQL Server it runs is Microsoft's free Developer edition, whose licence allows it for development, testing and
demonstration but not production. By starting the stack you accept the
[SQL Server Developer licence terms](https://go.microsoft.com/fwlink/?linkid=857698).

From the `dotnet` folder:

```bash
docker compose -f demo/docker-compose.yml up --build
```

Then open **https://localhost:15443**. Your browser warns once about the certificate, because it comes from the local
certificate authority of Caddy, the bundled proxy. Accept it, and sign in as `alice` with the password `password`. The
other members are `bob`, `carol` and `dave`, with the same password.

The demonstration grants relationships itself when it first starts, from `seed.sql`, so there is something to see at
once. `alice` holds an employee relationship waiting for a key, a client relationship that is active and a licensed
professional relationship the organisation has revoked. `bob` holds an active employee relationship, `carol` a client
relationship that expired on 1 January 2026 and `dave` nothing. The organisation grants relationships, not the member.
Grant another, also from the `dotnet` folder, with:

```bash
MSYS_NO_PATHCONV=1 docker compose -f demo/docker-compose.yml exec db /opt/mssql-tools18/bin/sqlcmd \
  -S localhost -U sa -P "Trusted-Attestation-Demo-Password1" -d trusted_attestation -C \
  -Q "EXEC grant_member_relationship '44444444-4444-4444-8444-444444444444', 'employee', NULL, 'demo'"
```

`MSYS_NO_PATHCONV=1` stops Git Bash on Windows from rewriting the path to `sqlcmd`, and does nothing elsewhere.

That subject identifier is dave's. The demonstration realm, `keycloak/trusted-attestation-dev-realm.json`, sets a fixed
subject identifier for each member.

## This Keycloak is disposable and insecure

It runs `start-dev`, which means no TLS, an embedded database that vanishes with the container and an administrator
(`admin`, with the password `admin`) created from environment variables in a file anyone can read. The members have the
password `password`. All of that suits a demonstration on your own machine and is catastrophic anywhere else. **Never
make this container reachable from a network you do not trust, and never ask real people to sign in through it.** A
production deployment uses the organisation's existing identity and access management system, and this container exists
only so the demonstration needs no such thing.

## Where TLS terminates

At the bundled Caddy proxy, not in the server's container. Caddy issues the certificate from its own local certificate
authority (`tls internal`), which your browser does not trust, so it warns once. The server runs plain HTTP inside the
compose network with `TrustedAttestation__Tls__TerminatedByProxy=true`, and refuses any request the proxy marks as
having arrived over plain HTTP.

This is one of the two supported ways to deploy it. In the other, the server's own container holds the certificate. You
set an `https://` address in `ASPNETCORE_URLS` and mount a certificate. If neither is set up, the server refuses to
start. There is no plain-HTTP fallback.

## Ports

| What                      | Where                                     |
| ------------------------- | ----------------------------------------- |
| The server, via the proxy | https://localhost:15443                   |
| Keycloak                  | http://localhost:8081                     |
| SQL Server                | not published, only the server reaches it |

The demonstration publishes its ports on every network address of this computer, so other machines on your network can
reach the proxy and this Keycloak, whose administrator password is `admin`. Run it only on a network you trust, or block
its ports from other machines with a firewall. Do not limit Keycloak's port to `127.0.0.1:` in `docker-compose.yml`. On
Docker Engine on Linux that breaks sign-in, because the server reaches Keycloak through `host.docker.internal`, which is
your computer's address on the Docker network. On Docker Desktop with the Windows Subsystem for Linux (WSL2), a port
limited that way can also hang when a browser reaches `localhost` over IPv6.

Port 8081 is also used by the Java and PHP demonstrations, the shared development environment in
`shared/test/docker-compose.yml` and the integration matrix in `shared/test/integration-matrix.sh`. Port 15443 is also
used by the integration stack that `scripts/integration.sh` runs, the integration matrix and `dotnet run`. Run only one
of them at a time.
