#!/usr/bin/env bash

set -Eeuo pipefail

BACKUP_DIR="${1:-${BACKUP_DIR:-}}"
if [[ -z "$BACKUP_DIR" || ! -d "$BACKUP_DIR" ]]; then
    printf 'manifest: backup directory does not exist: %s\n' "${BACKUP_DIR:-<empty>}" >&2
    exit 2
fi

MANIFEST_FILE="${MANIFEST_FILE:-$BACKUP_DIR/manifest.json}"
SHA256_TOOL=""
if command -v shasum >/dev/null 2>&1; then
    SHA256_TOOL="shasum"
elif command -v sha256sum >/dev/null 2>&1; then
    SHA256_TOOL="sha256sum"
else
    printf '%s\n' 'manifest: shasum or sha256sum is required' >&2
    exit 2
fi

sha256() {
    if [[ "$SHA256_TOOL" == 'shasum' ]]; then
        shasum -a 256 "$1" 2>/dev/null | awk '{print $1}'
    else
        sha256sum "$1" 2>/dev/null | awk '{print $1}'
    fi
}

json_escape() {
    printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'
}

files=(
    mysql.sql
    uploads.tar.gz
    phinx-status.txt
    migration-version.txt
    image-references.txt
)
for file in "${files[@]}"; do
    if [[ ! -s "$BACKUP_DIR/$file" ]]; then
        printf 'manifest: required artifact is missing or empty: %s\n' "$BACKUP_DIR/$file" >&2
        exit 1
    fi
done

created_at="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
migration_version="$(tr -d '[:space:]' < "$BACKUP_DIR/migration-version.txt")"
if [[ -z "$migration_version" ]]; then
    migration_version="unknown"
fi

{
    printf '{\n'
    printf '  "format_version": 1,\n'
    printf '  "created_at": "%s",\n' "$(json_escape "$created_at")"
    printf '  "migration_version": "%s",\n' "$(json_escape "$migration_version")"
    printf '  "files": [\n'

    comma=""
    for file in "${files[@]}"; do
        path="$BACKUP_DIR/$file"
        size="$(wc -c < "$path" | tr -d '[:space:]')"
        digest="$(sha256 "$path")"
        if [[ ! "$size" =~ ^[0-9]+$ || ! "$digest" =~ ^[0-9a-fA-F]{64}$ ]]; then
            printf 'manifest: cannot fingerprint %s\n' "$path" >&2
            exit 1
        fi
        if [[ -n "$comma" ]]; then
            printf ',\n'
        fi
        printf '    {"name":"%s","size_bytes":%s,"sha256":"%s"}' \
            "$(json_escape "$file")" "$size" "$digest"
        comma=1
    done
    printf '\n  ],\n'
    printf '  "images": [\n'

    image_comma=""
    while IFS= read -r image; do
        [[ -n "$image" ]] || continue
        if [[ -n "$image_comma" ]]; then
            printf ',\n'
        fi
        printf '    "%s"' "$(json_escape "$image")"
        image_comma=1
    done < "$BACKUP_DIR/image-references.txt"

    printf '\n  ]\n'
    printf '}\n'
} > "$MANIFEST_FILE"

[[ -s "$MANIFEST_FILE" ]] || {
    printf 'manifest: output is empty: %s\n' "$MANIFEST_FILE" >&2
    exit 1
}

printf 'manifest: %s\n' "$MANIFEST_FILE"
