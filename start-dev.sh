#!/bin/bash

# Always run from project root
cd "$(dirname "$0")"

LOG_DIR="storage/logs/dev"
mkdir -p "$LOG_DIR"

echo "🚀 Starting GSC dev environment..."

# Clear Laravel caches to re-read .env
echo "🧹 Clearing Laravel caches..."
php artisan config:clear --no-interaction >"$LOG_DIR/config_clear.log" 2>&1 || true

# Start Laravel dev server
if lsof -Pi :8003 -sTCP:LISTEN -t >/dev/null 2>&1; then
  SERVE_PID=$(lsof -Pi :8003 -sTCP:LISTEN -t)
  echo "✅ Laravel server already running (pid: $SERVE_PID)"
else
  echo "🔄 Starting Laravel dev server (http://127.0.0.1:8003)..."
  nohup php -d upload_max_filesize=10M -d post_max_size=500M -d max_file_uploads=100 artisan serve --host=127.0.0.1 --port=8003 --no-interaction >"$LOG_DIR/serve.log" 2>&1 &
  SERVE_PID=$!
  sleep 0.7
  if ps -p "$SERVE_PID" >/dev/null 2>&1; then
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
    echo "✅ Vite already running (pid: $VITE_PID)"
  else
    echo "🔄 Starting Vite (npm run dev)..."
    nohup npm run dev >"$LOG_DIR/vite.log" 2>&1 &
    VITE_PID=$!
    sleep 0.7
    if ps -p "$VITE_PID" >/dev/null 2>&1; then
      echo "✅ Vite dev server started (pid: $VITE_PID) → logs: $LOG_DIR/vite.log"
    else
      echo "❌ Vite failed → check logs: $LOG_DIR/vite.log"
    fi
  fi
fi

echo ""
echo "🎉 GSC running: http://127.0.0.1:8003"
