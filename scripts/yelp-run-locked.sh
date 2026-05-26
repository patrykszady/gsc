#!/usr/bin/env bash
# OS-level mutex around any Yelp browser automation invocation.
#
# Guarantees only ONE Yelp Node/Chromium process runs at a time on this host,
# regardless of release path, queue worker, or stale code.
#
# Usage (from PHP):
#   scripts/yelp-run-locked.sh <node-binary> scripts/yelp-upload-business-photo.mjs --photo=... ...
#
# Environment knobs:
#   YELP_RUN_LOCK_FILE   path to lock file (default /tmp/yelp-puppeteer.lock)
#   YELP_RUN_LOCK_WAIT   seconds to wait for lock before giving up (default 30)
#   YELP_RUN_TIMEOUT     hard kill timeout for the wrapped command (default 300)

set -euo pipefail

LOCK_FILE="${YELP_RUN_LOCK_FILE:-/tmp/yelp-puppeteer.lock}"
LOCK_WAIT="${YELP_RUN_LOCK_WAIT:-30}"
RUN_TIMEOUT="${YELP_RUN_TIMEOUT:-300}"

if [[ $# -lt 1 ]]; then
  echo '{"ok":false,"error":"yelp-run-locked.sh: missing command"}'
  exit 2
fi

# Ensure lock file exists and is writable by current user.
touch "${LOCK_FILE}" 2>/dev/null || {
  echo "{\"ok\":false,\"error\":\"cannot create lock file ${LOCK_FILE}\"}"
  exit 2
}

exec 9>"${LOCK_FILE}"

# Non-blocking attempt first, then block up to LOCK_WAIT seconds.
if ! flock -w "${LOCK_WAIT}" 9; then
  echo "{\"ok\":false,\"error\":\"yelp-run-locked.sh: another Yelp automation is running (lock ${LOCK_FILE})\"}"
  exit 75
fi

# Record holder PID/cmd for diagnostics.
printf 'pid=%s\ncmd=%s\nstarted=%s\n' "$$" "$*" "$(date -Iseconds)" >&9 || true

# Wrap with timeout so a stuck Chromium can't hold the lock forever.
# --kill-after sends SIGKILL 15s after SIGTERM if the child ignores it.
if command -v timeout >/dev/null 2>&1; then
  timeout --signal=TERM --kill-after=15 "${RUN_TIMEOUT}" "$@"
  exit_code=$?
else
  "$@"
  exit_code=$?
fi

exit "${exit_code}"
