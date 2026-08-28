#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$ROOT_DIR"

BACKUP_DIR="${1:-${BACKUP_DIR:-}}"
if [[ -z "$BACKUP_DIR" || ! -d "$BACKUP_DIR" ]]; then
    printf 'restore: backup directory does not exist: %s\n' "${BACKUP_DIR:-<empty>}" >&2
    exit 2
fi

for artifact in mysql.sql uploads.tar.gz manifest.json SHA256SUMS; do
    if [[ ! -s "$BACKUP_DIR/$artifact" ]]; then
        printf 'restore: required backup artifact is missing or empty: %s\n' "$BACKUP_DIR/$artifact" >&2
        exit 1
    fi
done

COMPOSE="${COMPOSE:-docker compose}"
read -r -a COMPOSE_CMD <<< "$COMPOSE"
if [[ "${#COMPOSE_CMD[@]}" -eq 0 ]]; then
    printf '%s\n' 'restore: COMPOSE is empty' >&2
    exit 2
fi

ENV_FILE="${ENV_FILE:-$ROOT_DIR/.env}"
if [[ ! -f "$ENV_FILE" ]]; then
    printf 'restore: env file does not exist: %s\n' "$ENV_FILE" >&2
    exit 2
fi

RESTORE_PROJECT="${RESTORE_PROJECT:-learn-site-restore-$(date -u '+%Y%m%d%H%M%S')}"
if [[ ! "$RESTORE_PROJECT" =~ ^learn-site-restore-[a-z0-9][a-z0-9_-]*$ ]]; then
    printf 'restore: RESTORE_PROJECT must be an isolated learn-site-restore-* project: %s\n' "$RESTORE_PROJECT" >&2
    exit 2
fi

run_compose() {
    "${COMPOSE_CMD[@]}" --env-file "$ENV_FILE" -p "$RESTORE_PROJECT" "$@"
}

cleanup() {
    if [[ "${RESTORE_STARTED:-0}" == '1' ]]; then
        run_compose down -v --remove-orphans >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

SHA256_TOOL=""
if command -v shasum >/dev/null 2>&1; then
    SHA256_TOOL="shasum"
elif command -v sha256sum >/dev/null 2>&1; then
    SHA256_TOOL="sha256sum"
else
    printf '%s\n' 'restore: shasum or sha256sum is required' >&2
    exit 2
fi

(
    cd "$BACKUP_DIR"
    if [[ "$SHA256_TOOL" == 'shasum' ]]; then
        shasum -a 256 -c SHA256SUMS
    else
        sha256sum -c SHA256SUMS
    fi
)

RESTORE_LOG="$BACKUP_DIR/restore-$RESTORE_PROJECT.log"
printf 'restore: project=%s backup=%s\n' "$RESTORE_PROJECT" "$BACKUP_DIR" | tee "$RESTORE_LOG"
run_compose up -d --wait --wait-timeout 180 mysql redis api 2>&1 | tee -a "$RESTORE_LOG"
RESTORE_STARTED=1

run_compose exec -T mysql sh -c \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE"' \
    < "$BACKUP_DIR/mysql.sql" | tee -a "$RESTORE_LOG"
run_compose exec -T api tar -C /app -xzf - < "$BACKUP_DIR/uploads.tar.gz" | tee -a "$RESTORE_LOG"

run_compose exec -T api php vendor/bin/phinx status | tee -a "$RESTORE_LOG"
run_compose exec -T api php -r \
    'if (@file_get_contents("http://127.0.0.1:8787/health") === false) { exit(1); }' \
    | tee -a "$RESTORE_LOG"
run_compose exec -T mysql sh -c \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "CREATE TEMPORARY TABLE restore_probe (value INT NOT NULL); INSERT INTO restore_probe VALUES (1); SELECT COUNT(*) FROM restore_probe"' \
    | tee -a "$RESTORE_LOG"

asset_paths="$(run_compose exec -T mysql sh -c \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT storage_path FROM assets WHERE status = \"ready\" LIMIT 20"')"
while IFS= read -r storage_path; do
    [[ -n "$storage_path" ]] || continue
    if [[ "$storage_path" == /* || "$storage_path" == *'..'* ]]; then
        printf 'restore: unsafe asset path in restored database: %s\n' "$storage_path" >&2
        exit 1
    fi
    run_compose exec -T api test -f "/app/uploads/$storage_path"
done <<< "$asset_paths"

COMPOSE="$COMPOSE" COMPOSE_ENV_FILE="$ENV_FILE" COMPOSE_PROJECT="$RESTORE_PROJECT" \
    BACKUP_DIR="$BACKUP_DIR" "$ROOT_DIR/scripts/verify-migrations.sh" | tee -a "$RESTORE_LOG"
printf '%s\n' 'restore: database, upload references, health and migration checks passed' | tee -a "$RESTORE_LOG"
