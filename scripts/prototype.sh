#!/bin/sh
# PROTOTYPE throwaway server. Not production.
cd "$(dirname "$0")/.."
PORT="${PORT:-4173}"
echo "学习端 B  http://127.0.0.1:${PORT}/apps/web/prototype/index.html"
echo "管理端 A  http://127.0.0.1:${PORT}/apps/admin/prototype/index.html"
exec python3 -m http.server "$PORT"
