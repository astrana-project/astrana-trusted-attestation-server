#!/usr/bin/env bash
#
# Runs the conformance suite across every implementation against one or more IdPs, and builds the matrix
# report from the results. One command for what is otherwise: point each app at the IdP, run check.py for
# each, then integration-report.py.
#
#   shared/test/integration-matrix.sh --idp keycloak            # one IdP, all three implementations
#   shared/test/integration-matrix.sh --all                     # keycloak, zitadel, authentik, simplesamlphp
#   shared/test/integration-matrix.sh --idp zitadel --only php  # a single cell
#   shared/test/integration-matrix.sh --idp wso2 --no-build     # reuse the app images from the last run
#   shared/test/integration-matrix.sh --report-only             # rebuild the report from existing JSON
#
# IdPs: keycloak, zitadel, authentik, simplesamlphp, casdoor, wso2, authelia, zitadel-saml, authentik-saml,
# wso2-saml.
#
# It drives a DEV environment of its own: the shared dev compose alongside this script (Keycloak and the
# three databases, docker-compose.yml) plus an app stack it writes to shared/test/reports/stack and builds
# from the three Dockerfiles in this repository, .NET on 15443, Java on 16443 and PHP on 17443 behind one
# TLS-terminating Caddy, the same shape as the CI stack in ci/docker-compose.ci.yml. That wiring is
# deliberately dev-specific (socat forwarding so each app reaches an IdP at the localhost port the browser
# uses, Zitadel's generated client secret) which is why it lives here and not in ci/. Each run writes JSON
# to shared/test/reports/runs/<idp>-<impl>.json, and the report is rebuilt from every JSON found there, so
# columns accumulate across invocations.
#
# Requires Docker, Python 3, openssl and, for the browser-login IdPs, `pip install playwright`. Ports 15443, 16443,
# 17443 and Keycloak's 8081 are the ones the CI stack and the demos publish too, so stop those first. Only
# the IdP being tested is brought up, so all of them plus the apps and a browser do not fight for memory. The
# keys, certificates and secrets of Authelia, WSO2 and Authentik are created on the first run by
# generate-idp-material.sh, into a generated/ folder beside each provider's configuration that Git ignores.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TEST="$ROOT/shared/test"
REPORTS="$TEST/reports"
RUNS="$REPORTS/runs"
STACK="$REPORTS/stack"
# The repository root as seen from a compose file in $STACK. Relative, because an absolute Windows path
# puts a drive-letter colon into a volume spec and compose misreads it, and because compose resolves
# relative paths against the first compose file's directory, which every file written here shares.
REL_ROOT="../../../.."
export MSYS_NO_PATHCONV=1

# On Windows "python3" can be a Store shortcut that only prints a message, so try each candidate.
PYTHON=""
for candidate in python3 python py; do
  if "$candidate" -c "import sys" >/dev/null 2>&1; then PYTHON="$candidate"; break; fi
done

# Per-implementation: base URL, database, and the command that reaches that database as a privileged user.
# One engine per implementation (dotnet on SQL Server, java on PostgreSQL, php on MySQL), so no two share a
# database and their conformance fixtures cannot interfere, which is what lets all three run at once.
DOTNET_URL="https://localhost:15443"; DOTNET_DB="mssql"
JAVA_URL="https://localhost:16443";   JAVA_DB="postgres"
PHP_URL="https://localhost:17443";    PHP_DB="mysql"
sql_command() {
  case "$1" in
    postgres) echo "docker exec -i trusted-attestation-dev-postgres-1 psql -q -t -A -U trusted_attestation -d trusted_attestation" ;;
    mysql)    echo "docker exec -i trusted-attestation-dev-mysql-1 mysql --silent --skip-column-names -u trusted_attestation -ptrusted-attestation-dev-password trusted_attestation" ;;
    mssql)    echo "docker exec -i trusted-attestation-dev-mssql-1 /opt/mssql-tools18/bin/sqlcmd -S localhost -U sa -P Trusted-Attestation-Dev-Password1 -d trusted_attestation -C -h -1 -W" ;;
  esac
}

log() { echo ">> $*"; }
die() { echo "error: $*" >&2; exit 1; }

# ---------------------------------------------------------------------------------------------------
# The shared dev stack: Keycloak and the three databases (docker-compose.yml alongside this script)
# ---------------------------------------------------------------------------------------------------

# Idempotent: `up -d` on containers already running does nothing. Keycloak is needed by every profile,
# not only the Keycloak one, because the Java app's base configuration wires its OIDC client to Keycloak
# and Spring resolves that issuer at boot even when the login runs over another IdP or over SAML.
start_shared_stack() {
  log "shared dev stack: Keycloak and the databases"
  ( cd "$TEST" && docker compose --profile postgres --profile mysql --profile mssql \
      up -d keycloak postgres mysql mssql >/dev/null 2>&1 ) || die "could not start the shared dev stack"

  # SQL Server's image creates no database beyond the system ones, so the first run has to create it. The
  # other two images create theirs from their environment.
  local created=""
  for _ in $(seq 1 60); do
    if docker exec trusted-attestation-dev-mssql-1 /opt/mssql-tools18/bin/sqlcmd \
         -S localhost -U sa -P 'Trusted-Attestation-Dev-Password1' -C \
         -Q "IF DB_ID('trusted_attestation') IS NULL CREATE DATABASE trusted_attestation;" >/dev/null 2>&1; then
      created=yes; break
    fi
    sleep 2
  done
  [[ -n "$created" ]] || die "SQL Server did not come up, so the .NET database could not be created"

  # `up -d` returns when the container starts, not when Keycloak is ready, and .NET checks IAM
  # reachability at startup and refuses to boot if the discovery document is not there yet.
  local iss="http://localhost:8081/realms/trusted-attestation-dev"
  for _ in $(seq 1 60); do
    [[ "$(curl -s -o /dev/null -w '%{http_code}' "$iss/.well-known/openid-configuration")" == "200" ]] && return 0
    sleep 5
  done
  die "Keycloak did not serve its discovery document at $iss"
}

# ---------------------------------------------------------------------------------------------------
# The app stack: .NET, Java and PHP containers (15443 / 16443 / 17443) behind one TLS proxy
# ---------------------------------------------------------------------------------------------------

# Written fresh on every run, so the stack always matches this script. The same pattern for all three
# apps: a socat gateway container owns the network namespace and forwards the host's IdP and database
# ports onto its own localhost, and the app joins that namespace, so "localhost:8081" (Keycloak),
# "localhost:9003" (Zitadel) and so on mean the same inside the container as in the browser. For OIDC
# that is not a nicety: the issuer an app validates has to be the name the browser was sent to, and the
# apps reach the IdP through the forward under exactly that name. The apps serve plain HTTP and Caddy
# terminates TLS in front of them with a certificate it issues itself, as the CI stack does, so no
# development certificate has to exist in the repository.
#
# The IAM settings of each app are overridable from the shell environment, which compose interpolates
# (${VAR:-default}). Each starter below scopes its exports to a subshell so nothing leaks from one profile
# to the next, and re-running `up -d <service>` with changed values makes compose recreate the container
# against the new IdP. Java's configuration is a file rather than environment variables, as in CI, because
# Spring's relaxed binding of locale-keyed maps and lists through the environment is fragile.
write_stack() {
  mkdir -p "$STACK"

  cat > "$STACK/Caddyfile" <<'CADDY'
# Written by integration-matrix.sh. One self-signed certificate, three virtual hosts, each forwarding to an
# app's plain HTTP port with X-Forwarded-Proto set, which is what terminated-by-proxy mode trusts.
{
	auto_https disable_redirects
}

localhost:15443 {
	tls internal
	reverse_proxy dotnet-gateway:8080
}

localhost:16443 {
	tls internal
	reverse_proxy java-gateway:8080
}

localhost:17443 {
	tls internal
	reverse_proxy php-gateway:8000
}
CADDY

  cat > "$STACK/application-java.yaml" <<'YAML'
# Written by integration-matrix.sh and mounted at /app/config/application.yaml. The hosts are localhost
# because the app shares its gateway's network namespace, where the host's ports are forwarded.
server:
  port: 8080

spring:
  datasource:
    url: jdbc:postgresql://localhost:55432/trusted_attestation
    username: trusted_attestation
    password: trusted-attestation-dev-password
  security:
    oauth2:
      client:
        provider:
          keycloak:
            issuer-uri: http://localhost:8081/realms/trusted-attestation-dev
            user-name-attribute: preferred_username
        registration:
          keycloak:
            client-id: trusted-attestation
            client-secret: trusted-attestation-dev-secret
            authorization-grant-type: authorization_code
            scope: openid, profile, email

trusted-attestation:
  tls:
    terminated-by-proxy: true

  database:
    provider: POSTGRESQL
    create-schema-on-startup: true

  manifest:
    manifest-version: 1
    default-locale: en
    name:
      en: Matrix Organisation
    description:
      en: The implementation under test
    website:
      en: https://localhost:16443/
    privacy-notice-url:
      en: https://localhost:16443/
    relationship-types:
      - employee
      - client
      - licensed_professional
    enrollment-url: https://localhost:16443/
    attestation-url: https://localhost:16443/api/v1/attest
YAML

  # Quoted heredoc: the ${VAR:-default} expressions are for compose, not for this shell. Paths are relative
  # to this file's directory (see REL_ROOT).
  cat > "$STACK/docker-compose.yml" <<'YAML'
# Written by integration-matrix.sh, which explains the design. Do not edit this copy.
name: trusted-attestation-matrix

# The host's IdP and database ports, republished on each app's localhost. Forwarding a port nothing
# answers on costs nothing until something connects, so every dev IdP and every engine is listed, and a
# cold start brings the forwards up with the gateway, before the app boots and tries to reach its IdP.
x-gateway: &gateway
  image: alpine/socat
  entrypoint: ["/bin/sh", "-c"]
  command:
    - |
      socat TCP-LISTEN:8081,fork,reuseaddr TCP:host.docker.internal:8081 &    # Keycloak
      socat TCP-LISTEN:9002,fork,reuseaddr TCP:host.docker.internal:9002 &    # Authentik
      socat TCP-LISTEN:9003,fork,reuseaddr TCP:host.docker.internal:9003 &    # Zitadel
      socat TCP-LISTEN:8010,fork,reuseaddr TCP:host.docker.internal:8010 &    # Casdoor
      socat TCP-LISTEN:9443,fork,reuseaddr TCP:host.docker.internal:9443 &    # WSO2
      socat TCP-LISTEN:9091,fork,reuseaddr TCP:host.docker.internal:9091 &    # Authelia
      socat TCP-LISTEN:9005,fork,reuseaddr TCP:host.docker.internal:9005 &    # SimpleSAMLphp
      socat TCP-LISTEN:55432,fork,reuseaddr TCP:host.docker.internal:55432 &  # PostgreSQL
      socat TCP-LISTEN:53306,fork,reuseaddr TCP:host.docker.internal:53306 &  # MySQL
      socat TCP-LISTEN:51433,fork,reuseaddr TCP:host.docker.internal:51433 &  # SQL Server
      wait
  extra_hosts:
    # Docker Desktop provides this name already, Linux does not.
    - "host.docker.internal:host-gateway"

services:
  dotnet-gateway: *gateway
  java-gateway: *gateway
  php-gateway: *gateway

  dotnet:
    image: ata-dotnet:matrix
    build:
      context: ../../../..
      dockerfile: dotnet/Dockerfile
    network_mode: "service:dotnet-gateway"
    depends_on: [dotnet-gateway]
    # The app refuses to start against an IdP it cannot reach, so the restart policy retries until the
    # IdP the profile points it at is answering.
    restart: on-failure
    volumes:
      # The dev SAML signing certificate, mounted rather than baked into the image so the image ships no
      # throwaway key. Mounted at the content-root path the SAML settings below resolve.
      - ../../../../dotnet/src/Astrana.TrustedAttestation.Server/saml:/app/saml:ro
    environment:
      ASPNETCORE_ENVIRONMENT: Production
      ASPNETCORE_URLS: http://+:8080
      TrustedAttestation__Tls__TerminatedByProxy: "true"
      TrustedAttestation__Database__Provider: SqlServer
      TrustedAttestation__Database__ConnectionString: "Server=localhost,51433;Database=trusted_attestation;User Id=sa;Password=Trusted-Attestation-Dev-Password1;TrustServerCertificate=True"
      TrustedAttestation__Database__CreateSchemaOnStartup: "true"
      # IAM, overridable from the host so the matrix can point this at any IdP or protocol.
      TrustedAttestation__Iam__Protocol: ${TrustedAttestation__Iam__Protocol:-Oidc}
      TrustedAttestation__Iam__Authority: ${TrustedAttestation__Iam__Authority:-http://localhost:8081/realms/trusted-attestation-dev}
      TrustedAttestation__Iam__ClientId: ${TrustedAttestation__Iam__ClientId:-trusted-attestation}
      TrustedAttestation__Iam__ClientSecret: ${TrustedAttestation__Iam__ClientSecret:-trusted-attestation-dev-secret}
      TrustedAttestation__Iam__RequireHttpsMetadata: "false"
      # The app's own default subject claim (OIDC "sub"). A SAML profile whose subject rides an attribute
      # rather than the NameID overrides it. Defaulting this to empty would break subject resolution: an
      # empty claim name resolves to no subject, and the member then reads as absent.
      TrustedAttestation__Iam__SubjectClaim: ${TrustedAttestation__Iam__SubjectClaim:-sub}
      TrustedAttestation__Iam__Saml__EntityId: https://localhost:15443/Saml2
      TrustedAttestation__Iam__Saml__IdentityProviderEntityId: ${TrustedAttestation__Iam__Saml__IdentityProviderEntityId:-}
      TrustedAttestation__Iam__Saml__IdentityProviderMetadataUrl: ${TrustedAttestation__Iam__Saml__IdentityProviderMetadataUrl:-}
      TrustedAttestation__Iam__Saml__SigningCertificatePath: saml/sp-dev.pfx
      TrustedAttestation__Iam__Saml__SigningCertificatePassword: changeit
      # Lets a profile mount an IdP's TLS certificate (WSO2, Authelia) for the app to trust on the
      # backchannel. Empty by default, since plain-HTTP IdPs need nothing. .NET on Linux honours it.
      SSL_CERT_FILE: ${SSL_CERT_FILE:-}
      TrustedAttestation__Manifest__ManifestVersion: "1"
      TrustedAttestation__Manifest__DefaultLocale: en
      TrustedAttestation__Manifest__Name__en: Matrix Organisation
      TrustedAttestation__Manifest__Description__en: The implementation under test
      TrustedAttestation__Manifest__Website__en: https://localhost:15443/
      TrustedAttestation__Manifest__PrivacyNoticeUrl__en: https://localhost:15443/
      TrustedAttestation__Manifest__RelationshipTypes__0: employee
      TrustedAttestation__Manifest__RelationshipTypes__1: client
      TrustedAttestation__Manifest__RelationshipTypes__2: licensed_professional
      TrustedAttestation__Manifest__EnrollmentUrl: https://localhost:15443/
      TrustedAttestation__Manifest__AttestationUrl: https://localhost:15443/api/v1/attest

  java:
    image: ata-java:matrix
    build:
      context: ../../../..
      dockerfile: java/Dockerfile
    network_mode: "service:java-gateway"
    depends_on: [java-gateway]
    restart: on-failure
    environment:
      SPRING_CONFIG_ADDITIONAL_LOCATION: /app/config/
    volumes:
      - ./application-java.yaml:/app/config/application.yaml:ro
      # The dev-saml profile and its signing material, mounted rather than packaged so the jar ships no
      # development configuration and no throwaway key. Spring reads the profile file from the additional
      # config location, and the profile resolves the key as file:saml/... against /app, the working directory.
      - ../../../../java/src/main/resources/application-dev-saml.yml:/app/config/application-dev-saml.yml:ro
      - ../../../../java/saml:/app/saml:ro

  php:
    image: ata-php:matrix
    build:
      context: ../../../..
      dockerfile: php/Dockerfile
    network_mode: "service:php-gateway"
    depends_on: [php-gateway]
    restart: on-failure
    volumes:
      # php/saml is excluded from the image (.dockerignore), so the SAML profiles find the SP signing
      # material here, at the base path TRUSTED_ATTESTATION_SAML_SP_CERTIFICATE=saml/sp-certificate.crt
      # resolves against.
      - ../../../../php/saml:/app/saml:ro
    environment:
      APP_URL: https://localhost:17443
      APP_KEY: base64:ZGVtby1rZXktMzItYnl0ZXMtYWFhYWFhYWFhYWFhYWE=
      TRUSTED_ATTESTATION_TLS_TERMINATED_BY_PROXY: "true"
      CACHE_STORE: file
      SESSION_DRIVER: file
      TRUSTED_ATTESTATION_IAM_PROTOCOL: ${TRUSTED_ATTESTATION_IAM_PROTOCOL:-oidc}
      TRUSTED_ATTESTATION_IAM_ISSUER: ${TRUSTED_ATTESTATION_IAM_ISSUER:-http://localhost:8081/realms/trusted-attestation-dev}
      TRUSTED_ATTESTATION_IAM_DISCOVERY_URL: ${TRUSTED_ATTESTATION_IAM_DISCOVERY_URL:-http://localhost:8081/realms/trusted-attestation-dev/.well-known/openid-configuration}
      TRUSTED_ATTESTATION_IAM_CLIENT_ID: ${TRUSTED_ATTESTATION_IAM_CLIENT_ID:-trusted-attestation}
      TRUSTED_ATTESTATION_IAM_CLIENT_SECRET: ${TRUSTED_ATTESTATION_IAM_CLIENT_SECRET:-trusted-attestation-dev-secret}
      TRUSTED_ATTESTATION_IAM_REQUIRE_HTTPS_METADATA: "false"
      DB_CONNECTION: mysql
      # 127.0.0.1 rather than localhost: PHP's MySQL driver reads "localhost" as the Unix socket, and the
      # forwarded port is TCP.
      DB_HOST: 127.0.0.1
      DB_PORT: "53306"
      DB_DATABASE: trusted_attestation
      DB_USERNAME: trusted_attestation
      DB_PASSWORD: trusted-attestation-dev-password
      TRUSTED_ATTESTATION_CREATE_SCHEMA: "true"
      TRUSTED_ATTESTATION_MANIFEST_DEFAULT_LOCALE: en
      TRUSTED_ATTESTATION_MANIFEST_NAME: '{"en":"Matrix Organisation"}'
      TRUSTED_ATTESTATION_MANIFEST_DESCRIPTION: '{"en":"The implementation under test"}'
      TRUSTED_ATTESTATION_MANIFEST_WEBSITE: '{"en":"https://localhost:17443/"}'
      TRUSTED_ATTESTATION_MANIFEST_PRIVACY_NOTICE_URL: '{"en":"https://localhost:17443/"}'
      TRUSTED_ATTESTATION_MANIFEST_RELATIONSHIP_TYPES: employee,client,licensed_professional
      TRUSTED_ATTESTATION_MANIFEST_ENROLLMENT_URL: https://localhost:17443/
      TRUSTED_ATTESTATION_MANIFEST_ATTESTATION_URL: https://localhost:17443/api/v1/attest

  proxy:
    image: caddy:2
    depends_on: [dotnet-gateway, java-gateway, php-gateway]
    ports:
      - "15443:15443"
      - "16443:16443"
      - "17443:17443"
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
YAML
}

# docker compose against the app stack, from its directory so every -f and volume path stays relative.
compose_stack() {
  ( cd "$STACK" && docker compose "$@" )
}

build_apps() {
  log "building the three app images from this repository (pass --no-build to reuse the last ones)"
  compose_stack build >/dev/null 2>&1 || die "an app image did not build; run 'docker compose build' in $STACK to see why"
  compose_stack up -d dotnet-gateway java-gateway php-gateway proxy >/dev/null 2>&1 || die "the gateways or the proxy did not start"
}

# Bring the .NET app container up. A profile that needs an extra compose layer (the WSO2 profile mounts
# the IdP's TLS certificate and points SSL_CERT_FILE at it, so the app trusts it on the backchannel) sets
# DOTNET_OVERRIDE to that file's name in $STACK, otherwise the base compose is used alone.
_dotnet_up() {
  if [[ -n "${DOTNET_OVERRIDE:-}" ]]; then
    compose_stack -f docker-compose.yml -f "$DOTNET_OVERRIDE" up -d dotnet >/dev/null 2>&1
  else
    compose_stack up -d dotnet >/dev/null 2>&1
  fi
}

# start_dotnet <authority> <client-id> <client-secret>  (OIDC)
start_dotnet() {
  ( export TrustedAttestation__Iam__Protocol="Oidc"
    export TrustedAttestation__Iam__Authority="$1"
    export TrustedAttestation__Iam__ClientId="$2"
    export TrustedAttestation__Iam__ClientSecret="$3"
    _dotnet_up )
}

# start_dotnet_saml <idp-entity-id> <idp-metadata-url> [subject-claim]  (SAML)
# The SP entity id (https://localhost:15443/Saml2) and dev signing certificate are the compose defaults, only
# the protocol and the IdP are set here. A subject claim is exported only when the subject rides an attribute
# rather than the NameID (Zitadel's UserID). Left unset, the app keeps its default "sub", which no SAML
# assertion carries and so falls back to the NameID, the subject for every NameID-based SAML profile.
start_dotnet_saml() {
  ( export TrustedAttestation__Iam__Protocol="Saml"
    export TrustedAttestation__Iam__Saml__IdentityProviderEntityId="$1"
    export TrustedAttestation__Iam__Saml__IdentityProviderMetadataUrl="$2"
    [[ -n "${3:-}" ]] && export TrustedAttestation__Iam__SubjectClaim="$3"
    _dotnet_up )
}

# start_java <issuer-uri> <client-id> <client-secret>  (empty issuer => Keycloak default, no override)
start_java() {
  if [[ -z "$1" ]]; then
    compose_stack up -d java >/dev/null 2>&1
  else
    cat > "$STACK/java-override.yml" <<YAML
services:
  java:
    environment:
      SPRING_SECURITY_OAUTH2_CLIENT_PROVIDER_KEYCLOAK_ISSUER_URI: "$1"
      SPRING_SECURITY_OAUTH2_CLIENT_REGISTRATION_KEYCLOAK_CLIENT_ID: "$2"
      SPRING_SECURITY_OAUTH2_CLIENT_REGISTRATION_KEYCLOAK_CLIENT_SECRET: "$3"
YAML
    compose_stack -f docker-compose.yml -f java-override.yml up -d java >/dev/null 2>&1
  fi
}

# start_php <issuer> <discovery-url> <client-id> <client-secret>  (empty issuer => Keycloak)
# The app reaches the IdP through its gateway's forward, at the same localhost port the browser uses, so
# the discovery URL is the issuer's own.
start_php() {
  if [[ -z "$1" ]]; then
    compose_stack up -d php >/dev/null 2>&1
  else
    cat > "$STACK/php-override.yml" <<YAML
services:
  php:
    environment:
      TRUSTED_ATTESTATION_IAM_ISSUER: "$1"
      TRUSTED_ATTESTATION_IAM_DISCOVERY_URL: "$2"
      TRUSTED_ATTESTATION_IAM_CLIENT_ID: "$3"
      TRUSTED_ATTESTATION_IAM_CLIENT_SECRET: "$4"
YAML
    compose_stack -f docker-compose.yml -f php-override.yml up -d php >/dev/null 2>&1
  fi
}

# Java and PHP with a profile-specific override file already written to $STACK.
start_java_with() { compose_stack -f docker-compose.yml -f "$1" up -d java >/dev/null 2>&1; }
start_php_with()  { compose_stack -f docker-compose.yml -f "$1" up -d php  >/dev/null 2>&1; }

app_ready() {  # is the app at $1 serving its manifest right now?
  [[ "$(curl -sk -o /dev/null -w '%{http_code}' "$1/.well-known/ata-manifest.json" 2>/dev/null)" == "200" ]]
}

# Whether a cell's result stands (no retry) or is an infrastructure flake to re-roll. A browser login can
# flake under the contention of the three implementations running at once, in two shapes, and both are
# retried: the first login never completes so check.py aborts the run ("aborted" set, session-dependent
# sections skipped), OR a later login flakes while the run otherwise completes, leaving a failure whose
# detail is exactly "login failed". Anything else stands and is reported at once: a clean pass, or a
# behavioural divergence (a check that failed after a successful login, whose detail is never "login
# failed"). This is why the retry cannot mask a real divergence, while still absorbing a login flake
# wherever it lands. A genuine login regression is not hidden either, it fails every retry and then
# stands. No JSON at all (a crashed or hung browser) is likewise retried.
run_stands() {  # $1 = json path relative to the working directory; success => the result is real and should not be retried
  [[ -s "$1" ]] || return 1
  "$PYTHON" - "$1" <<'PY' 2>/dev/null
import json, sys
d = json.load(open(sys.argv[1]))
if d.get("aborted"):
    sys.exit(1)                                   # first login never completed -> retry
fails = [c for c in d.get("checks", []) if c.get("status") == "fail"]
if not fails:
    sys.exit(0)                                   # clean pass -> stands
# Stands only if some failure is a real divergence, not merely a login that flaked mid-run.
sys.exit(0 if any(c.get("detail") != "login failed" for c in fails) else 1)
PY
}

wait_healthy() {  # wait for all three apps
  for url in "$DOTNET_URL" "$JAVA_URL" "$PHP_URL"; do
    for _ in $(seq 1 40); do
      app_ready "$url" && break
      sleep 3
    done
  done
}

# ---------------------------------------------------------------------------------------------------
# IdP profiles: bring the IdP up, point the three apps at it, and set BROWSER for its login kind.
# ---------------------------------------------------------------------------------------------------

BROWSER=""  # set to "--browser-login" for a JavaScript sign-in

# The IdP stacks live alongside this script: zitadel/, authentik/, casdoor/, wso2/, authelia/ and
# simplesamlphp/, each with its own docker-compose.yml, and the configure scripts are run from $ROOT by a
# relative path, because Git Bash rewrites an absolute /d/... path handed to native Python into a broken
# D:\d\... one while a relative path is left alone.

configure_keycloak() {
  BROWSER=""
  # Keycloak is up and serving discovery already (start_shared_stack), so only the apps move.
  local iss="http://localhost:8081/realms/trusted-attestation-dev"
  start_dotnet "$iss" "trusted-attestation" "trusted-attestation-dev-secret"
  start_java "" "" ""
  start_php "" "" "" ""
}

configure_zitadel() {
  BROWSER="--browser-login"
  ( cd "$TEST/zitadel" && docker compose up -d >/dev/null 2>&1 )
  for _ in $(seq 1 40); do [[ "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:9003/.well-known/openid-configuration)" == "200" ]] && break; sleep 5; done
  local out; out="$(cd "$ROOT" && "$PYTHON" shared/test/zitadel/configure.py)"
  local cid cs; cid="$(echo "$out" | sed -n 's/^client_id: *//p')"; cs="$(echo "$out" | sed -n 's/^client_secret: *//p')"
  [[ -n "$cid" && -n "$cs" ]] || die "could not read Zitadel client id/secret from configure.py"
  start_dotnet "http://localhost:9003" "$cid" "$cs"
  start_java "http://localhost:9003" "$cid" "$cs"
  start_php "http://localhost:9003" "http://localhost:9003/.well-known/openid-configuration" "$cid" "$cs"
}

# Authentik's readiness probe answers 204 in some releases and 200 in others (2024.10 answers 200), and
# either means ready.
wait_for_authentik() {
  for _ in $(seq 1 60); do
    [[ "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:9002/-/health/ready/)" =~ ^20[04]$ ]] && return 0
    sleep 5
  done
}

configure_authentik() {
  BROWSER="--browser-login"
  ( cd "$TEST/authentik" && docker compose up -d >/dev/null 2>&1 )
  wait_for_authentik
  ( cd "$ROOT" && "$PYTHON" shared/test/authentik/configure.py >/dev/null )
  local iss="http://localhost:9002/application/o/trusted-attestation/"  # trailing slash: Authentik's issuer carries one
  start_dotnet "$iss" "trusted-attestation" "trusted-attestation-dev-secret"
  start_java "$iss" "trusted-attestation" "trusted-attestation-dev-secret"
  start_php "$iss" "${iss}.well-known/openid-configuration" "trusted-attestation" "trusted-attestation-dev-secret"
}

configure_casdoor() {
  BROWSER="--browser-login"   # Casdoor's login page is a React SPA, like Zitadel/Authentik
  ( cd "$TEST/casdoor" && docker compose up -d >/dev/null 2>&1 )
  # Casdoor races its own database on a cold start (it connects before Postgres accepts and panics). The
  # container is restart-on-failure via compose, but nudge it and wait for the API to answer.
  for _ in $(seq 1 40); do
    [[ "$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:8010/api/get-organizations?owner=admin")" =~ ^(200|401|403)$ ]] && break
    ( cd "$TEST/casdoor" && docker compose up -d casdoor >/dev/null 2>&1 ); sleep 5
  done
  local out; out="$(cd "$ROOT" && "$PYTHON" shared/test/casdoor/configure.py)"
  local cid cs; cid="$(echo "$out" | sed -n 's/^client_id: *//p')"; cs="$(echo "$out" | sed -n 's/^client_secret: *//p')"
  [[ -n "$cid" && -n "$cs" ]] || die "could not read Casdoor client id/secret from configure.py"
  local iss="http://localhost:8010"
  start_dotnet "$iss" "$cid" "$cs"
  start_java "$iss" "$cid" "$cs"
  start_php "$iss" "$iss/.well-known/openid-configuration" "$cid" "$cs"
}

# WSO2 Identity Server over OIDC. Browser login (its sign-in page is a JavaScript app). Unlike the plain-HTTP
# dev IdPs, WSO2 serves discovery, token, JWKS, and PAR over HTTPS with a self-signed certificate, so each app
# has to trust that certificate on the backchannel or every metadata fetch fails: the .NET container via
# SSL_CERT_FILE (OpenSSL), Java via a JVM truststore, PHP via php.ini's curl.cainfo, all pointing at the same
# certificate, which generate-idp-material.sh creates under wso2/generated/, mounted into each container by a
# path relative to the stack directory. The subject is the SCIM externalId, which configure.py pins to the fixture UUID, so no
# subject-claim override is needed.
configure_wso2() {
  BROWSER="--browser-login"
  ( cd "$TEST/wso2" && docker compose up -d --build >/dev/null 2>&1 )
  for _ in $(seq 1 60); do [[ "$(curl -sk -o /dev/null -w '%{http_code}' https://localhost:9443/api/health-check/v1.0/health)" == "200" ]] && break; sleep 5; done
  local out; out="$(cd "$ROOT" && "$PYTHON" shared/test/wso2/configure.py)"
  local cid cs; cid="$(echo "$out" | sed -n 's/^client_id: *//p')"; cs="$(echo "$out" | sed -n 's/^client_secret: *//p')"
  [[ -n "$cid" && -n "$cs" ]] || die "could not read WSO2 client id/secret from configure.py"
  local iss="https://localhost:9443/oauth2/token"

  # The certificate (and Java's truststore built from it), by a path relative to the stack directory.
  local crt="$REL_ROOT/shared/test/wso2/generated/wso2-tls.crt"
  local jks="$REL_ROOT/shared/test/wso2/generated/truststore.p12"

  cat > "$STACK/dotnet-override.yml" <<YAML
services:
  dotnet:
    environment:
      SSL_CERT_FILE: /wso2/tls.crt
    volumes:
      - $crt:/wso2/tls.crt:ro
YAML
  ( export DOTNET_OVERRIDE="dotnet-override.yml"; start_dotnet "$iss" "$cid" "$cs" )

  cat > "$STACK/java-override.yml" <<YAML
services:
  java:
    environment:
      SPRING_SECURITY_OAUTH2_CLIENT_PROVIDER_KEYCLOAK_ISSUER_URI: "$iss"
      SPRING_SECURITY_OAUTH2_CLIENT_REGISTRATION_KEYCLOAK_CLIENT_ID: "$cid"
      SPRING_SECURITY_OAUTH2_CLIENT_REGISTRATION_KEYCLOAK_CLIENT_SECRET: "$cs"
      JAVA_TOOL_OPTIONS: "-Djavax.net.ssl.trustStore=/wso2/truststore.p12 -Djavax.net.ssl.trustStorePassword=changeit -Djavax.net.ssl.trustStoreType=PKCS12"
    volumes:
      - $jks:/wso2/truststore.p12:ro
YAML
  start_java_with java-override.yml

  # PHP trusts the certificate through php.ini's curl.cainfo/openssl.cafile, which Laravel's HTTP client
  # (Guzzle over libcurl) honours, unlike the CURL_CA_BUNDLE environment variable, which it ignores.
  cat > "$STACK/php-override.yml" <<YAML
services:
  php:
    environment:
      TRUSTED_ATTESTATION_IAM_ISSUER: "$iss"
      TRUSTED_ATTESTATION_IAM_DISCOVERY_URL: "$iss/.well-known/openid-configuration"
      TRUSTED_ATTESTATION_IAM_CLIENT_ID: "$cid"
      TRUSTED_ATTESTATION_IAM_CLIENT_SECRET: "$cs"
    volumes:
      - $crt:/wso2/tls.crt:ro
    command: ["php", "-d", "curl.cainfo=/wso2/tls.crt", "-d", "openssl.cafile=/wso2/tls.crt", "-S", "0.0.0.0:8000", "-t", "public", "server.php"]
YAML
  start_php_with php-override.yml
}

# Authelia over OIDC. Browser login (its portal is a JavaScript app). Authelia refuses a bare-localhost
# cookie domain and requires an HTTPS portal, so it is served over TLS on the loopback wildcard domain
# authelia.127.0.0.1.nip.io (see authelia/ alongside this script), and each app trusts that certificate on
# the backchannel exactly as in the WSO2 profile (.NET SSL_CERT_FILE, Java JVM truststore, PHP php.ini
# curl.cainfo). Authelia's `sub` is an opaque generated id that cannot be pinned to the fixture UUID, so the
# UUID rides a custom `ata_subject` claim: each member carries it as an extra attribute, a custom `ata` scope
# grants it, and every app requests that scope and takes its subject from that claim. No configure script:
# the members and client are static in authelia/configuration.yml.
configure_authelia() {
  BROWSER="--browser-login"
  ( cd "$TEST/authelia" && docker compose up -d >/dev/null 2>&1 )
  local iss="https://authelia.127.0.0.1.nip.io:9091"
  for _ in $(seq 1 40); do [[ "$(curl -sk -o /dev/null -w '%{http_code}' "$iss/.well-known/openid-configuration")" == "200" ]] && break; sleep 3; done
  # One client per app (Authelia enforces a single token-endpoint auth method per client and the apps
  # disagree, see authelia/configuration.yml). The secret is shared.
  local cs="ata-authelia-secret-change-in-real-deployments"

  local crt="$REL_ROOT/shared/test/authelia/generated/tls.crt"
  local jks="$REL_ROOT/shared/test/authelia/generated/truststore.p12"

  cat > "$STACK/dotnet-override.yml" <<YAML
services:
  dotnet:
    environment:
      SSL_CERT_FILE: /authelia/tls.crt
      TrustedAttestation__Iam__SubjectClaim: ata_subject
      TrustedAttestation__Iam__AdditionalScopes__0: ata
    volumes:
      - $crt:/authelia/tls.crt:ro
YAML
  ( export DOTNET_OVERRIDE="dotnet-override.yml"; start_dotnet "$iss" "ata-authelia-dotnet" "$cs" )

  cat > "$STACK/java-override.yml" <<YAML
services:
  java:
    environment:
      TRUSTED_ATTESTATION_IAM_SUBJECT_CLAIM: "ata_subject"
      JAVA_TOOL_OPTIONS: "-Djavax.net.ssl.trustStore=/authelia/truststore.p12 -Djavax.net.ssl.trustStorePassword=changeit -Djavax.net.ssl.trustStoreType=PKCS12"
      SPRING_SECURITY_OAUTH2_CLIENT_PROVIDER_KEYCLOAK_ISSUER_URI: "$iss"
      SPRING_SECURITY_OAUTH2_CLIENT_REGISTRATION_KEYCLOAK_CLIENT_ID: "ata-authelia-java"
      SPRING_SECURITY_OAUTH2_CLIENT_REGISTRATION_KEYCLOAK_CLIENT_SECRET: "$cs"
      SPRING_SECURITY_OAUTH2_CLIENT_REGISTRATION_KEYCLOAK_SCOPE: "openid,profile,email,ata"
    volumes:
      - $jks:/authelia/truststore.p12:ro
YAML
  start_java_with java-override.yml

  cat > "$STACK/php-override.yml" <<YAML
services:
  php:
    environment:
      TRUSTED_ATTESTATION_IAM_ISSUER: "$iss"
      TRUSTED_ATTESTATION_IAM_DISCOVERY_URL: "$iss/.well-known/openid-configuration"
      TRUSTED_ATTESTATION_IAM_CLIENT_ID: "ata-authelia-php"
      TRUSTED_ATTESTATION_IAM_CLIENT_SECRET: "$cs"
      TRUSTED_ATTESTATION_IAM_SUBJECT_CLAIM: "ata_subject"
      TRUSTED_ATTESTATION_IAM_SCOPES: "ata"
    volumes:
      - $crt:/authelia/tls.crt:ro
    command: ["php", "-d", "curl.cainfo=/authelia/tls.crt", "-d", "openssl.cafile=/authelia/tls.crt", "-S", "0.0.0.0:8000", "-t", "public", "server.php"]
YAML
  start_php_with php-override.yml
}

# The SAML profiles start the apps differently from start_dotnet/java/php, which speak OIDC. Java runs its
# dev-saml profile on top of the stack's configuration file: the profile supplies the protocol, the SP
# entity id and the SP signing credentials packaged in the jar, and the file supplies the database, the
# proxy-terminated TLS and the manifest, so the "dev" profile (which would bring its own TLS keystore and
# port) is not activated. SPRING_APPLICATION_JSON carries the metadata URL because the property is nested
# past what a single relaxed-binding env var expresses cleanly. PHP reads the SP certificate and key from
# the php/saml mount in the base compose.

# SimpleSAMLphp is the plain SAML profile. Form login, so no --browser-login. Every app points at the same
# IdP metadata. The entity ID and SSO URLs inside it are localhost:9005 (fixed by the IdP's baseurlpath),
# reachable from the browser directly and from the containers through their gateways.
configure_simplesamlphp() {
  BROWSER=""
  ( cd "$TEST/simplesamlphp" && docker compose up -d --build >/dev/null 2>&1 )
  local meta="http://localhost:9005/saml2/idp/metadata.php"
  for _ in $(seq 1 40); do [[ "$(curl -s -o /dev/null -w '%{http_code}' "$meta")" == "200" ]] && break; sleep 3; done

  # .NET (SAML mode). SimpleSAMLphp's entity id is its metadata URL.
  start_dotnet_saml "$meta" "$meta"

  cat > "$STACK/java-override.yml" <<YAML
services:
  java:
    environment:
      SPRING_PROFILES_ACTIVE: "dev-saml"
      SPRING_APPLICATION_JSON: '{"spring":{"security":{"saml2":{"relyingparty":{"registration":{"keycloak":{"assertingparty":{"metadata-uri":"$meta"}}}}}}}}'
YAML
  start_java_with java-override.yml

  cat > "$STACK/php-override.yml" <<YAML
services:
  php:
    environment:
      TRUSTED_ATTESTATION_IAM_PROTOCOL: saml
      TRUSTED_ATTESTATION_SAML_IDP_METADATA_URL: $meta
      TRUSTED_ATTESTATION_SAML_ENTITY_ID: https://localhost:17443/auth/saml/metadata
      TRUSTED_ATTESTATION_SAML_SP_CERTIFICATE: saml/sp-certificate.crt
      TRUSTED_ATTESTATION_SAML_SP_PRIVATE_KEY: saml/sp-private.key
YAML
  start_php_with php-override.yml
}

# Zitadel over SAML rather than OIDC. Browser login (Zitadel's sign-in is a JavaScript app). The subject
# the members are granted against is Zitadel's user id, which over SAML it carries in a UserID attribute
# rather than the NameID (the login name), so every implementation sets its subject-claim to UserID here.
configure_zitadel_saml() {
  BROWSER="--browser-login"
  ( cd "$TEST/zitadel" && docker compose up -d >/dev/null 2>&1 )
  for _ in $(seq 1 40); do [[ "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:9003/.well-known/openid-configuration)" == "200" ]] && break; sleep 5; done
  ( cd "$ROOT" && "$PYTHON" shared/test/zitadel/configure.py >/dev/null )          # members (userId = fixture UUID)
  ( cd "$ROOT" && "$PYTHON" shared/test/zitadel/register-saml-sps.py >/dev/null )  # the three SPs as SAML apps
  local meta="http://localhost:9003/saml/v2/metadata"

  # The subject rides Zitadel's UserID attribute over SAML, not the NameID, so the subject claim is set.
  start_dotnet_saml "$meta" "$meta" "UserID"

  cat > "$STACK/java-override.yml" <<YAML
services:
  java:
    environment:
      SPRING_PROFILES_ACTIVE: "dev-saml"
      TRUSTED_ATTESTATION_IAM_SUBJECT_CLAIM: "UserID"
      SPRING_APPLICATION_JSON: '{"spring":{"security":{"saml2":{"relyingparty":{"registration":{"keycloak":{"assertingparty":{"metadata-uri":"$meta"}}}}}}}}'
YAML
  start_java_with java-override.yml

  # Zitadel serves only on the domain it was configured with (localhost), which is what the gateway's
  # forward presents.
  cat > "$STACK/php-override.yml" <<YAML
services:
  php:
    environment:
      TRUSTED_ATTESTATION_IAM_PROTOCOL: saml
      TRUSTED_ATTESTATION_IAM_SUBJECT_CLAIM: UserID
      TRUSTED_ATTESTATION_SAML_IDP_METADATA_URL: $meta
      TRUSTED_ATTESTATION_SAML_ENTITY_ID: https://localhost:17443/auth/saml/metadata
      TRUSTED_ATTESTATION_SAML_SP_CERTIFICATE: saml/sp-certificate.crt
      TRUSTED_ATTESTATION_SAML_SP_PRIVATE_KEY: saml/sp-private.key
YAML
  start_php_with php-override.yml
}

# Authentik over SAML rather than OIDC. Browser login. Authentik's SAML is per-application, so each
# implementation points at its own provider's metadata (ata-saml-<impl>). No subject-claim override is
# needed: a NameID property mapping pins the NameID, which is the subject, to the fixture UUID.
configure_authentik_saml() {
  BROWSER="--browser-login"
  ( cd "$TEST/authentik" && docker compose up -d >/dev/null 2>&1 )
  wait_for_authentik
  ( cd "$ROOT" && "$PYTHON" shared/test/authentik/configure.py >/dev/null )
  ( cd "$ROOT" && "$PYTHON" shared/test/authentik/configure-saml.py >/dev/null )

  start_dotnet_saml "authentik" "http://localhost:9002/application/saml/ata-saml-dotnet/metadata/"

  cat > "$STACK/java-override.yml" <<YAML
services:
  java:
    environment:
      SPRING_PROFILES_ACTIVE: "dev-saml"
      SPRING_APPLICATION_JSON: '{"spring":{"security":{"saml2":{"relyingparty":{"registration":{"keycloak":{"assertingparty":{"metadata-uri":"http://localhost:9002/application/saml/ata-saml-java/metadata/"}}}}}}}}'
YAML
  start_java_with java-override.yml

  cat > "$STACK/php-override.yml" <<YAML
services:
  php:
    environment:
      TRUSTED_ATTESTATION_IAM_PROTOCOL: saml
      TRUSTED_ATTESTATION_SAML_IDP_METADATA_URL: http://localhost:9002/application/saml/ata-saml-php/metadata/
      TRUSTED_ATTESTATION_SAML_ENTITY_ID: https://localhost:17443/auth/saml/metadata
      TRUSTED_ATTESTATION_SAML_SP_CERTIFICATE: saml/sp-certificate.crt
      TRUSTED_ATTESTATION_SAML_SP_PRIVATE_KEY: saml/sp-private.key
YAML
  start_php_with php-override.yml
}

# WSO2 over SAML rather than OIDC. Browser login. The apps fetch WSO2's IdP metadata over HTTPS, so this
# carries the same certificate trust as the OIDC WSO2 profile (.NET SSL_CERT_FILE, Java JVM truststore, PHP
# php.ini curl.cainfo). The SAML NameID is the SCIM externalId = the fixture UUID (configure-saml.py pins the
# SP subject to it), so the apps' subject claim falls back to the NameID and no override is needed. WSO2
# signs the response with a SHA-256 digest, which .NET's Sustainsys requires, but only because the SP's
# SAML config is kept minimal (see configure-saml.py for the SHA-1 fallback that fuller configs trigger).
configure_wso2_saml() {
  BROWSER="--browser-login"
  ( cd "$TEST/wso2" && docker compose up -d --build >/dev/null 2>&1 )
  for _ in $(seq 1 60); do [[ "$(curl -sk -o /dev/null -w '%{http_code}' https://localhost:9443/api/health-check/v1.0/health)" == "200" ]] && break; sleep 5; done
  ( cd "$ROOT" && "$PYTHON" shared/test/wso2/configure.py >/dev/null )        # members (externalId = fixture UUID)
  ( cd "$ROOT" && "$PYTHON" shared/test/wso2/configure-saml.py >/dev/null )   # the three SPs as SAML apps
  local meta="https://localhost:9443/identity/metadata/saml2"

  # The certificate (and Java's truststore), by a path relative to the stack directory (see configure_wso2).
  local crt="$REL_ROOT/shared/test/wso2/generated/wso2-tls.crt"
  local jks="$REL_ROOT/shared/test/wso2/generated/truststore.p12"

  cat > "$STACK/dotnet-override.yml" <<YAML
services:
  dotnet:
    environment:
      SSL_CERT_FILE: /wso2/tls.crt
    volumes:
      - $crt:/wso2/tls.crt:ro
YAML
  ( export DOTNET_OVERRIDE="dotnet-override.yml"; start_dotnet_saml "localhost" "$meta" )

  cat > "$STACK/java-override.yml" <<YAML
services:
  java:
    environment:
      SPRING_PROFILES_ACTIVE: "dev-saml"
      JAVA_TOOL_OPTIONS: "-Djavax.net.ssl.trustStore=/wso2/truststore.p12 -Djavax.net.ssl.trustStorePassword=changeit -Djavax.net.ssl.trustStoreType=PKCS12"
      SPRING_APPLICATION_JSON: '{"spring":{"security":{"saml2":{"relyingparty":{"registration":{"keycloak":{"assertingparty":{"metadata-uri":"$meta"}}}}}}}}'
    volumes:
      - $jks:/wso2/truststore.p12:ro
YAML
  start_java_with java-override.yml

  cat > "$STACK/php-override.yml" <<YAML
services:
  php:
    environment:
      TRUSTED_ATTESTATION_IAM_PROTOCOL: saml
      TRUSTED_ATTESTATION_SAML_IDP_METADATA_URL: $meta
      TRUSTED_ATTESTATION_SAML_ENTITY_ID: https://localhost:17443/auth/saml/metadata
      TRUSTED_ATTESTATION_SAML_SP_CERTIFICATE: saml/sp-certificate.crt
      TRUSTED_ATTESTATION_SAML_SP_PRIVATE_KEY: saml/sp-private.key
    volumes:
      - $crt:/wso2/tls.crt:ro
    command: ["php", "-d", "curl.cainfo=/wso2/tls.crt", "-d", "openssl.cafile=/wso2/tls.crt", "-S", "0.0.0.0:8000", "-t", "public", "server.php"]
YAML
  start_php_with php-override.yml
}

# ---------------------------------------------------------------------------------------------------
# Running a column and building the report
# ---------------------------------------------------------------------------------------------------

# run_one <idp> <impl> <base-url> <db>
run_one() {
  local idp="$1" impl="$2" url="$3" db="$4"
  log "$idp / $impl ($db)"
  # No schema reset here: the app created the schema on boot (configure_* started it), and check.py grants
  # and clears its own fixtures. Resetting now, after the app is up, would drop the tables out from under
  # it, the trap this whole suite has hit before.
  #
  # Run from $ROOT with relative script paths: Git Bash rewrites an absolute /d/... path passed to native
  # Python into a broken D:\d\... one, while a relative path is left alone (MSYS_NO_PATHCONV still guards
  # the /opt/... sqlcmd path inside the --sql-command string).
  local json="shared/test/reports/runs/${idp}-${impl}.json"
  local txt="shared/test/reports/runs/${idp}-${impl}.txt"
  # Start each cell with no stale JSON, so "did check.py finish?" below reflects THIS run, not a JSON left
  # by an earlier invocation.
  rm -f "$ROOT/$json"

  # A browser-login cell drives a real headed browser, and the three implementations run at once, so a
  # login can flake under the contention. So a browser-login cell gets up to three attempts, each preceded
  # by a readiness re-check of just this app (wait_healthy gated all three before the column, but an idle
  # app can stop answering under load) and, on a retry, a settle to let the previous browser tear down. An
  # attempt is retried only when its result is an infrastructure flake: no JSON (crashed browser), an
  # aborted run, or a completed run whose only failures are login flakes (see run_stands). A clean pass or a
  # real behavioural divergence stands at once and is never re-rolled. Form-login cells (BROWSER empty) are
  # fast and need none of this: one attempt, no re-check, no settle.
  local attempts=1; [[ -n "$BROWSER" ]] && attempts=3
  local attempt
  for attempt in $(seq 1 "$attempts"); do
    if [[ -n "$BROWSER" ]]; then
      for _ in $(seq 1 20); do app_ready "$url" && break; sleep 3; done
    fi
    local _t0; _t0=$(date +%s)
    # Hard wall-clock cap per attempt (check.py bounds its own HTTP requests, but this also catches a
    # wedged browser op or anything else): a browser-login cell is normally under ~5 minutes, so 15 kills
    # a genuine hang, leaving no JSON, which run_stands treats as a flake to retry.
    ( cd "$ROOT" && MSYS_NO_PATHCONV=1 timeout 900 "$PYTHON" -u shared/test/conformance/check.py \
        --base-url "$url" --sql-command "$(sql_command "$db")" --database "$db" $BROWSER \
        --idp "$idp" --implementation "$impl" --json "$json" \
        > "$txt" 2>&1 )
    log "   [timing] $idp/$impl attempt $attempt took $(($(date +%s)-_t0))s"
    # From $ROOT with the relative path, for the same reason as check.py above: run_stands hands the path
    # to native Python, which cannot open an absolute /d/... path while MSYS_NO_PATHCONV is set.
    { ( cd "$ROOT" && run_stands "$json" ) || [[ "$attempt" -ge "$attempts" ]]; } && break
    log "   browser-login flaked (attempt $attempt); retrying $idp / $impl after a settle"
    sleep 15
  done
  ( cd "$ROOT" && "$PYTHON" -c "import json;d=json.load(open('$json'));print('   ',d['passed'],'passed,',d['failed'],'failed')" 2>/dev/null ) \
    || echo "    (run produced no JSON, see $RUNS/${idp}-${impl}.txt)"
}

# Stop the optional heavy IdP stacks this profile does not need, so only one is resident at a time. The
# configure_* functions only ever `up -d`, so without this the stacks accumulate across a multi-profile run:
# by the last profile every IdP (Authentik alone is ~1GB across four containers) is up at once on top of
# all three apps and the browser, and the browser logins are starved into timing out. This is the isolation
# the file header promises. Keycloak and the databases are the shared base (the Java app needs Keycloak
# reachable to boot whichever IdP the login runs over) so they are never stopped here. `stop` (not `down`)
# keeps the containers so the next profile's `up -d` restarts them fast.
idle_idps() {  # $1 = the one optional stack to keep up (authentik|zitadel|simplesamlphp|...), or "" for none
  local keep="$1" stack
  for stack in authentik zitadel simplesamlphp casdoor wso2 authelia; do
    [[ "$stack" == "$keep" ]] && continue
    ( cd "$TEST/$stack" && docker compose stop >/dev/null 2>&1 )
  done
}

run_idp() {
  local idp="$1"
  # Bring only this profile's IdP down to one resident optional stack before configuring it (see idle_idps).
  case "$idp" in
    keycloak)       idle_idps "";            configure_keycloak ;;
    zitadel)        idle_idps zitadel;       configure_zitadel ;;
    authentik)      idle_idps authentik;     configure_authentik ;;
    simplesamlphp)  idle_idps simplesamlphp; configure_simplesamlphp ;;
    casdoor)        idle_idps casdoor;       configure_casdoor ;;
    wso2)           idle_idps wso2;          configure_wso2 ;;
    authelia)       idle_idps authelia;      configure_authelia ;;
    zitadel-saml)   idle_idps zitadel;       configure_zitadel_saml ;;
    authentik-saml) idle_idps authentik;     configure_authentik_saml ;;
    wso2-saml)      idle_idps wso2;          configure_wso2_saml ;;
    *) die "unknown idp '$idp' (keycloak, zitadel, authentik, simplesamlphp, casdoor, wso2, authelia, zitadel-saml, authentik-saml, wso2-saml)" ;;
  esac
  wait_healthy

  # Run the three implementations concurrently, grouped by database. check.py grants and clears its
  # fixtures in the app's own database, so two implementations that shared one database would have to run
  # serially or they would corrupt each other's fixtures. Today each is on its own engine, so the three
  # groups are one implementation each and all run at once. Each cell's progress is buffered to its own log
  # so concurrent output does not interleave, then printed in a stable implementation order once all groups
  # finish.
  local specs=("dotnet $DOTNET_URL $DOTNET_DB" "java $JAVA_URL $JAVA_DB" "php $PHP_URL $PHP_DB")
  local dbs=() spec db pids=()
  for spec in "${specs[@]}"; do
    set -- $spec
    [[ -n "$ONLY" && ",$ONLY," != *",$1,"* ]] && continue
    [[ " ${dbs[*]} " == *" $3 "* ]] || dbs+=("$3")   # distinct databases, first-seen order
  done
  for db in "${dbs[@]}"; do
    ( for spec in "${specs[@]}"; do
        set -- $spec
        { [[ -n "$ONLY" && ",$ONLY," != *",$1,"* ]] || [[ "$3" != "$db" ]]; } && continue
        run_one "$idp" "$1" "$2" "$3" > "$RUNS/${idp}-${1}.cell.log" 2>&1
      done ) &
    pids+=($!)
  done
  [[ ${#pids[@]} -gt 0 ]] && wait "${pids[@]}"

  for spec in "${specs[@]}"; do
    set -- $spec
    [[ -f "$RUNS/${idp}-${1}.cell.log" ]] || continue
    cat "$RUNS/${idp}-${1}.cell.log"; rm -f "$RUNS/${idp}-${1}.cell.log"
  done
}

build_report() {
  ( cd "$ROOT"
    shopt -s nullglob
    local jsons=(shared/test/reports/runs/*.json)
    [[ ${#jsons[@]} -gt 0 ]] || die "no run JSON in shared/test/reports/runs to report on"
    "$PYTHON" shared/test/integration-report.py "${jsons[@]}" --out shared/test/reports/integration-report \
      --title "Astrana Trusted Attestation Integration Test Report"
  )
  log "report: $REPORTS/integration-report.html (+ .md)"
}

# ---------------------------------------------------------------------------------------------------

main() {
  local idps=() ONLY="" build=yes
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --idp) idps+=("$2"); shift 2 ;;
      --all) idps=(keycloak zitadel authentik simplesamlphp); shift ;;
      --only) ONLY="$2"; shift 2 ;;
      --no-build) build=""; shift ;;
      --report-only) shift ;;  # just rebuild from existing JSON
      -h|--help) sed -n '2,29p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
      *) die "unknown argument: $1" ;;
    esac
  done
  mkdir -p "$RUNS"
  [[ -f "$REPORTS/.gitignore" ]] || printf "*\n" > "$REPORTS/.gitignore"

  export ONLY
  if [[ ${#idps[@]} -gt 0 ]]; then
    [[ -n "$PYTHON" ]] || die "python is not installed"
    command -v docker >/dev/null 2>&1 || die "docker is not installed"
    # The identity providers' keys, certificates and secrets are not in the repository. Create whichever are missing
    # before any profile, so every provider's stack can be started, and stopped by idle_idps, from the first run.
    bash "$TEST/generate-idp-material.sh" >/dev/null || die "could not generate the identity providers' keys and secrets"
    start_shared_stack
    write_stack
    if [[ -n "$build" ]]; then build_apps; else compose_stack up -d dotnet-gateway java-gateway php-gateway proxy >/dev/null 2>&1; fi
    for idp in "${idps[@]}"; do run_idp "$idp"; done
  fi
  build_report
}

main "$@"
