#!/usr/bin/env bash
# Runs what continuous integration runs on a pull request. A check whose toolchain is not installed is skipped.
#
#   scripts/check.sh            formatting, sign-off and changelog checks
#   scripts/check.sh --tests    the same, plus each implementation's unit tests
#   scripts/check.sh --base REF compare against REF instead of origin/master
self="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"
cd "$(dirname "$self")/.." || exit 1
set -u

tests=""; base=""
while [ $# -gt 0 ]; do
  case "$1" in
    --tests) tests=yes ;;
    --base) [ $# -ge 2 ] || { echo "--base needs a ref"; exit 2; }; shift; base="$1" ;;
    -h|--help) sed -n '2,6p' "$self"; exit 0 ;;
    *) echo "unknown option: $1"; exit 2 ;;
  esac
  shift
done
if [ -z "$base" ]; then
  for candidate in origin/master master; do
    if git rev-parse --verify --quiet "$candidate" >/dev/null; then base="$candidate"; break; fi
  done
fi

have() { command -v "$1" >/dev/null 2>&1; }
# On Windows "python3" can be a Store shortcut that only prints a message, so try each candidate.
py=""
for candidate in python3 python py; do
  if "$candidate" -c "import sys" >/dev/null 2>&1; then py="$candidate"; break; fi
done
# Maven from the PATH, or else the wrapper in java/, which downloads Maven on first use. Both need a Java development kit.
maven=""
if have mvn; then maven="mvn"
elif [ -x java/mvnw ] && { have java || [ -n "${JAVA_HOME:-}" ]; }; then maven="java/mvnw"
fi

passed=(); failed=(); skipped=()
run() {
  local label="$1"; shift
  echo; echo "== $label"
  if "$@"; then passed+=("$label"); else failed+=("$label"); fi
}
skip() { echo; echo "== $1"; echo "   skipped, $2"; skipped+=("$1"); }
join() { local IFS=","; echo "$*" | sed 's/,/, /g'; }

if [ -f node_modules/.bin/prettier ]; then run "prettier" npm run --silent format:check
elif have npm; then skip "prettier" "run scripts/setup.sh first"
else skip "prettier" "node is not installed"
fi

if have dotnet; then run "dotnet format" dotnet format dotnet/Astrana.TrustedAttestation.slnx --verify-no-changes --verbosity quiet
else skip "dotnet format" "the .NET SDK is not installed"
fi

if [ -n "$maven" ]; then run "spotless" "$maven" -q -f java/pom.xml spotless:check
else skip "spotless" "the Java 25 development kit is not installed"
fi

if have php && [ -f php/vendor/bin/pint ]; then run "pint" bash -c 'cd php && php vendor/bin/pint --test'
elif have php; then skip "pint" "run composer install in php/ first"
else skip "pint" "php is not installed"
fi

if [ -n "$py" ] && [ -n "$base" ]; then
  echo; echo "comparing against $base at $(git log -1 --format='%h, %cr' "$base")"
  [ -z "$(git status --porcelain)" ] || echo "uncommitted changes are not included in the sign-off and changelog checks, commit first"
  run "sign-off" "$py" .github/scripts/dco_check.py "$base"
  run "changelog" "$py" .github/scripts/changelog_check.py "$base"
elif [ -z "$py" ]; then
  skip "sign-off and changelog" "python is not installed"
else
  skip "sign-off and changelog" "no master to compare against (fetch origin first)"
fi

if [ -n "$tests" ]; then
  if have dotnet; then run "dotnet test" dotnet test dotnet/Astrana.TrustedAttestation.slnx --nologo --verbosity quiet
  else skip "dotnet test" "the .NET SDK is not installed"
  fi
  if [ -n "$maven" ]; then run "mvn test" "$maven" -q -f java/pom.xml test
  else skip "mvn test" "the Java 25 development kit is not installed"
  fi
  if have php && [ -f php/vendor/bin/phpunit ]; then run "phpunit" bash -c 'cd php && php vendor/bin/phpunit'
  elif have php; then skip "phpunit" "run composer install in php/ first"
  else skip "phpunit" "php is not installed"
  fi
fi

echo
echo "== summary"
[ ${#passed[@]} -gt 0 ] && echo "   passed:  $(join "${passed[@]}")"
[ ${#skipped[@]} -gt 0 ] && echo "   skipped: $(join "${skipped[@]}") (continuous integration runs these)"
[ ${#failed[@]} -gt 0 ] && echo "   FAILED:  $(join "${failed[@]}")"
case " ${failed[*]:-} " in *" prettier "*|*" dotnet format "*|*" spotless "*|*" pint "*) echo "   scripts/format.sh fixes formatting" ;; esac
if [ -n "$tests" ]; then echo "   not run: the integration suites (scripts/integration.sh)"
else echo "   not run: unit tests (--tests), the integration suites (scripts/integration.sh)"
fi
[ ${#failed[@]} -eq 0 ]
