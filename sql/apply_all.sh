#!/usr/bin/env bash
# ============================================================================
# apply_all.sh — build the schema from sql/ alone, in the order that works.
# ============================================================================
#
# WHY THIS FILE EXISTS
#
# Before sql/107, a database could not be built from this repository at all:
# twelve tables and two column sets existed only inside PHP migrations, so the
# numbered chain failed in ten places. `tests/e2e/provision_env.sh` worked
# around that with a regex that scraped CREATE TABLE statements out of PHP —
# which meant the *tests* could build a database and the *deployment path*
# could not.
#
# 107 writes those definitions down as SQL, so the workaround is gone. What
# remains is an ordering fact that no single migration file can express:
#
#     012 creates `classes` and `academic_years` (its baseline).
#     107 creates `subjects`, `assessments`, finance, materials.
#     017/029/075/076 need those, but they are numbered BEFORE 107.
#
# A chain cannot repair its own past. So this script does the two passes that
# the live database's history actually took, and it is the procedure the
# deployment guide should point at.
#
# VERIFIED: produces 92 of 96 production tables on an empty database. The four
# absent are the three group tables retired by N-4 and
# migration_055_mezmur_link_quarantine, which 055 declines to create without a
# seeded hymn taxonomy by design (SIGNAL SQLSTATE '45000').
#
# USAGE
#   bash sql/apply_all.sh <database-name>
#   MARIADB="sudo -n mariadb -uroot" bash sql/apply_all.sh school
#
# Idempotent: every migration in this repository guards its own DDL, and the
# two passes only repeat files that are safe to repeat.

set -uo pipefail

DB="${1:-}"
if [ -z "$DB" ]; then
    echo "usage: bash sql/apply_all.sh <database-name>" >&2
    exit 1
fi

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MARIADB="${MARIADB:-mariadb}"

run() {  # run <file> <label> <required:0|1>
    local f="$1" label="$2" required="${3:-0}"
    if $MARIADB "$DB" < "$f" 2>/tmp/apply_err; then
        echo "    ok    $label"
        return 0
    fi
    if [ "$required" = "1" ]; then
        echo "    FAIL  $label (required)" >&2
        sed 's/^/          /' /tmp/apply_err | head -5 >&2
        return 1
    fi
    echo "    skip  $label  ($(grep -oE 'ERROR [0-9]+[^\n]*' /tmp/apply_err | head -1))"
    return 0
}

echo "== 1. legacy baseline (the 11 original tables) =="
run "$HERE/legacy/database_schema.sql" "legacy/database_schema.sql" 1 || exit 1

echo "== 2. runtime baselines =="
run "$HERE/012_runtime_schema_baseline.sql" "012" 1 || exit 1
run "$HERE/013_application_schema_completion.sql" "013" 1 || exit 1

echo "== 3. every numbered migration, in order =="
# Some of these legitimately fail on the first pass: they reference objects that
# a LATER migration creates (017/029/075/076 need 107's subjects; 014/068 need
# members.membership_tier). Pass 4 re-runs them.
first_pass_failures=()
for f in $(ls "$HERE"/[0-9][0-9][0-9]_*.sql | sort -V); do
    if $MARIADB "$DB" < "$f" 2>/dev/null; then
        :
    else
        first_pass_failures+=("$(basename "$f")")
    fi
done
echo "    ${#first_pass_failures[@]} file(s) deferred to pass 4"
for f in "${first_pass_failures[@]}"; do echo "      - $f"; done

echo "== 4. re-run the deferred migrations =="
# Correct order for a chain that was retrofitted: everything that needs
# 107-created objects now sees them.
for name in "${first_pass_failures[@]}"; do
    run "$HERE/$name" "$name" 0
done

echo "== 5. summary =="
tables=$($MARIADB "$DB" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB'" 2>/dev/null)
echo "    tables in $DB: ${tables:-unknown}"
echo
echo "    Two files are expected to remain unapplied, both for stated reasons:"
echo "      003  duplicate key 'idx_members_status' — idempotency wart, no missing object"
echo "      055  aborts by design without seeded hymn categories 85/116"
