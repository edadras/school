#!/usr/bin/env bash
# Starts the full local stack used by the E2E tests: MariaDB/MySQL + Redis must already run.
#   LiveKit SFU (real WebRTC) · Reverb (WebSocket) · queue worker · scheduler · web (Flutter build + API)
# Usage: scripts/e2e-stack.sh start|stop
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LOG="${E2E_LOG_DIR:-/tmp/e2e}"; mkdir -p "$LOG"
LIVEKIT_BIN="${LIVEKIT_BIN:-livekit-server}"
export APP_ENV=e2e
cd "$ROOT/backend"
case "${1:-start}" in
  start)
    "$LIVEKIT_BIN" --dev --bind 0.0.0.0 --node-ip 127.0.0.1 > "$LOG/livekit.log" 2>&1 & echo $! > "$LOG/livekit.pid"
    php artisan reverb:start --host=0.0.0.0 --port=8080 > "$LOG/reverb.log" 2>&1 & echo $! > "$LOG/reverb.pid"
    php artisan queue:work redis --queue=default,broadcasts --sleep=1 --tries=3 > "$LOG/queue.log" 2>&1 & echo $! > "$LOG/queue.pid"
    php artisan schedule:work > "$LOG/scheduler.log" 2>&1 & echo $! > "$LOG/scheduler.pid"
    PHP_CLI_SERVER_WORKERS=8 php -S 0.0.0.0:8000 "$ROOT/infra/dev-router.php" > "$LOG/web.log" 2>&1 & echo $! > "$LOG/web.pid"
    sleep 3; echo "stack started (logs in $LOG)";;
  stop)
    for n in livekit reverb queue scheduler web; do [ -f "$LOG/$n.pid" ] && kill "$(cat "$LOG/$n.pid")" 2>/dev/null || true; done; echo stopped;;
esac
