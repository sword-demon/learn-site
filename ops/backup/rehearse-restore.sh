#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ -z "${BACKUP_DIR:-}" ]]; then
    printf '%s\n' 'rehearse-restore: BACKUP_DIR must point to a completed backup' >&2
    exit 2
fi

VERIFY_RESTORE=1 "$SCRIPT_DIR/restore.sh" "$BACKUP_DIR"
