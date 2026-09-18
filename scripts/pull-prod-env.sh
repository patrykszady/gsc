#!/usr/bin/env bash
# Bring this machine's .env up to production's, keeping the handful of values
# that MUST stay local.
#
# Why a script you run rather than something the assistant does: pulling
# production credentials onto a workstation is exactly the action the agent
# tooling refuses, and rightly. This puts the decision in your hands.
#
# What it does NOT touch is the PRESERVE list below — the URLs, database
# credentials, app key, session and mail settings that make this a dev box.
# Everything else is taken from production, so integrations behave the same.
#
#   bash scripts/pull-prod-env.sh --dry-run    # list what would change
#   bash scripts/pull-prod-env.sh              # apply, after a backup
#
# READ THIS FIRST. With production's values in place, this machine talks to
# production services for real: hive.contractors receives leads you submit
# locally, connected mailboxes are read, and a connected Google Business
# Profile can be posted to. That is the point of matching, and it is also the
# risk. Nothing here is reversible except from the backup it writes.
set -euo pipefail

REMOTE_HOST="${GSC_PROD_HOST:-${HIVE_PROD_HOST:-hive-prod}}"
REMOTE_PATH="${GSC_PROD_PATH:-/home/forge/gs.construction/current}"
LOCAL_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$LOCAL_ROOT/.env"
DRY_RUN=0
[ "${1:-}" = "--dry-run" ] && DRY_RUN=1

# Keys whose LOCAL value always wins. Everything not listed comes from prod.
PRESERVE="APP_ENV APP_DEBUG APP_URL ASSET_URL APP_KEY
LOG_LEVEL LOG_CHANNEL LOG_DAILY_DAYS LOG_VIEWER_API_STATEFUL_DOMAINS
DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD
SESSION_DRIVER SESSION_DOMAIN SESSION_PATH SESSION_ENCRYPT SESSION_SECURE_COOKIE
CACHE_STORE QUEUE_CONNECTION
REDIS_HOST REDIS_PORT REDIS_PASSWORD
MAIL_MAILER MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD MAIL_FROM_ADDRESS MAIL_FROM_NAME
VITE_APP_NAME PHP_CLI_SERVER_WORKERS BCRYPT_ROUNDS
SS_URL PEER_JPETERSON_URL PEER_GSC_URL
YELP_REMOTE_LOGIN_PUBLIC_URL INSTAGRAM_REMOTE_LOGIN_PUBLIC_URL
YELP_REMOTE_LOGIN_WS_HOST YELP_REMOTE_LOGIN_NOVNC_WEB
FORGE_API_TOKEN"

[ -f "$ENV_FILE" ] || { echo "No .env at $ENV_FILE"; exit 1; }

REMOTE_ENV="$(mktemp)"; trap 'rm -f "$REMOTE_ENV"' EXIT
ssh -o ConnectTimeout=10 -o BatchMode=yes "$REMOTE_HOST" "cat $REMOTE_PATH/.env" > "$REMOTE_ENV"
[ -s "$REMOTE_ENV" ] || { echo "Could not read production's .env from $REMOTE_HOST:$REMOTE_PATH"; exit 1; }

MERGED="$(mktemp)"; trap 'rm -f "$REMOTE_ENV" "$MERGED"' EXIT

PRESERVE="$PRESERVE" ENV_FILE="$ENV_FILE" REMOTE_ENV="$REMOTE_ENV" DRY_RUN="$DRY_RUN" \
python3 - "$MERGED" <<'PY'
import os, sys, hashlib

keep = set(os.environ['PRESERVE'].split())
def read(p):
    out = []
    for line in open(p):
        s = line.rstrip('\n')
        if s.strip() and not s.lstrip().startswith('#') and '=' in s:
            out.append((s.split('=', 1)[0].strip(), s))
        else:
            out.append((None, s))
    return out

local, remote = read(os.environ['ENV_FILE']), read(os.environ['REMOTE_ENV'])
lmap = {k: v for k, v in local if k}
rmap = {k: v for k, v in remote if k}
def val(line): return line.split('=', 1)[1].strip(' "\'')
def fp(line): return hashlib.sha256(val(line).encode()).hexdigest()[:8]

changed, added, kept = [], [], []
out = []
for k, line in local:                       # keep local file order and comments
    if k is None: out.append(line); continue
    if k in keep or k not in rmap:
        out.append(line)
        if k in keep and k in rmap and fp(line) != fp(rmap[k]): kept.append(k)
    else:
        out.append(rmap[k])
        if fp(line) != fp(rmap[k]): changed.append(k)
for k, line in remote:                      # then anything production has that we lack
    if k and k not in lmap and k not in keep:
        out.append(line); added.append(k)

print(f"  {len(changed)} values updated from production:")
for k in sorted(changed): print(f"     {k}")
print(f"  {len(added)} keys added:")
for k in sorted(added): print(f"     {k}")
print(f"  {len(kept)} kept local on purpose (differ from production):")
for k in sorted(kept): print(f"     {k}")

if os.environ['DRY_RUN'] != '1':
    open(sys.argv[1], 'w').write('\n'.join(out) + '\n')
PY

if [ "$DRY_RUN" = "1" ]; then
    echo; echo "Dry run — nothing written."
    exit 0
fi

BACKUP="$ENV_FILE.backup-$(date +%Y%m%d-%H%M%S)"
cp "$ENV_FILE" "$BACKUP"
cp "$MERGED" "$ENV_FILE"
echo; echo "✓ .env updated. Previous file kept at $(basename "$BACKUP")."
echo "  Run 'php artisan config:clear' before the next request."
