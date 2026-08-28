#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$ROOT_DIR"

references=(
    'php|php:8.4-cli@sha256:f78661b492226388a7057679cc731c3e43bc92ba66cd49a8cfe12374a56bee9f'
    'node|node:22.11.0-alpine@sha256:b64ced2e7cd0a4816699fe308ce6e8a08ccba463c757c00c14cd372e3d2c763e'
    'nginx|nginx:1.27.3-alpine@sha256:814a8e88df978ade80e584cc5b333144b9372a8e3c98872d07137dbf3b44d0e4'
    'mysql|mysql:8.4.11@sha256:b3b90af2a6552ae30c266fdb7d5dd55f3afb72404bb78d37fe8a23eb857fd3fb'
    'redis|redis:7.4.11@sha256:91d0f7e8c748ec7a4c2b4fb2c4f84edab794dd91d01e095e38dc906db9d684ab'
)

image_files=(compose.yaml compose.debug.yaml compose.test.yaml docker/api/Dockerfile docker/admin/Dockerfile docker/web/Dockerfile)

for file in "${image_files[@]}"; do
    if [[ ! -f "$file" ]]; then
        printf 'verify-images: missing image definition file: %s\n' "$file" >&2
        exit 1
    fi
done

for entry in "${references[@]}"; do
    name="${entry%%|*}"
    reference="${entry#*|}"
    if ! rg -F -q "$reference" "${image_files[@]}"; then
        printf 'verify-images: expected pinned %s reference is not present: %s\n' "$name" "$reference" >&2
        exit 1
    fi
done

if rg -n '^[[:space:]]*(image:|FROM[[:space:]])[^#]*latest' "${image_files[@]}"; then
    printf '%s\n' 'verify-images: latest image references are forbidden' >&2
    exit 1
fi

while IFS= read -r line; do
    image="${line#*image:}"
    image="$(printf '%s' "$image" | awk '{$1=$1; print $1}')"
    if [[ "$image" != learn-site/* && "$image" != *@sha256:* ]]; then
        printf 'verify-images: external Compose image is not digest-pinned: %s\n' "$line" >&2
        exit 1
    fi
done < <(rg -n '^[[:space:]]*image:[[:space:]]*[^#]+' compose.yaml compose.debug.yaml compose.test.yaml || true)

for entry in "${references[@]}"; do
    name="${entry%%|*}"
    reference="${entry#*|}"
    printf 'verify-images: inspecting %s\n' "$reference"
    if ! output="$(docker buildx imagetools inspect "$reference" 2>&1)"; then
        printf 'verify-images: inspect failed for %s\n%s\n' "$reference" "$output" >&2
        exit 1
    fi
    digest="${reference##*@}"
    if ! grep -F -q "$digest" <<< "$output"; then
        printf 'verify-images: inspect output does not contain expected %s digest: %s\n' "$name" "$digest" >&2
        exit 1
    fi
    if ! grep -Eq 'linux/amd64|linux/arm64' <<< "$output"; then
        printf 'verify-images: %s did not resolve to a multi-architecture manifest\n' "$reference" >&2
        exit 1
    fi
done

printf '%s\n' 'verify-images: all critical image references are digest-pinned and multi-architecture'
