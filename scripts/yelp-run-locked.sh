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

# Run the wrapped command in its OWN process group / session so we can
# nuke the entire descendant tree (node + headed Chromium + helper procs)
# on timeout. Without this, `timeout` kills only its direct child (node);
# Chromium lingers, keeps stdout/stderr pipes open, and the parent PHP
# Symfony Process blocks forever waiting for EOF.
USERDATA_DIR="${YELP_USER_DATA_DIR:-}"
TMP_OUT_DIR="$(mktemp -d -t yelp-run.XXXXXX)"
CHILD_OUT="${TMP_OUT_DIR}/stdout"
CHILD_ERR="${TMP_OUT_DIR}/stderr"
mkfifo "${CHILD_OUT}" "${CHILD_ERR}" 2>/dev/null || true

cleanup_pgid() {
  local pgid="$1"
  if [[ -n "${pgid}" ]]; then
    kill -TERM "-${pgid}" 2>/dev/null || true
    sleep 1
    kill -KILL "-${pgid}" 2>/dev/null || true
  fi
  # Belt-and-suspenders: kill any chromium still holding our userDataDir.
  if [[ -n "${USERDATA_DIR}" ]]; then
    pkill -KILL -f "user-data-dir=${USERDATA_DIR}" 2>/dev/null || true
  fi
  rm -rf "${TMP_OUT_DIR}" 2>/dev/null || true
}

# setsid starts the child as its own session leader (new pgid == its pid).
setsid "$@" &
child_pid=$!
# Process group id equals the child pid since it became session leader.
child_pgid=${child_pid}

# Schedule the hard kill in the background. Use SIGKILL after the
# configured timeout — the child has its own internal soft timeout
# (YELP_RUN_TIMEOUT - small buffer) to attempt a graceful close first.
(
  sleep "${RUN_TIMEOUT}"
  if kill -0 "${child_pid}" 2>/dev/null; then
    echo "[yelp-run-locked] HARD TIMEOUT after ${RUN_TIMEOUT}s - killing pgid ${child_pgid}" >&2
    cleanup_pgid "${child_pgid}"
  fi
) &
killer_pid=$!

# Forward SIGTERM/SIGINT from PHP straight to the child group.
trap 'cleanup_pgid "${child_pgid}"; kill "${killer_pid}" 2>/dev/null || true; exit 143' TERM INT

wait "${child_pid}"
exit_code=$?

# Child finished on its own — cancel the killer and clean up any zombies.
kill "${killer_pid}" 2>/dev/null || true
wait "${killer_pid}" 2>/dev/null || true
cleanup_pgid "${child_pgid}"

exit "${exit_code}"
