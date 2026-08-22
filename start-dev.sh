#!/bin/bash

# Always run from project root
cd "$(dirname "$0")"

LOG_DIR="storage/logs/dev"
mkdir -p "$LOG_DIR"

APP_HTTP_PORT="${APP_HTTP_PORT:-8003}"
APP_HOST="${APP_HOST:-127.0.0.1}"
FORCE_RESTART=false

for arg in "$@"; do
  if [ "$arg" = "--force" ] || [ "$arg" = "-f" ]; then
    FORCE_RESTART=true
  fi
done

CLOUDFLARED_CONFIG="${CLOUDFLARED_CONFIG:-$HOME/.cloudflared/config-gsc.yml}"
CLOUDFLARED_LOG="${CLOUDFLARED_LOG:-$LOG_DIR/cloudflared-gsc.log}"

echo "🚀 Starting GSC dev environment..."

if [ "$FORCE_RESTART" = true ]; then
  echo "♻️  Force mode enabled: stopping existing dev processes..."

  # Stop listeners on app port.
  while lsof -Pi :"$APP_HTTP_PORT" -sTCP:LISTEN -t >/dev/null 2>&1; do
    PID_TO_KILL=$(lsof -Pi :"$APP_HTTP_PORT" -sTCP:LISTEN -t | head -n 1)
    kill "$PID_TO_KILL" >/dev/null 2>&1 || true
    sleep 0.2
  done

  # Stop known process patterns that may survive port-based kills.
  pkill -f "artisan serve --host=$APP_HOST --port=$APP_HTTP_PORT" >/dev/null 2>&1 || true

  # Sibling apps this script also owns.
  for SIBLING_PORT in "${SS_HTTP_PORT:-8001}" "${JPETERSON_HTTP_PORT:-8004}"; do
    while lsof -Pi :"$SIBLING_PORT" -sTCP:LISTEN -t >/dev/null 2>&1; do
      kill "$(lsof -Pi :"$SIBLING_PORT" -sTCP:LISTEN -t | head -n 1)" >/dev/null 2>&1 || true
      sleep 0.2
    done
    pkill -f "artisan serve --host=$APP_HOST --port=$SIBLING_PORT" >/dev/null 2>&1 || true
  done
  pkill -f "local-ssl-proxy" >/dev/null 2>&1 || true
  pkill -f "cloudflared tunnel --config $CLOUDFLARED_CONFIG run" >/dev/null 2>&1 || true

  # Stop Vite started from this project.
  pkill -f "$PWD/node_modules/.bin/vite" >/dev/null 2>&1 || true
fi

# Clear Laravel caches to re-read .env
echo "🧹 Clearing Laravel caches..."
php artisan config:clear --no-interaction >"$LOG_DIR/config_clear.log" 2>&1 || true

# Start a Laravel dev server for one app, concurrency-safe and idempotent.
#
# PHP_CLI_SERVER_WORKERS + --no-reload, together, are load-bearing:
#
#   These apps call each other. /admin here proxies to ss-systems, and
#   ss-systems calls straight back into this app's management API. With the
#   default single worker that deadlocks — this app's only worker sits waiting
#   on ss-systems, so ss-systems' callback has nobody to answer it, and /admin
#   dies on a timeout showing "Admin is temporarily unavailable".
#
#   Laravel IGNORES the worker count unless --no-reload is passed (it warns,
#   then quietly serves a single worker). The trade-off: .env and config edits
#   need a restart of this script. Ordinary PHP/Blade edits reload as usual.
#
# Usage: start_laravel <dir> <port> <label> [extra php -d flags…]
start_laravel() {
  local APP_DIR="$1" PORT="$2" LABEL="$3"
  shift 3
  local PHP_FLAGS=("$@")

  if [ ! -f "$APP_DIR/artisan" ]; then
    echo "⏭️  $LABEL: skipped (no app at $APP_DIR)"
    return 0
  fi

  if lsof -Pi :"$PORT" -sTCP:LISTEN -t >/dev/null 2>&1; then
    echo "✅ $LABEL already running on http://$APP_HOST:$PORT (pid: $(lsof -Pi :"$PORT" -sTCP:LISTEN -t | head -n 1))"
    return 0
  fi

  echo "🔄 Starting $LABEL (http://$APP_HOST:$PORT)..."
  (
    cd "$APP_DIR" || exit 1
    mkdir -p storage/logs/dev
    setsid -f env PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-6}" \
      php "${PHP_FLAGS[@]}" artisan serve \
        --host="$APP_HOST" --port="$PORT" --no-reload --no-interaction \
      >"storage/logs/dev/serve.log" 2>&1 < /dev/null
  )
  sleep 0.9

  if lsof -Pi :"$PORT" -sTCP:LISTEN -t >/dev/null 2>&1; then
    echo "✅ $LABEL started (pid: $(lsof -Pi :"$PORT" -sTCP:LISTEN -t | head -n 1)) → logs: $APP_DIR/storage/logs/dev/serve.log"
  else
    echo "❌ $LABEL failed → check $APP_DIR/storage/logs/dev/serve.log"
  fi
}

# This app.
start_laravel "$PWD" "$APP_HTTP_PORT" "GSC" -d upload_max_filesize=50M -d post_max_size=0 -d max_file_uploads=10000

# Sibling apps on this dev box. ss-systems is REQUIRED for /admin here: that
# route is a transparent proxy to the central admin, and without it every
# /admin page renders the "temporarily unavailable" fallback.
start_laravel "${SS_SYSTEMS_DIR:-/home/patryk/web/ss-systems}" "${SS_HTTP_PORT:-8001}" "SS Systems (central admin)"
start_laravel "${JPETERSON_DIR:-/home/patryk/web/jpeterson-design}" "${JPETERSON_HTTP_PORT:-8004}" "J. Peterson Design"

# Install npm deps if needed, then start Vite
if [ -f package.json ]; then
  if [ ! -d node_modules ]; then
    echo "📦 Installing npm dependencies..."
    npm install >"$LOG_DIR/npm_install.log" 2>&1 || true
  fi

  if lsof -Pi :5173 -sTCP:LISTEN -t >/dev/null 2>&1; then
    VITE_PID=$(lsof -Pi :5173 -sTCP:LISTEN -t)
    VITE_CMD=$(ps -p "$VITE_PID" -o command= 2>/dev/null || true)
    if echo "$VITE_CMD" | grep -q "$PWD/node_modules/.bin/vite"; then
      echo "✅ Vite already running (pid: $VITE_PID)"
    else
      if [ "$FORCE_RESTART" = true ]; then
        echo "⚠️  Port 5173 is used by another Vite process (pid: $VITE_PID); replacing it"
        kill "$VITE_PID" >/dev/null 2>&1 || true
        sleep 0.4
        echo "🔄 Starting Vite (npm run dev)..."
        setsid -f npm run dev >"$LOG_DIR/vite.log" 2>&1 < /dev/null
        sleep 0.7
        VITE_PID=$(lsof -Pi :5173 -sTCP:LISTEN -t 2>/dev/null | head -n 1)
        if [ -n "$VITE_PID" ] && ps -p "$VITE_PID" >/dev/null 2>&1; then
          echo "✅ Vite dev server started (pid: $VITE_PID) → logs: $LOG_DIR/vite.log"
        else
          echo "❌ Vite failed → check logs: $LOG_DIR/vite.log"
        fi
      else
        echo "⚠️  Port 5173 is already used by another process (pid: $VITE_PID)"
        echo "   Use --force to replace it with this project's Vite"
      fi
    fi
  else
    echo "🔄 Starting Vite (npm run dev)..."
    setsid -f npm run dev >"$LOG_DIR/vite.log" 2>&1 < /dev/null
    sleep 0.7
    VITE_PID=$(lsof -Pi :5173 -sTCP:LISTEN -t 2>/dev/null | head -n 1)
    if [ -n "$VITE_PID" ] && ps -p "$VITE_PID" >/dev/null 2>&1; then
      echo "✅ Vite dev server started (pid: $VITE_PID) → logs: $LOG_DIR/vite.log"
    else
      echo "❌ Vite failed → check logs: $LOG_DIR/vite.log"
    fi
  fi
fi

echo ""
echo "🎉 GSC running: http://$APP_HOST:$APP_HTTP_PORT"

if [ -n "${WSL_DISTRO_NAME:-}" ]; then
  WSL_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
  if [ -n "$WSL_IP" ]; then
    echo "   WSL browser fallback: http://$WSL_IP:$APP_HTTP_PORT"
  fi
fi

# Start Cloudflare tunnel for public dev URL if config exists.
if command -v cloudflared >/dev/null 2>&1 && [ -f "$CLOUDFLARED_CONFIG" ]; then
  if pgrep -f "cloudflared tunnel --config $CLOUDFLARED_CONFIG run" >/dev/null 2>&1; then
    TUNNEL_PID=$(pgrep -f "cloudflared tunnel --config $CLOUDFLARED_CONFIG run" | head -n 1)
    echo "✅ Cloudflare tunnel already running (pid: $TUNNEL_PID)"
  else
    echo "🔄 Starting Cloudflare tunnel (config: $CLOUDFLARED_CONFIG)..."
    setsid -f cloudflared tunnel --config "$CLOUDFLARED_CONFIG" run >"$CLOUDFLARED_LOG" 2>&1 < /dev/null
    sleep 0.7
    TUNNEL_PID=$(pgrep -f "cloudflared tunnel --config $CLOUDFLARED_CONFIG run" | head -n 1)
    if [ -n "$TUNNEL_PID" ] && ps -p "$TUNNEL_PID" >/dev/null 2>&1; then
      echo "✅ Cloudflare tunnel started (pid: $TUNNEL_PID) → logs: $CLOUDFLARED_LOG"
    else
      echo "❌ Cloudflare tunnel failed → check logs: $CLOUDFLARED_LOG"
    fi
  fi
elif [ ! -f "$CLOUDFLARED_CONFIG" ]; then
  echo "⚠️  Skipping Cloudflare tunnel: config not found at $CLOUDFLARED_CONFIG"
else
  echo "⚠️  Skipping Cloudflare tunnel: cloudflared CLI not found"
fi

echo "🌐 Public dev URL: https://dev.gs.construction"

# Per-tenant local URLs. Printed from the sites table, so a new tenant appears
# here the day its row exists, with nothing to maintain. *.localhost is mapped
# to loopback by the browser itself — no hosts file needed.
echo ""
echo "🏢 Sites on this platform:"
php artisan tinker --execute='foreach (App\Models\Site::listAll() as $s) { printf("   %-34s %-12s %s%s", "http://".$s->devHost().":8003/", $s->slug, $s->is_active ? "" : "(in build) ", PHP_EOL); }' 2>/dev/null \
  || echo "   (could not read the sites table)"
echo "   http://127.0.0.1:8003/_sites        register + path checker"
echo ""
echo "🔐 Admin:"
echo "   http://127.0.0.1:$APP_HTTP_PORT/admin              central admin (proxied to :${SS_HTTP_PORT:-8001})"
echo "   http://127.0.0.1:$APP_HTTP_PORT/admin-legacy       this app's own screens"
