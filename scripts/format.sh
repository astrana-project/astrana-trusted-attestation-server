#!/usr/bin/env bash
# Runs every formatter, skipping any whose toolchain is not installed.
#
#   scripts/format.sh
cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 1
set -u

have() { command -v "$1" >/dev/null 2>&1; }
status=0
run() {
  local label="$1"; shift
  echo "== $label"
  if "$@"; then echo "   ok"; else echo "   FAILED"; status=1; fi
}
skip() { echo "== $1"; echo "   skipped, $2"; }
# Maven from the PATH, or else the wrapper in java/, which downloads Maven on first use. Both need a Java development kit.
maven=""
if have mvn; then maven="mvn"
elif [ -x java/mvnw ] && { have java || [ -n "${JAVA_HOME:-}" ]; }; then maven="java/mvnw"
fi

if [ -f node_modules/.bin/prettier ]; then run "prettier" npm run --silent format
elif have npm; then skip "prettier" "run scripts/setup.sh first"
else skip "prettier" "node is not installed"
fi

if have dotnet; then run "dotnet format" dotnet format dotnet/Astrana.TrustedAttestation.slnx --verbosity quiet
else skip "dotnet format" "the .NET SDK is not installed"
fi

if [ -n "$maven" ]; then run "spotless" "$maven" -q -f java/pom.xml spotless:apply
else skip "spotless" "the Java 25 development kit is not installed"
fi

if have php && [ -f php/vendor/bin/pint ]; then run "pint" bash -c 'cd php && php vendor/bin/pint'
elif have php; then skip "pint" "run composer install in php/ first"
else skip "pint" "php is not installed"
fi

exit "$status"
