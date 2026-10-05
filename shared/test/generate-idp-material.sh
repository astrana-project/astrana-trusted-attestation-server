#!/usr/bin/env bash
#
# Creates the keys, certificates and secrets the local identity providers need, so none of them is kept in the
# repository. Each provider's material goes into a generated/ folder inside its own folder here, which Git ignores.
# Anything already there is reused and anything missing is created, so running it again changes nothing.
#
#   shared/test/generate-idp-material.sh                    # every provider below
#   shared/test/generate-idp-material.sh authelia wso2      # only the ones named
#
# Providers: authelia, wso2, authentik. integration-matrix.sh runs it before every profile. Run it yourself before
# starting one of those providers with docker compose directly.
#
# Requires openssl. Java's truststore is written by openssl when it supports -jdktrust (OpenSSL 3.2 and later),
# otherwise by keytool in a short-lived eclipse-temurin container, so Docker is then needed too.

set -euo pipefail

TEST="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Git Bash would otherwise rewrite the "/O=..." subject below into a Windows path. With that rewriting off, a native
# Windows openssl cannot read an absolute /d/... path either, so every provider works inside its own folder and
# names its files relative to it (see in_generated_folder).
export MSYS_NO_PATHCONV=1

# The password of the Java truststores, which the matrix passes to each Java container. A made-up value, because
# a truststore holds only public certificates.
TRUSTSTORE_PASSWORD="changeit"

log() { echo ">> $*"; }

# Writes a command's output to a temporary file and moves it into place only when the command succeeds, so a failed
# run never leaves a partial file that a later run would reuse.
write_atomically() {  # <path> <command...>
  local path="$1"; shift
  "$@" > "$path.tmp" && mv "$path.tmp" "$path"
}

# 32 random bytes as hex. Only the hex digits are kept, because openssl on Windows ends its output with a carriage
# return as well as a newline, and a secret file must hold the secret alone.
random_hex() { openssl rand -hex 32 | tr -dc '0-9a-f'; }

ensure_secret() {  # <path>
  [[ -s "$1" ]] && return 0
  log "secret $1"
  write_atomically "$1" random_hex
}

ensure_private_key() {  # <path>: an RSA 2048 key in PKCS#8 PEM, usable for TLS and for RS256 signing
  [[ -s "$1" ]] && return 0
  log "private key $1"
  write_atomically "$1" openssl genpkey -quiet -algorithm RSA -pkeyopt rsa_keygen_bits:2048
}

# A self-signed certificate, valid ten years, marked as a certificate authority so each application can trust it
# directly as its own trust anchor. Issued again whenever the key is newer than the certificate.
ensure_certificate() {  # <key> <certificate> <common-name> <subject-alternative-names>
  local key="$1" crt="$2" cn="$3" san="$4"
  ensure_private_key "$key"
  [[ -s "$crt" && ! "$key" -nt "$crt" ]] && return 0
  log "certificate $crt ($cn)"
  write_atomically "$crt" openssl req -x509 -new -key "$key" -sha256 -days 3650 \
    -subj "/O=Astrana Trusted Attestation Development/CN=$cn" \
    -addext "basicConstraints=critical,CA:TRUE" -addext "subjectAltName=$san"
}

# A PKCS#12 truststore holding the one certificate, which a JVM reads through javax.net.ssl.trustStore. Rebuilt
# whenever the certificate is newer than the truststore.
ensure_truststore() {  # <certificate> <truststore> <alias>
  local crt="$1" store="$2" alias="$3"
  [[ -s "$store" && ! "$crt" -nt "$store" ]] && return 0
  log "Java truststore $store"
  if openssl pkcs12 -help 2>&1 | grep -q -- '-jdktrust'; then
    write_atomically "$store" openssl pkcs12 -export -nokeys -in "$crt" -name "$alias" \
      -jdktrust anyExtendedKeyUsage -passout "pass:$TRUSTSTORE_PASSWORD"
  else
    # The certificate goes in on standard input and the truststore comes back on standard output, so no host path
    # is mounted, which keeps this the same on Windows and Linux.
    write_atomically "$store" docker run --rm -i eclipse-temurin:25-jre sh -c \
      'cat > /tmp/c.pem && keytool -importcert -noprompt -alias "$1" -file /tmp/c.pem -keystore /tmp/t.p12 \
         -storetype PKCS12 -storepass "$2" >/dev/null 2>&1 && cat /tmp/t.p12' sh "$alias" "$TRUSTSTORE_PASSWORD" < "$crt"
  fi
}

# Authelia serves its portal over HTTPS on authelia.127.0.0.1.nip.io and reads every secret through the templated
# references in its configuration.yml, so that file holds no secret itself. Its SQLite database is encrypted with the
# storage key, so the database is created beside the key on Authelia's first start. A database left without its key
# can never be opened again, so it is set aside rather than reused.
generate_authelia() {
  ensure_certificate tls.key tls.crt "authelia.127.0.0.1.nip.io" "DNS:authelia.127.0.0.1.nip.io,DNS:*.127.0.0.1.nip.io"
  ensure_truststore tls.crt truststore.p12 authelia
  ensure_private_key oidc-issuer.key
  ensure_secret reset-password-jwt-secret
  ensure_secret session-secret
  ensure_secret oidc-hmac-secret
  if [[ ! -s storage-encryption-key && -e db.sqlite3 ]]; then
    log "setting aside db.sqlite3, which no longer has its encryption key"
    mv db.sqlite3 "db.sqlite3.without-key.$(date +%Y%m%d%H%M%S)"
  fi
  ensure_secret storage-encryption-key
}

# WSO2 Identity Server serves TLS from a PKCS#12 keystore that its Dockerfile copies into the image, under the alias
# and password the stock image already uses (wso2carbon), so the rest of its configuration is unchanged. The image is
# rebuilt by the matrix on every run, so a new keystore reaches it.
generate_wso2() {
  ensure_certificate tls.key wso2-tls.crt localhost "DNS:localhost,IP:127.0.0.1"
  if [[ ! -s tls.p12 || wso2-tls.crt -nt tls.p12 ]]; then
    log "TLS keystore tls.p12"
    write_atomically tls.p12 openssl pkcs12 -export -inkey tls.key -in wso2-tls.crt -name wso2carbon -passout pass:wso2carbon
  fi
  ensure_truststore wso2-tls.crt truststore.p12 wso2
}

# Authentik signs its cookies with AUTHENTIK_SECRET_KEY, which its docker-compose.yml reads from this file.
generate_authentik() {
  [[ -s secrets.env ]] && return 0
  log "secret key secrets.env"
  write_atomically secrets.env printf 'AUTHENTIK_SECRET_KEY=%s\n' "$(random_hex)"
}

# Runs one provider's generate_ function inside that provider's generated/ folder, creating the folder first.
in_generated_folder() {  # <provider>
  local dir="$TEST/$1/generated"
  mkdir -p "$dir"
  log "$1: shared/test/$1/generated"
  ( cd "$dir" && "generate_$1" )
}

main() {
  command -v openssl >/dev/null 2>&1 || { echo "error: openssl is not installed" >&2; exit 1; }
  local providers=("$@")
  [[ ${#providers[@]} -gt 0 ]] || providers=(authelia wso2 authentik)
  local provider
  for provider in "${providers[@]}"; do
    case "$provider" in
      authelia|wso2|authentik) in_generated_folder "$provider" ;;
      *) echo "error: unknown provider '$provider' (authelia, wso2, authentik)" >&2; exit 1 ;;
    esac
  done
}

main "$@"
