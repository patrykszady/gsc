#!/usr/bin/env bash
# Zero-downtime deploy for gs.construction on Forge.
#
# The old script deployed IN PLACE (git reset inside `current`), so the whole
# site had to sit in maintenance while composer, `npm ci` (~5 min on this
# 2-core box) and the build ran: every push meant ~5 minutes of 503. This
# builds a fresh release next to the live one — the live one keeps serving —
# and switches the `current` symlink only when the new release is ready.
# No maintenance mode at all.
#
# Layout (already in place since January):
#   /home/forge/gs.construction/.env          Forge-managed env (each release symlinks to it)
#   /home/forge/gs.construction/storage/app   shared uploads (each release symlinks storage/app to it)
#   /home/forge/gs.construction/releases/*    one directory per release
#   /home/forge/gs.construction/current       symlink to the live release
#   /home/forge/gs.construction/repo          persistent git checkout this script runs from
#
# The Forge deploy script is a short bootstrap (see deploy/forge-script.txt)
# that updates `repo` and runs this file, so the deploy logic is versioned
# with the code. Env from Forge: FORGE_PHP, FORGE_COMPOSER, FORGE_PHP_FPM.
#
# DEPLOY_STOP_BEFORE_SWITCH=1 builds the release and exits without switching
# (used to validate the script the first time).

set -euo pipefail

SITE=/home/forge/gs.construction
REPO="${DEPLOY_REPO:-$SITE/repo}"
RELEASES=$SITE/releases
PHP="${FORGE_PHP:-php}"
COMPOSER="${FORGE_COMPOSER:-composer}"
FPM="${FORGE_PHP_FPM:-php8.3-fpm}"
KEEP=3

NEW=$RELEASES/$(date +%Y%m%d%H%M%S)
PREV=$(readlink -f "$SITE/current" 2>/dev/null || true)

log() { printf '\n== %s  (%s)\n' "$1" "$(date +%H:%M:%S)"; }

log "New release: $NEW (live: ${PREV:-none})"
mkdir -p "$RELEASES"
# A real clone (from the local checkout — fast, no network), with origin set
# to the real remote, so a release is also a valid in-place checkout: the
# previous Forge script (git reset inside `current`) keeps working on it if
# it ever runs again.
git clone -q --branch "$(git -C "$REPO" rev-parse --abbrev-ref HEAD)" "$REPO" "$NEW"
git -C "$NEW" remote set-url origin "$(git -C "$REPO" remote get-url origin)"
git -C "$REPO" rev-parse --short HEAD > "$NEW/.deployed-commit"

log "Shared env + storage"
ln -sfn "$SITE/.env" "$NEW/.env"
rm -rf "$NEW/storage/app"
ln -s "$SITE/storage/app" "$NEW/storage/app"
mkdir -p "$NEW"/storage/framework/{cache/data,sessions,testing,views} "$NEW/storage/logs"

# Warm the new release with the live release's vendor/ and node_modules/ so
# composer and npm only apply the diff instead of installing from scratch.
if [ -n "$PREV" ] && [ -d "$PREV/vendor" ]; then
    log "Seeding vendor/ from live release"
    cp -a "$PREV/vendor" "$NEW/vendor"
fi
if [ -n "$PREV" ] && [ -d "$PREV/node_modules" ]; then
    log "Seeding node_modules/ from live release"
    cp -a "$PREV/node_modules" "$NEW/node_modules"
fi

cd "$NEW"

log "composer install"
$COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress

log "npm install + build"
# `npm install` on a seeded node_modules applies only the lockfile diff;
# `npm ci` would delete it and reinstall everything (the 5-minute step).
npm install --no-audit --no-fund --prefer-offline
npm run build

log "Caches for the new release"
$PHP artisan storage:link --force
$PHP artisan optimize

log "Migrate"
# Runs against the database the live release is still serving. Migrations
# here are additive in practice; anything destructive must be deployed in two
# steps (code that stops using a column first, the drop later).
$PHP artisan migrate --force

if [ "${DEPLOY_STOP_BEFORE_SWITCH:-0}" = "1" ]; then
    log "Stopping before switch (DEPLOY_STOP_BEFORE_SWITCH=1). Built: $NEW"
    exit 0
fi

log "Switch current → $NEW"
ln -sfn "$NEW" "$SITE/current.next"
mv -Tf "$SITE/current.next" "$SITE/current"

log "Reload PHP-FPM (opcache) and restart Horizon"
( flock -w 10 9 || exit 1; sudo -S service "$FPM" reload ) 9>/tmp/fpmlock || echo "FPM reload skipped (no sudo); opcache revalidates on its own"
cd "$SITE/current"
$PHP artisan horizon:terminate --wait || true

log "Post-deploy (never fatal)"
$PHP artisan sitemap:generate --url="https://gs.construction" || true
$PHP artisan seo:gsc-submit-sitemaps || true
$PHP artisan geo:llms-txt || true
$PHP artisan geo:llms-txt --full || true
$PHP artisan indexnow:submit --all || true

log "Prune old releases (keep $KEEP)"
ls -1dt "$RELEASES"/*/ | tail -n +$((KEEP + 1)) | xargs -r rm -rf

log "Deployed $(cat "$SITE/current/.deployed-commit") → $(readlink -f "$SITE/current")"
