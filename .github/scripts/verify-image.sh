#!/usr/bin/env bash
# Verifies an image's signature against SIGNER_IDENTITY_REGEXP and SIGNER_ISSUER. Docker Hub can take a moment to show
# a signature that was just pushed, so "no signatures found" is checked again, every VERIFY_DELAY seconds (10), up to
# VERIFY_ATTEMPTS times (6). Any other failure fails at once.
#
# Usage: verify-image.sh <image>@<digest>
set -euo pipefail

attempts=${VERIFY_ATTEMPTS:-6}
delay=${VERIFY_DELAY:-10}

for ((attempt = 1; ; attempt++)); do
  if output=$(cosign verify --certificate-identity-regexp "$SIGNER_IDENTITY_REGEXP" \
    --certificate-oidc-issuer "$SIGNER_ISSUER" "$1" 2>&1); then
    printf '%s\n' "$output"
    exit 0
  fi

  printf '%s\n' "$output" >&2
  if [[ $output != *"no signatures found"* ]] || ((attempt >= attempts)); then
    exit 1
  fi

  echo "Docker Hub does not show the signature yet. Checking again in $delay seconds." >&2
  sleep "$delay"
done
