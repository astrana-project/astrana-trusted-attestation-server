#!/usr/bin/env bash
# Runs the conformance, differential and accessibility suites against all three implementations, in the
# Docker stack continuous integration uses. The report lands in shared/test/ci/reports/index.html.
#
#   scripts/integration.sh              build, run the suites, tear the stack down
#   scripts/integration.sh --keep       leave the stack running afterwards
#   scripts/integration.sh --no-build   reuse the images from a previous run
#   scripts/integration.sh --down       tear the stack down
#
# The first run downloads several gigabytes of images and builds three applications, so it takes a while.
# The stack uses ports 15443, 16443 and 17443, the same as the demonstration stacks, so stop those first.
self="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"
cd "$(dirname "$self")/.." || exit 1
set -u
export MSYS_NO_PATHCONV=1

keep=""; build="--build"; down=""
for arg in "$@"; do
  case "$arg" in
    --keep) keep=yes ;;
    --no-build) build="--no-build" ;;
    --down) down=yes ;;
    -h|--help) sed -n '2,11p' "$self"; exit 0 ;;
    *) echo "unknown option: $arg"; exit 2 ;;
  esac
done

command -v docker >/dev/null 2>&1 || { echo "docker is not installed: https://docs.docker.com/get-docker/"; exit 1; }
compose=(docker compose -f shared/test/ci/docker-compose.ci.yml)
[ -z "$down" ] || exec "${compose[@]}" --profile suite down -v

echo "== starting the stack"
if ! "${compose[@]}" up -d $build keycloak postgres dotnet-app java-app php-app proxy; then
  echo "the stack did not start, scripts/integration.sh --down cleans up"; exit 1
fi
if [ "$build" = "--build" ] && ! "${compose[@]}" --profile suite build suite; then
  echo "the suite image did not build, scripts/integration.sh --down stops the stack"; exit 1
fi

echo "== running the suites"
"${compose[@]}" run --rm --no-deps suite bash ci/run-suites.sh /reports
status=$?

if [ -z "$keep" ]; then
  echo "== stopping the stack"
  "${compose[@]}" --profile suite down -v
else
  echo "stack left running, scripts/integration.sh --down stops it"
fi
echo "report: shared/test/ci/reports/index.html"
exit "$status"
