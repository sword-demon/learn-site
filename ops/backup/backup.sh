#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$ROOT_DIR"

COMPOSE="${COMPOSE:-docker compose}"
read -r -a COMPOSE_CMD <<< "$COMPOSE"
if [[ "${#COMPOSE_CMD[@]}" -eq 0 ]]; then
    printf '%s\n' 'backup: COMPOSE is empty' >&2
    exit 2
fi

run_compose() {
    "${COMPOSE_CMD[@]}" "$@"
}

BACKUP_DIR="${BACKUP_DIR:-${TMPDIR:-/private/tmp}/learn-site-backup-$(date -u '+%Y%m%d%H%M%S')}"
if [[ -e "$BACKUP_DIR" ]]; then
    if [[ ! -d "$BACKUP_DIR" ]]; then
        printf 'backup: destination is not a directory: %s\n' "$BACKUP_DIR" >&2
        exit 2
    fi
    while IFS= read -r existing; do
        name="${existing##*/}"
        if [[ "${ALLOW_PREFLIGHT:-0}" != '1' || ( "$name" != 'pre-migration-status.txt' && "$name" != 'migrate.log' ) ]]; then
            printf 'backup: refusing to overwrite a non-empty directory: %s\n' "$BACKUP_DIR" >&2
            exit 2
        fi
    done < <(find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -print 2>/dev/null)
fi
mkdir -p "$BACKUP_DIR"

printf 'backup: destination=%s\n' "$BACKUP_DIR"

set +e
run_compose exec -T api php vendor/bin/phinx status > "$BACKUP_DIR/phinx-status.txt"
status_code="$?"
set -e
if [[ "$status_code" -ne 0 && "$status_code" -ne 3 ]]; then
    printf 'backup: phinx status failed with exit code %s\n' "$status_code" >&2
    exit "$status_code"
fi
run_compose exec -T mysql sh -c \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump --single-transaction --routines --events --triggers -uroot "$MYSQL_DATABASE"' \
    > "$BACKUP_DIR/mysql.sql"
run_compose exec -T api tar -C /app -czf - uploads > "$BACKUP_DIR/uploads.tar.gz"
run_compose exec -T mysql sh -c \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT COALESCE(MAX(version), 0) FROM phinxlog"' \
    > "$BACKUP_DIR/migration-version.txt"
run_compose config --images > "$BACKUP_DIR/image-references.txt"

for artifact in mysql.sql uploads.tar.gz phinx-status.txt migration-version.txt image-references.txt; do
    if [[ ! -s "$BACKUP_DIR/$artifact" ]]; then
        printf 'backup: artifact is missing or empty: %s\n' "$BACKUP_DIR/$artifact" >&2
        exit 1
    fi
done

BACKUP_DIR="$BACKUP_DIR" "$SCRIPT_DIR/manifest.sh" "$BACKUP_DIR"

SHA256_TOOL=""
if command -v shasum >/dev/null 2>&1; then
    SHA256_TOOL="shasum"
elif command -v sha256sum >/dev/null 2>&1; then
    SHA256_TOOL="sha256sum"
else
    printf '%s\n' 'backup: shasum or sha256sum is required' >&2
    exit 2
fi

(
    cd "$BACKUP_DIR"
    for artifact in mysql.sql uploads.tar.gz phinx-status.txt migration-version.txt image-references.txt manifest.json; do
        if [[ "$SHA256_TOOL" == 'shasum' ]]; then
            shasum -a 256 "$artifact" | awk -v name="$artifact" '{printf "%s  %s\n", $1, name}'
        else
            sha256sum "$artifact" | awk -v name="$artifact" '{printf "%s  %s\n", $1, name}'
        fi
    done
) > "$BACKUP_DIR/SHA256SUMS"

chmod 600 "$BACKUP_DIR"/*
printf 'backup: complete=%s\n' "$BACKUP_DIR"
