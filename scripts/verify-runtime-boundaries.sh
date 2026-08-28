#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
API_DIR="$ROOT_DIR/apps/api"

failures=()
record_hits() {
    local label="$1"
    local pattern="$2"
    shift 2
    local hits
    hits="$(rg -n --hidden --glob '*.php' "$pattern" "$@" 2>/dev/null || true)"
    if [[ -n "$hits" ]]; then
        failures+=("$label")
        printf 'verify-runtime-boundaries: %s\n%s\n' "$label" "$hits" >&2
    fi
}

record_hits 'forbidden PDO/mysqli data access' \
    '(^use[[:space:]]+PDO[[:space:]]*;|new[[:space:]]+\\?PDO\b|(^use[[:space:]]+mysqli[[:space:]]*;|new[[:space:]]+mysqli\b)|illuminate/database|Illuminate\\Database|Eloquent)' \
    "$API_DIR/app"
record_hits 'application migration or schema commands' \
    '(vendor/bin/phinx|phinx[[:space:]]+(migrate|rollback|seed)|Schema::|CREATE[[:space:]]+TABLE|ALTER[[:space:]]+TABLE|DROP[[:space:]]+TABLE)' \
    "$API_DIR/app"

if [[ ! -f "$API_DIR/config/think-orm.php" ]]; then
    failures+=('missing config/think-orm.php')
fi
if [[ ! -f "$API_DIR/config/database.php" ]]; then
    failures+=('missing compatibility config/database.php')
else
    if rg -n 'connections|hostname|database|username|password' "$API_DIR/config/database.php" >/dev/null 2>&1; then
        failures+=('config/database.php contains runtime connection settings')
        printf '%s\n' 'verify-runtime-boundaries: config/database.php must remain an empty compatibility file' >&2
    fi
fi

if rg -n 'phinx[[:space:]]+(migrate|rollback|seed)|vendor/bin/phinx' \
    "$API_DIR/start.php" "$API_DIR/app/controller" "$API_DIR/app/middleware" "$API_DIR/app/controller/HealthController.php" \
    >/dev/null 2>&1; then
    failures+=('migration command reachable from startup, health or request path')
fi

if [[ "${#failures[@]}" -gt 0 ]]; then
    printf 'verify-runtime-boundaries: failed (%d checks)\n' "${#failures[@]}" >&2
    exit 1
fi

printf '%s\n' 'verify-runtime-boundaries: ORM, migration and configuration boundaries passed'
