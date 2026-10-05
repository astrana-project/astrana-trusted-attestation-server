# Built from the repository root as context (so shared/ is in scope):
#   docker build -f dotnet/Dockerfile -t astrana/ata-dotnet .
#
# Build
FROM mcr.microsoft.com/dotnet/sdk:10.0 AS build
WORKDIR /src

# The contract, schema and interface inputs are embedded or copied from ../../../shared at build time,
# so they must be present before the build. The project imports the notices target from dotnet/build.
COPY shared/ shared/
COPY dotnet/global.json dotnet/
COPY dotnet/build/ dotnet/build/
COPY dotnet/src/Astrana.TrustedAttestation.Server/Astrana.TrustedAttestation.Server.csproj dotnet/src/Astrana.TrustedAttestation.Server/

# Built for Linux on the processor the image is for alone, so the native files the packages carry for Windows,
# macOS and other processors stay out of the image. The published image is for 64-bit Intel and AMD processors,
# linux-x64. Docker sets TARGETARCH, and a build for 64-bit ARM processors gets linux-arm64.
ARG TARGETARCH
RUN echo "linux-$([ "$TARGETARCH" = arm64 ] && echo arm64 || echo x64)" > /src/runtime-identifier \
    && dotnet restore dotnet/src/Astrana.TrustedAttestation.Server/Astrana.TrustedAttestation.Server.csproj -r "$(cat /src/runtime-identifier)"

COPY dotnet/src/ dotnet/src/
RUN dotnet publish dotnet/src/Astrana.TrustedAttestation.Server/Astrana.TrustedAttestation.Server.csproj -c Release -r "$(cat /src/runtime-identifier)" --self-contained false -o /app --no-restore

# Run
FROM mcr.microsoft.com/dotnet/aspnet:10.0
WORKDIR /app
COPY --from=build /app .
# The third-party notices, served from the web root, also at a fixed path beside the application.
COPY --from=build /app/wwwroot/THIRD-PARTY-NOTICES.txt THIRD-PARTY-NOTICES.txt

# No port is exposed by default. Astrana Trusted Attestation refuses to start without TLS, so the
# operator has to choose to bind an https endpoint here, or to set
# TrustedAttestation__Tls__TerminatedByProxy=true and put a TLS-terminating proxy in front.
USER $APP_UID
ENTRYPOINT ["dotnet", "Astrana.TrustedAttestation.Server.dll"]
