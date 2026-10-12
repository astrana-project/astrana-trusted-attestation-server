# 46. The published container images contain only what the server needs

Accepted on 2026-10-12.

## Context

Every organisation that runs a published image runs everything in it. Each program or library in the image that the
server never uses still widens the attack surface, makes the image larger to pull, and is one more package the
organisation has to scan and patch. Whatever is in the image also has to be kept patched at its source, or it becomes a
list of known vulnerabilities.

[Record 43](0043-the-published-container-images-run-on-amd64-and-64-bit-arm.md) decides what goes into the images on top
of the base they are built on, and says nothing about the base itself.

## Decision

Each published image contains only what the server needs to run. It is built on the smallest base that runs the server
and that its publisher keeps patched, and nothing that the server does not use is added to it.

## Consequences

- The .NET image runs on Microsoft's chiseled ASP.NET Core image, in the variant that adds language and time zone data.
  The Java image runs on Google's distroless base, which has the C library and time zone data and little else, with a
  Java runtime that jlink cuts down from Eclipse Temurin's to the modules the server uses. The PHP image runs on the
  official PHP image for Debian, and removes the compilers and download tools that built PHP's database extensions, but
  keeps the shell and package manager of its base.
- The .NET and Java images have a smaller attack surface than a full runtime image would give them, are smaller to pull,
  and have fewer packages for an organisation to scan and patch. Vulnerabilities in packages the server never uses are
  not reported against them, because those packages are not in them.
- The .NET and Java images have no shell and no package manager, so nothing can be installed in them and `docker exec`
  cannot open a shell in them. An organisation whose identity system or database uses a certificate from a private
  certificate authority trusts that authority without changing the image. For .NET it mounts a copy of the image's
  certificate bundle with the authority's certificate added, and sets `SSL_CERT_FILE` to its path. For Java it mounts a
  trust store that includes the authority and points Java at it through `JAVA_TOOL_OPTIONS`.
- The distroless base has no locale data, so the Java image copies Debian's `C.UTF-8` locale file from the Debian
  release the base is built from. Without it Java reads environment variables and file names as ASCII.
- A Java dependency that needs a module the runtime leaves out fails at run time, so a change to the Java dependencies
  checks the module list in the Java `Dockerfile` and adds what it needs.
- Building the Java image on Debian's slim image was rejected because it carried dozens of serious vulnerabilities with
  no fix, all in packages the server never uses. Building on the full runtime images was rejected because they carry a
  shell, a package manager and packages the server never uses.
