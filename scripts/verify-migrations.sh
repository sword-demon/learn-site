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
    favorites site_profile notification_dispatches notification_dispatch_recipients
    learner_notifications scheduled_tasks scheduled_task_runs learner_daily_checkins banners
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

active_marker_extra="$(run_compose exec -T mysql sh -c \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT EXTRA FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = \"course_entitlements\" AND column_name = \"active_marker\""' \
    | tr -d '\r')"
if [[ "$active_marker_extra" != *'GENERATED'* ]]; then
    printf '%s\n' 'verify-migrations: course_entitlements.active_marker is not a generated column' >&2
    exit 1
fi

active_index_shape="$(run_compose exec -T mysql sh -c \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT CONCAT(GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR \",\"), \"|\", NON_UNIQUE) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = \"course_entitlements\" AND index_name = \"uq_course_entitlements_active\" GROUP BY INDEX_NAME, NON_UNIQUE"' \
    | tr -d '\r')"
if [[ "$active_index_shape" != 'learner_id,course_id,active_marker|0' ]]; then
    printf 'verify-migrations: active entitlement unique index has unexpected shape: %s\n' "$active_index_shape" >&2
    exit 1
fi

legacy_index_count="$(run_compose exec -T mysql sh -c \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM (SELECT INDEX_NAME FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = \"course_entitlements\" AND NON_UNIQUE = 0 AND INDEX_NAME <> \"PRIMARY\" GROUP BY INDEX_NAME, NON_UNIQUE HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR \",\") = \"learner_id,course_id,status\") AS legacy_indexes"' \
    | tr -d '\r')"
if [[ "$legacy_index_count" != '0' ]]; then
    printf 'verify-migrations: legacy status-based entitlement unique index remains: %s\n' "$legacy_index_count" >&2
    exit 1
fi

active_duplicate_count="$(run_compose exec -T mysql sh -c \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM (SELECT learner_id, course_id FROM course_entitlements WHERE status = \"active\" GROUP BY learner_id, course_id HAVING COUNT(*) > 1) AS duplicate_active"' \
    | tr -d '\r')"
if [[ "$active_duplicate_count" != '0' ]]; then
    printf 'verify-migrations: duplicate active entitlements found: %s\n' "$active_duplicate_count" >&2
    exit 1
fi

printf 'verify-migrations: %d required tables present; no pending migrations\n' "${#required_tables[@]}"
