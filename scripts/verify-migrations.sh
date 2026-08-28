#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$ROOT_DIR"

COMPOSE="${COMPOSE:-docker compose}"
read -r -a COMPOSE_CMD <<< "$COMPOSE"
if [[ "${#COMPOSE_CMD[@]}" -eq 0 ]]; then
    printf '%s\n' 'verify-migrations: COMPOSE is empty' >&2
    exit 2
fi

COMPOSE_EXTRA_ARGS=()
if [[ -n "${COMPOSE_ENV_FILE:-}" ]]; then
    COMPOSE_EXTRA_ARGS+=(--env-file "$COMPOSE_ENV_FILE")
fi
if [[ -n "${COMPOSE_PROJECT:-}" ]]; then
    COMPOSE_EXTRA_ARGS+=(-p "$COMPOSE_PROJECT")
fi

run_compose() {
    if [[ "${#COMPOSE_EXTRA_ARGS[@]}" -gt 0 ]]; then
        "${COMPOSE_CMD[@]}" "${COMPOSE_EXTRA_ARGS[@]}" "$@"
    else
        "${COMPOSE_CMD[@]}" "$@"
    fi
}

STATUS_FILE="${STATUS_FILE:-${BACKUP_DIR:-/private/tmp}/learn-site-migration-status.txt}"
mkdir -p "$(dirname "$STATUS_FILE")"
run_compose exec -T api php vendor/bin/phinx status > "$STATUS_FILE"

if grep -Eiq '\|[[:space:]]*(down|pending)[[:space:]]*\|' "$STATUS_FILE"; then
    printf '%s\n' 'verify-migrations: pending migrations remain' >&2
    sed -n '1,160p' "$STATUS_FILE" >&2
    exit 1
fi

required_tables=(
    accounts learners staff_users departments posts roles permissions
    categories courses chapters lessons assets orders course_entitlements
    course_enrollments lesson_progresses questions reviews learning_maps
    favorites site_profile
)

for table in "${required_tables[@]}"; do
    exists="$(run_compose exec -T mysql sh -c \
        'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = \"$1\""' \
        sh "$table")"
    if [[ "$exists" != '1' ]]; then
        printf 'verify-migrations: required table is missing: %s\n' "$table" >&2
        exit 1
    fi
done

printf 'verify-migrations: %d required tables present; no pending migrations\n' "${#required_tables[@]}"
