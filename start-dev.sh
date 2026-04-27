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
  pkill -f "local-ssl-proxy" >/dev/null 2>&1 || true
  pkill -f "cloudflared tunnel --config $CLOUDFLARED_CONFIG run" >/dev/null 2>&1 || true

  # Stop Vite started from this project.
  pkill -f "$PWD/node_modules/.bin/vite" >/dev/null 2>&1 || true
fi

# Clear Laravel caches to re-read .env
echo "🧹 Clearing Laravel caches..."
php artisan config:clear --no-interaction >"$LOG_DIR/config_clear.log" 2>&1 || true

# Start Laravel dev server on internal HTTP port
if lsof -Pi :"$APP_HTTP_PORT" -sTCP:LISTEN -t >/dev/null 2>&1; then
  SERVE_PID=$(lsof -Pi :"$APP_HTTP_PORT" -sTCP:LISTEN -t)
  echo "✅ Laravel server already running on http://$APP_HOST:$APP_HTTP_PORT (pid: $SERVE_PID)"
else
  echo "🔄 Starting Laravel dev server (http://$APP_HOST:$APP_HTTP_PORT)..."
  setsid -f php -d upload_max_filesize=50M -d post_max_size=0 -d max_file_uploads=10000 artisan serve --host="$APP_HOST" --port="$APP_HTTP_PORT" --no-interaction >"$LOG_DIR/serve.log" 2>&1 < /dev/null
  sleep 0.7
  SERVE_PID=$(pgrep -f "artisan serve --host=$APP_HOST --port=$APP_HTTP_PORT" | head -n 1)
  if [ -n "$SERVE_PID" ] && ps -p "$SERVE_PID" >/dev/null 2>&1; then
    echo "✅ Laravel server started (pid: $SERVE_PID) → logs: $LOG_DIR/serve.log"
  else
    echo "❌ Laravel server failed → check logs: $LOG_DIR/serve.log"
  fi
fi

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
