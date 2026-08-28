#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$ROOT_DIR"

COMPOSE="${COMPOSE:-docker compose}"
read -r -a COMPOSE_CMD <<< "$COMPOSE"
if [[ "${#COMPOSE_CMD[@]}" -eq 0 ]]; then
    printf '%s\n' 'migrate: COMPOSE is empty' >&2
    exit 2
fi

run_compose() {
    "${COMPOSE_CMD[@]}" "$@"
}

BACKUP_DIR="${BACKUP_DIR:-${TMPDIR:-/private/tmp}/learn-site-backup-$(date -u '+%Y%m%d%H%M%S')}"
mkdir -p "$BACKUP_DIR"
LOG_FILE="$BACKUP_DIR/migrate.log"

log() {
    printf 'migrate: %s\n' "$*" | tee -a "$LOG_FILE"
}

check_disk() {
    local available
    available="$(df -Pk "$BACKUP_DIR" | awk 'NR == 2 {print $4}')"
    if [[ ! "$available" =~ ^[0-9]+$ ]]; then
        log 'cannot determine free disk space'
        return 1
    fi
    if (( available < ${MIN_FREE_KIB:-65536} )); then
        log "insufficient free disk space: ${available} KiB"
        return 1
    fi
    log "free disk space: ${available} KiB"
}

check_database() {
    run_compose exec -T mysql sh -c \
        'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqladmin ping -h localhost -uroot' \
        >> "$LOG_FILE" 2>&1
    log 'database connection: ok'
}

log "backup directory: $BACKUP_DIR"
check_disk
check_database

set +e
run_compose exec -T api php vendor/bin/phinx status > "$BACKUP_DIR/pre-migration-status.txt" 2>> "$LOG_FILE"
status_code="$?"
set -e
if [[ "$status_code" -ne 0 && "$status_code" -ne 3 ]]; then
    log "pre-migration status failed with exit code $status_code"
    exit "$status_code"
fi
[[ -s "$BACKUP_DIR/pre-migration-status.txt" ]] || {
    log 'cannot read pre-migration status'
    exit 1
}
log 'pre-migration status: captured'

# This prefix is intentional: backup.sh must receive the exact compose
# command and destination selected for this migration attempt.
BACKUP_DIR="$BACKUP_DIR" COMPOSE="$COMPOSE" ALLOW_PREFLIGHT=1 "$SCRIPT_DIR/backup.sh"
log 'pre-migration backup: complete'

if [[ ! -s "$BACKUP_DIR/manifest.json" || ! -s "$BACKUP_DIR/SHA256SUMS" ]]; then
    log 'backup manifest or checksum file is missing'
    exit 1
fi

set +e
run_compose exec -T api php vendor/bin/phinx migrate 2>&1 | tee "$LOG_FILE.migration"
migration_status="${PIPESTATUS[0]}"
set -e
cat "$LOG_FILE.migration" >> "$LOG_FILE"

if [[ "$migration_status" -ne 0 ]]; then
    log "migration failed with exit code $migration_status; no automatic retry was attempted"
    log "preserved failure log: $LOG_FILE.migration"
    exit "$migration_status"
fi

run_compose exec -T api php vendor/bin/phinx status > "$BACKUP_DIR/post-migration-status.txt" 2>> "$LOG_FILE"
[[ -s "$BACKUP_DIR/post-migration-status.txt" ]] || {
    log 'cannot read post-migration status'
    exit 1
}

COMPOSE="$COMPOSE" "$ROOT_DIR/scripts/verify-migrations.sh" >> "$LOG_FILE" 2>&1
run_compose exec -T api php -r \
    'if (@file_get_contents("http://127.0.0.1:8787/health") === false) { exit(1); }' \
    >> "$LOG_FILE" 2>&1

log 'migration, status, schema and health checks: passed'
