#!/usr/bin/env bash
# Pull production storage (all uploaded media) into this local checkout.
#
# Usage: composer storage:pull [-- --delete] [--full] [--dry-run]
#        ./scripts/pull-prod-storage.sh [--delete] [--full] [--dry-run]
#
#   --delete   Remove local files that no longer exist on the server. Off by
#              default so a stray local file is never silently destroyed.
#   --full     Sync the whole storage/ tree (logs, framework caches, puppeteer
#              profiles). Default is the media only: storage/app/public
#              (project photos, ~1.1G) and storage/app/private.
#   --dry-run  Show what rsync would transfer and change nothing.
#
# Mirrors ~/web/hive2025/scripts/pull-prod-storage.sh so both repos behave the
# same. That script also has a `gsc` target, but it syncs into
# ~/web/gs.construction — a different path from this checkout — so this repo
# carries its own copy rather than depending on that one being pointed here.
#
# Production runs a zero-downtime deploy: releases/ + a `current` symlink, with
# ONE persistent storage/ at the site root that each release symlinks to. That
# root storage/ is what we pull.

set -euo pipefail

REMOTE_HOST="${GSC_PROD_HOST:-${HIVE_PROD_HOST:-hive-prod}}"
REMOTE_PATH="${GSC_PROD_PATH:-/home/forge/gs.construction}"
LOCAL_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

EXTRA_FLAGS=()
SUBPATHS=("storage/app/public/" "storage/app/private/")

for arg in "$@"; do
    case "$arg" in
        --delete)  EXTRA_FLAGS+=(--delete) ;;
        --dry-run) EXTRA_FLAGS+=(--dry-run) ;;
        --full)    SUBPATHS=("storage/") ;;
        -h|--help)
            sed -n '2,20p' "$0"
            exit 0
            ;;
        *)
            echo "Unknown option: $arg" >&2
            exit 1
            ;;
    esac
done

if ! ssh -o BatchMode=yes -o ConnectTimeout=8 "$REMOTE_HOST" true 2>/dev/null; then
    echo "Cannot reach ${REMOTE_HOST} over SSH." >&2
    echo "Check your ~/.ssh/config Host entry, or set GSC_PROD_HOST." >&2
    exit 1
fi

cd "$LOCAL_ROOT"

for SUBPATH in "${SUBPATHS[@]}"; do
    mkdir -p "$SUBPATH"

    echo "→ Pulling ${REMOTE_HOST}:${REMOTE_PATH}/${SUBPATH}"
    echo "  into ${LOCAL_ROOT}/${SUBPATH}"

    rsync -avz --human-readable --progress \
        --exclude='framework/cache/data/*' \
        --exclude='framework/sessions/*' \
        --exclude='framework/views/*' \
        --exclude='framework/testing/*' \
        --exclude='logs/*.log' \
        --exclude='debugbar/*' \
        --exclude='clockwork/*' \
        --exclude='livewire-tmp/*' \
        --exclude='*-puppeteer/*' \
        --exclude='yelp-runtime/*' \
        --exclude='yelp-novnc-web/*' \
        --exclude='*cookies.json' \
        "${EXTRA_FLAGS[@]}" \
        "${REMOTE_HOST}:${REMOTE_PATH}/${SUBPATH}" \
        "./${SUBPATH}" \
        || echo "  (skipped ${SUBPATH}: not present on remote or partial transfer)"
done

# public/storage → storage/app/public. Pulling files is useless if the symlink
# the browser reads through is missing (a fresh checkout has no symlink).
if [ ! -e public/storage ]; then
    echo "→ public/storage symlink missing; creating it"
    php artisan storage:link
fi

echo "✓ Done."
