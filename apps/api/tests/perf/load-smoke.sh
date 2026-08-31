#!/usr/bin/env bash
# 008-api-scale-100k — load smoke placeholder.
# Requires k6 or wrk on the host. See specs/008-api-scale-100k/quickstart.md.
set -euo pipefail

BASE_URL="${1:-http://127.0.0.1:8787}"

echo "Load smoke target: ${BASE_URL}"
echo "Install k6 and extend this script for 500 catalog + 200 progress concurrent gates."
exit 0
