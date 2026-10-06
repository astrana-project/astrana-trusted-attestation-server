#!/usr/bin/env bash
# Prepares a clone for contributing. Safe to run again at any time.
#
#   scripts/setup.sh            install the formatter and report toolchains
#   scripts/setup.sh --hooks    also install the git hooks (sign off each commit, check sign-off and changelog on push)
#   scripts/setup.sh --no-hooks remove those hooks
self="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"
cd "$(dirname "$self")/.." || exit 1
set -u

hooks=""
for arg in "$@"; do
  case "$arg" in
    --hooks) hooks=install ;;
    --no-hooks) hooks=remove ;;
    -h|--help) sed -n '2,6p' "$self"; exit 0 ;;
    *) echo "unknown option: $arg"; exit 2 ;;
  esac
done

report() {
  local version
  if version="$("$1" --version 2>/dev/null | head -1)" && [ -n "$version" ]; then
    printf '  %-10s found    %s\n' "$1" "$version"
  else
    printf '  %-10s missing  %s\n' "$1" "$2"
  fi
}
# On Windows "python3" can be a Store shortcut that only prints a message, so try each candidate.
python=""
for candidate in python3 python py; do
  if "$candidate" -c "import sys" >/dev/null 2>&1; then python="$candidate"; break; fi
done

echo "== toolchains"
report node "formatter: https://nodejs.org"
report "${python:-python}" "sign-off and changelog checks: https://www.python.org"
report dotnet ".NET implementation: https://dotnet.microsoft.com/download"
report java "Java implementation: a Java 25 development kit, for example https://adoptium.net"
if command -v mvn >/dev/null 2>&1 || [ ! -x java/mvnw ]; then report mvn "Java implementation: https://maven.apache.org"
else printf '  %-10s wrapper  java/mvnw supplies Maven, no install needed\n' mvn
fi
report php "PHP implementation: https://www.php.net/downloads"
report composer "PHP implementation: https://getcomposer.org"
report docker "demonstration stacks and integration suites: https://docs.docker.com/get-docker/"
echo "  a missing toolchain only matters for what it builds, continuous integration runs everything"

if npm --version >/dev/null 2>&1; then
  echo "== formatter"
  if npm ci --no-audit --no-fund --silent; then echo "prettier installed"; else echo "npm ci failed, run it by hand"; fi
fi

hook_dir="$(git rev-parse --git-path hooks)"
marker="astrana-trusted-attestation-server"
install_hook() {
  local target="$hook_dir/$1"
  if [ -f "$target" ] && ! grep -q "$marker" "$target"; then
    echo "a $1 hook that is not ours exists at $target, leaving it alone"
  else
    cp "scripts/hooks/$1" "$target" && chmod +x "$target" && echo "$1 hook installed"
  fi
}
remove_hook() {
  local target="$hook_dir/$1"
  if [ -f "$target" ] && grep -q "$marker" "$target"; then rm -f "$target" && echo "$1 hook removed"; else echo "no $1 hook of ours to remove"; fi
}
case "$hooks" in
  install)
    echo "== hooks"
    hooks_path="$(git config --get core.hooksPath || true)"
    case "$hooks_path" in
      "") ;;
      /*|?:*) case "$hooks_path" in "$PWD"/*) ;; *) echo "core.hooksPath is $hooks_path, outside this repository, not installing there"; exit 1 ;; esac ;;
    esac
    mkdir -p "$hook_dir"
    install_hook prepare-commit-msg
    install_hook pre-push ;;
  remove)
    echo "== hooks"
    remove_hook prepare-commit-msg
    remove_hook pre-push ;;
esac

echo "== next"
[ "$hooks" = install ] || echo "  sign off every commit with git commit -s, or run scripts/setup.sh --hooks to have it added for you"
if php --version >/dev/null 2>&1 && [ ! -d php/vendor ]; then echo "  PHP: run composer install in php/"; fi
echo "  scripts/format.sh formats, scripts/check.sh runs what continuous integration runs, scripts/integration.sh runs the suites"
