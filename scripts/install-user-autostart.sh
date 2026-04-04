#!/bin/bash
set -euo pipefail

SERVICE_NAME="gsc-dev.service"
SOURCE_SERVICE="$(cd "$(dirname "$0")" && pwd)/systemd/$SERVICE_NAME"
USER_SYSTEMD_DIR="$HOME/.config/systemd/user"
TARGET_SERVICE="$USER_SYSTEMD_DIR/$SERVICE_NAME"
PROJECT_ROOT="/home/patryk/web/gsc"
START_CMD="cd $PROJECT_ROOT && ./start-dev.sh >> $PROJECT_ROOT/storage/logs/dev/autostart.log 2>&1"
CRON_MARKER="# gsc-dev-autostart"

install_cron_fallback() {
  if ! command -v crontab >/dev/null 2>&1; then
    echo "crontab not found. Could not configure autostart automatically."
    exit 1
  fi

  CURRENT_CRON="$(crontab -l 2>/dev/null || true)"
  if echo "$CURRENT_CRON" | grep -F "$CRON_MARKER" >/dev/null 2>&1; then
    echo "Cron autostart already installed."
    exit 0
  fi

  {
    echo "$CURRENT_CRON"
    echo "@reboot $START_CMD $CRON_MARKER"
  } | crontab -

  echo "Installed cron autostart fallback (@reboot)."
}

if ! command -v systemctl >/dev/null 2>&1; then
  install_cron_fallback
  exit 0
fi

mkdir -p "$USER_SYSTEMD_DIR"
cp "$SOURCE_SERVICE" "$TARGET_SERVICE"

if systemctl --user daemon-reload >/dev/null 2>&1 && systemctl --user enable --now "$SERVICE_NAME" >/dev/null 2>&1; then
  echo "Installed and started $SERVICE_NAME"
  systemctl --user status "$SERVICE_NAME" --no-pager -n 20 || true
else
  echo "Systemd user bus unavailable. Switching to cron fallback."
  install_cron_fallback
fi
