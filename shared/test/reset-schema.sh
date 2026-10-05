#!/usr/bin/env bash
#
# Drops the Astrana Trusted Attestation schema from a dev database, so whichever implementation starts
# next recreates it from the canonical DDL in shared/schema.
#
#   shared/test/reset-schema.sh [postgres|mysql|mssql]
#
# The database is the one the shared dev compose alongside this script runs (docker-compose.yml, project
# trusted-attestation-dev), started with `docker compose --profile <engine> up -d`. Run it before pointing
# an implementation at a database another implementation has already initialised: running one
# implementation's suite against a schema another one created would leave every implementation's own
# first-run creation path untested -- which is exactly where the engine-specific work lives:
# dollar-quoted procedure bodies, DELIMITER, GO batches.

set -euo pipefail

DATABASE="${1:-postgres}"
DEV_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

case "${DATABASE}" in
  -h|--help) sed -n '2,12p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
esac

# Git Bash on Windows rewrites anything that looks like a Unix path in a command line, so the
# container path /opt/mssql-tools18/bin/sqlcmd arrives at Docker as C:/Program Files/Git/opt/... and
# the exec fails with 'no such file or directory'. The failure is easy to miss: the message names a
# Windows path nobody wrote, and the SQL Server branch quietly does nothing, leaving no database for
# the app to connect to, which surfaces much later, and elsewhere, as a login failure.
export MSYS_NO_PATHCONV=1

compose() {
  (cd "$DEV_DIR" && docker compose "$@")
}

echo "--> dropping the Astrana Trusted Attestation schema from $DATABASE"

case "$DATABASE" in
  postgres)
    compose exec -T postgres psql -U trusted_attestation -d trusted_attestation -q -c "
      DROP PROCEDURE IF EXISTS grant_member_relationship;
      DROP PROCEDURE IF EXISTS revoke_member_relationship;
      DROP PROCEDURE IF EXISTS extend_member_relationship;
      DROP PROCEDURE IF EXISTS member_self_revoke_relationship;
      DROP PROCEDURE IF EXISTS prune_audit_log;
      DROP TABLE IF EXISTS audit_log;
      DROP TABLE IF EXISTS member_relationships;" > /dev/null
    ;;

  mysql)
    compose exec -T mysql mysql -utrusted_attestation -ptrusted-attestation-dev-password trusted_attestation -e "
      DROP PROCEDURE IF EXISTS grant_member_relationship;
      DROP PROCEDURE IF EXISTS revoke_member_relationship;
      DROP PROCEDURE IF EXISTS extend_member_relationship;
      DROP PROCEDURE IF EXISTS member_self_revoke_relationship;
      DROP PROCEDURE IF EXISTS prune_audit_log;
      DROP TABLE IF EXISTS audit_log;
      DROP TABLE IF EXISTS member_relationships;" 2>&1 | grep -v "Using a password" || true
    ;;

  mssql)
    # The container image ships no database beyond the system ones, so the first run has to create it.
    compose exec -T mssql /opt/mssql-tools18/bin/sqlcmd \
      -S localhost -U sa -P 'Trusted-Attestation-Dev-Password1' -C -Q "
        IF DB_ID('trusted_attestation') IS NULL CREATE DATABASE trusted_attestation;" > /dev/null

    # Confirmed rather than assumed. Everything after this depends on the database existing, and
    # the failure otherwise arrives later wearing a different face: the app cannot log in, which
    # reads as a credentials problem rather than a missing database.
    if ! compose exec -T mssql /opt/mssql-tools18/bin/sqlcmd -S localhost -U sa -P 'Trusted-Attestation-Dev-Password1' -C -d trusted_attestation -Q 'SELECT 1' > /dev/null 2>&1; then
      echo "Could not create the trusted_attestation database on SQL Server." >&2
      exit 1
    fi

    compose exec -T mssql /opt/mssql-tools18/bin/sqlcmd \
      -S localhost -U sa -P 'Trusted-Attestation-Dev-Password1' -C -d trusted_attestation -Q "
        DROP PROCEDURE IF EXISTS grant_member_relationship;
        DROP PROCEDURE IF EXISTS revoke_member_relationship;
        DROP PROCEDURE IF EXISTS extend_member_relationship;
        DROP PROCEDURE IF EXISTS member_self_revoke_relationship;
        DROP PROCEDURE IF EXISTS prune_audit_log;
        DROP TABLE IF EXISTS audit_log;
        DROP TABLE IF EXISTS member_relationships;" > /dev/null
    ;;

  *)
    echo "Unknown database '$DATABASE'. Use postgres, mysql or mssql." >&2
    exit 1
    ;;
esac
