#!/usr/bin/env bash
# Runs the conformance, differential and accessibility suites against the three implementations in the CI
# stack, and writes a report. Runs inside the "suite" container (see docker-compose.ci.yml), where
# https://localhost:15443, 16443 and 17443 are .NET, Java and PHP.
#
#   ci/run-suites.sh [report directory]
#
# Exit status is non-zero if any suite failed. Every suite still runs, so one failure does not hide another.
# The report directory always ends up with an index.html, because that is the page CI publishes: when the
# suites produce a conformance report it is that report, and otherwise it is a page saying what failed,
# with links to each suite's output, so a broken run is visible rather than a missing page.
set -uo pipefail

OUT="${1:-/reports}"
mkdir -p "$OUT/runs"
cd "$(dirname "${BASH_SOURCE[0]}")/.."

declare -A URL=([dotnet]=https://localhost:15443 [java]=https://localhost:16443 [php]=https://localhost:17443)
IMPLS=(dotnet java php)
TITLE="Astrana Trusted Attestation Server conformance"
failed=()
runs=()
report_written=""

# Written before anything runs and again on every exit, including one forced on the script, so the page
# reflects whatever happened. A conformance report produced by this run replaces it.
write_index() {
  if [ -n "$report_written" ] && [ -f "$OUT/conformance-report.html" ]; then
    cp "$OUT/conformance-report.html" "$OUT/index.html"
    return
  fi
  {
    echo '<!doctype html>'
    echo '<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    echo "<title>$TITLE</title></head><body>"
    echo "<h1>$TITLE</h1>"
    if [ "${#failed[@]}" -eq 0 ] && [ -z "$report_written" ]; then
      echo '<p>The suites did not finish. No conformance report was produced.</p>'
    else
      echo '<p>The suites did not produce a conformance report. Failed: '"${failed[*]:-none}"'</p>'
    fi
    echo '<h2>Suite output</h2><ul>'
    local f
    for f in "$OUT"/*.txt; do
      [ -f "$f" ] || continue
      echo "<li><a href=\"$(basename "$f")\">$(basename "$f")</a></li>"
    done
    echo '</ul></body></html>'
  } > "$OUT/index.html"
}
write_index
trap write_index EXIT

wait_for() { # name url: wait up to five minutes for the manifest to answer 200
  local name="$1" url="$2/.well-known/ata-manifest.json" i
  for i in $(seq 1 60); do
    if python3 - "$url" <<'EOF' 2>/dev/null; then echo "$name is up"; return 0; fi
import ssl, sys, urllib.request
ctx = ssl._create_unverified_context()
sys.exit(0 if urllib.request.urlopen(sys.argv[1], context=ctx, timeout=5).status == 200 else 1)
EOF
    sleep 5
  done
  echo "$name did not come up at $url"; return 1
}

for impl in "${IMPLS[@]}"; do
  wait_for "$impl" "${URL[$impl]}" || failed+=("startup:$impl")
done

for impl in "${IMPLS[@]}"; do
  echo; echo "===== conformance: $impl"
  python3 conformance/check.py \
    --base-url "${URL[$impl]}" \
    --database postgres \
    --sql-command "psql -q -v ON_ERROR_STOP=1 -h postgres -U trusted_attestation -d trusted_attestation_$impl" \
    --json "$OUT/runs/keycloak-$impl.json" --idp Keycloak --implementation "$impl" \
    2>&1 | tee "$OUT/conformance-$impl.txt" || failed+=("conformance:$impl")
  [ -f "$OUT/runs/keycloak-$impl.json" ] && runs+=("$OUT/runs/keycloak-$impl.json")
done

echo; echo "===== differential"
python3 differential.py \
  --base-url "${URL[dotnet]}" --base-url "${URL[java]}" --base-url "${URL[php]}" \
  2>&1 | tee "$OUT/differential.txt" || failed+=("differential")

# The accessibility suite checks the key form's error flow only when the member holds a relationship, and
# the conformance suite clears its fixtures when it finishes, so grant alice one for the duration.
ALICE="11111111-1111-4111-8111-111111111111"
sql() { psql -q -v ON_ERROR_STOP=1 -h postgres -U trusted_attestation -d "trusted_attestation_$1" -c "$2"; }
for impl in "${IMPLS[@]}"; do
  sql "$impl" "CALL grant_member_relationship('$ALICE', 'employee', NULL, 'ci');" || echo "could not grant the accessibility fixture for $impl"
done

for impl in "${IMPLS[@]}"; do
  echo; echo "===== accessibility: $impl"
  python3 accessibility.py --base-url "${URL[$impl]}" --member alice --password password \
    2>&1 | tee "$OUT/accessibility-$impl.txt" || failed+=("accessibility:$impl")
done

for impl in "${IMPLS[@]}"; do
  sql "$impl" "DELETE FROM member_relationships WHERE iam_subject_id = '$ALICE';" || true
done

echo; echo "===== report"
# A conformance run that never started writes no JSON, and the report needs at least one. Without this
# guard the report step fails on an empty argument list and the placeholder index.html stands.
if [ "${#runs[@]}" -eq 0 ]; then
  echo "no conformance results to report on"
elif python3 integration-report.py "${runs[@]}" --out "$OUT/conformance-report" --title "$TITLE"; then
  report_written=yes
else
  failed+=("report")
fi

echo
if [ "${#failed[@]}" -eq 0 ]; then
  echo "All suites passed."
  exit 0
fi
echo "Failed: ${failed[*]}"
exit 1
