# Built from the repository root as context (so shared/ is in scope):
#   docker build -f dotnet/Dockerfile -t astrana/ata-dotnet .
#
# Build, on the processor of the computer doing the build, so that an image for another processor is
# compiled natively for it rather than under emulation. The runtime identifier below picks the processor
# the output is for.
# The digest pins the exact base image, and Dependabot moves it when the tag gets a new image.
FROM --platform=$BUILDPLATFORM mcr.microsoft.com/dotnet/sdk:10.0@sha256:e70cdb7f80b0348f5cb85f19a8f670fca061f033d57eed12fa003d58b0e06317 AS build
WORKDIR /src

# The contract, schema and interface inputs are embedded or copied from ../../../shared at build time,
# so they must be present before the build. The project imports the notices target from dotnet/build, and takes its
# version from the top heading of dotnet/CHANGELOG.md.
COPY shared/ shared/
COPY dotnet/global.json dotnet/CHANGELOG.md dotnet/
COPY dotnet/build/ dotnet/build/
COPY dotnet/src/Astrana.TrustedAttestation.Server/Astrana.TrustedAttestation.Server.csproj dotnet/src/Astrana.TrustedAttestation.Server/

# Built for Linux on the processor the image is for alone, so the native files the packages carry for Windows,
# macOS and other processors stay out of the image. The published image is for 64-bit Intel and AMD processors,
# linux-x64, and 64-bit ARM processors, linux-arm64. Docker sets TARGETARCH to the processor the image is for.
ARG TARGETARCH
RUN echo "linux-$([ "$TARGETARCH" = arm64 ] && echo arm64 || echo x64)" > /src/runtime-identifier \
    && dotnet restore dotnet/src/Astrana.TrustedAttestation.Server/Astrana.TrustedAttestation.Server.csproj --locked-mode

COPY dotnet/src/ dotnet/src/
RUN dotnet publish dotnet/src/Astrana.TrustedAttestation.Server/Astrana.TrustedAttestation.Server.csproj \
        -c Release -r "$(cat /src/runtime-identifier)" --self-contained false -o /app --no-restore

# Run
# Microsoft's chiseled Ubuntu image holds the ASP.NET Core runtime and the few system libraries it loads, with no shell,
# no package manager and a non-root user. The extra variant adds ICU, which the server needs to render pages in every
# locale it supports, and the time zone data, which it needs to run the audit log clean-up at Audit:RunAt in server
# time.
# The digest pins the exact base image, and Dependabot moves it when the tag gets a new image.
FROM mcr.microsoft.com/dotnet/aspnet:10.0-noble-chiseled-extra@sha256:00e0ad6a7ef8c0c1391b87f05c7ac757a15740455688f2bfcd146a3f4b987efd
WORKDIR /app
COPY --from=build /app .
# The third-party notices, served from the web root, also at a fixed path beside the application.
COPY --from=build /app/wwwroot/THIRD-PARTY-NOTICES.txt THIRD-PARTY-NOTICES.txt

# No port is exposed by default. Astrana Trusted Attestation refuses to start without TLS, so the
# operator has to choose to bind an https endpoint here, or to set
# TrustedAttestation__Tls__TerminatedByProxy=true and put a TLS-terminating proxy in front.
#
# APP_UID is the number of the user that is not root, which the runtime image defines, so a
# platform that refuses to run containers as root can check it. Hadolint cannot see the number
# behind the variable.
# hadolint ignore=DL3066
USER $APP_UID
ENTRYPOINT ["dotnet", "Astrana.TrustedAttestation.Server.dll"]
