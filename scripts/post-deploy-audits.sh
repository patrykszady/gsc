#!/usr/bin/env bash
# Post-deploy audit + sync runner.
#
# Tiers:
#   1. AUDITS    – read-only, safe to run any time, no external mutations.
#   2. SYNCS     – pull data from external APIs into our DB (idempotent).
#   3. OPTIONAL  – mutates external systems or costs API tokens; opt-in.
#
# Usage:
#   ./scripts/post-deploy-audits.sh              # tiers 1+2
#   ./scripts/post-deploy-audits.sh audits       # tier 1 only
#   ./scripts/post-deploy-audits.sh syncs        # tier 2 only
#   ./scripts/post-deploy-audits.sh all          # tiers 1+2+3 (DANGER: mutates GBP)
#
# Interactive commands (google-business-profile:auth, seo:gsc-auth) are NEVER
# run from here — run them manually when refresh tokens expire.

set -u
cd "$(dirname "$0")/.." || exit 1

MODE="${1:-default}"
LOG_DIR="storage/logs/post-deploy"
mkdir -p "$LOG_DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"
LOG="$LOG_DIR/run-$STAMP.log"

pass=0; fail=0; warn=0
# Commands whose non-zero exit means "audit found real issues to review"
# rather than "command crashed". These count as warnings, not failures.
AUDIT_REPORTS_RE='^(seo:audit|seo:audit-quickwins|seo:health|seo:health-check|seo:image-schema-audit|seo:image-audit|seo:schema-audit|seo:gbp-parity|seo:content-decay|seo:content-gap|seo:title-audit|seo:gsc-monitor|seo:internal-link-audit|seo:internal-link-suggest|seo:area-pages-audit|seo:content-depth-audit|seo:competitor-schema-gap|seo:competitor-brand-track|seo:cwv-template|gbp:geotag-audit|gbp:unresponded-reviews)( |$)'

is_audit_report() {
    [[ "$1" =~ $AUDIT_REPORTS_RE ]]
}

run() {
    local label="$1"; shift
    local cmd="$*"
    echo "" | tee -a "$LOG"
    echo "──▶ $label" | tee -a "$LOG"
    echo "    $ php artisan $cmd" | tee -a "$LOG"
    if php artisan "$@" >>"$LOG" 2>&1; then
        echo "    ✓ ok" | tee -a "$LOG"
        pass=$((pass+1))
    elif is_audit_report "$cmd"; then
        echo "    ⚠ findings (review log)" | tee -a "$LOG"
        warn=$((warn+1))
    else
        echo "    ✗ FAILED (see $LOG)" | tee -a "$LOG"
        fail=$((fail+1))
    fi
}

run_shell() {
    local label="$1"; shift
    local cmd="$*"
    echo "" | tee -a "$LOG"
    echo "──▶ $label" | tee -a "$LOG"
    echo "    $ $cmd" | tee -a "$LOG"
    if eval "$cmd" >>"$LOG" 2>&1; then
        echo "    ✓ ok" | tee -a "$LOG"
        pass=$((pass+1))
    else
        echo "    ✗ FAILED (see $LOG)" | tee -a "$LOG"
        fail=$((fail+1))
    fi
}

verify_sitemap() {
    local sitemap="public/sitemap.xml"

    [[ -f "$sitemap" ]] || {
        echo "sitemap.xml missing"
        return 1
    }

    local first_url
    first_url="$(grep -oP '(?<=<loc>)[^<]+' "$sitemap" | head -1)"

    [[ -n "$first_url" ]] || {
        echo "sitemap.xml has no <loc> entries"
        return 1
    }

    # Homepage canonical is slashless: https://gs.construction
    if [[ "$first_url" =~ /$ ]]; then
        echo "first sitemap URL has trailing slash: $first_url"
        return 1
    fi

    local non_html_count
    non_html_count="$(grep -oP '(?<=<loc>)[^<]+' "$sitemap" | grep -Ec '\\.(txt|json|xml|webmanifest|ico)$' || true)"
    if [[ "$non_html_count" -gt 0 ]]; then
        echo "sitemap contains non-HTML URLs: $non_html_count"
        return 1
    fi

    return 0
}

echo "Post-deploy run – mode=$MODE – log=$LOG"

# ── Tier 1: AUDITS ────────────────────────────────────────────────────────
if [[ "$MODE" == "default" || "$MODE" == "audits" || "$MODE" == "all" ]]; then
    echo "" | tee -a "$LOG"
    echo "═══ Tier 1: Audits (read-only) ═══" | tee -a "$LOG"

    # GBP audits
    run "GBP health"                   google-business-profile:health
    run "GBP locations"                google-business-profile:locations
    run "GBP geotag audit"             gbp:geotag-audit
    run "GBP unresponded reviews"      gbp:unresponded-reviews
    run "GBP Q&A checklist"            gbp:qna-checklist

    # SEO on-site audits
    run "SEO schema audit"             seo:schema-audit
    run "SEO image schema audit"       seo:image-schema-audit
    run "SEO image audit"              seo:image-audit
    run "SEO area pages audit"         seo:area-pages-audit --markdown
    run "SEO health check"             seo:health-check --markdown
    run "SEO internal link audit"      seo:internal-link-audit --min=3 --limit=250
    run "SEO internal link suggest"    seo:internal-link-suggest
    run "SEO content depth audit"      seo:content-depth-audit
    run "SEO GBP parity"               seo:gbp-parity
    run "SEO audit"                    seo:audit
    run "SEO audit quickwins"          seo:audit-quickwins

    # SEO data-driven audits (use already-synced GSC data; safe)
    run "SEO health (composite)"       seo:health
    run "SEO content decay"            seo:content-decay
    run "SEO content gap"              seo:content-gap
    run "SEO content strategy"         seo:content-strategy
    run "SEO title audit"              seo:title-audit
    run "SEO GSC monitor"              seo:gsc-monitor
    run "SEO Clarity health"           seo:clarity-health --markdown
    run "SEO GSC top"                  seo:gsc-top
    run "SEO show rankings"            seo:show-rankings
    run "SEO CWV by template"          seo:cwv-template
    run "SEO competitor brand track"   seo:competitor-brand-track
    run "SEO competitor schema gap"    seo:competitor-schema-gap
fi

# ── Tier 2: SYNCS ─────────────────────────────────────────────────────────
if [[ "$MODE" == "default" || "$MODE" == "syncs" || "$MODE" == "all" ]]; then
    echo "" | tee -a "$LOG"
    echo "═══ Tier 2: Syncs (pull external → DB) ═══" | tee -a "$LOG"

    # Always regenerate sitemap after deploy and fail fast on malformed output.
    run "Sitemap generate"             sitemap:generate
    run_shell "Sitemap validate"       verify_sitemap

    run "GSC sync"                     seo:gsc-sync
    run "Bing sync"                    seo:bing-sync
    run "Clarity sync"                 seo:clarity-sync --days=3
    run "GBP metrics sync"             gbp:metrics-sync
    run "GBP sync-reviews"             google-business-profile:sync-reviews
    run "GBP match-reviews"            google-business-profile:match-reviews --normalize-google-urls
    run "GBP sync media (queued)"      google-business-profile:sync --upload --queue
    run "PSI snapshot"                 seo:psi-sync
    run "Backlinks monitor"            seo:backlinks-monitor
fi

# ── Tier 3: OPTIONAL (mutates external systems / costs tokens) ────────────
if [[ "$MODE" == "all" ]]; then
    echo "" | tee -a "$LOG"
    echo "═══ Tier 3: Optional / mutating ═══" | tee -a "$LOG"

    run "Rank tracking (SerpApi)"      seo:track-rankings --engine=both
    run "Competitor rank gap"          seo:competitor-rank-gap
    run "Competitor gap (briefs)"      seo:competitor-gap
    run "Reindex problem pages"        seo:reindex-problem-pages
    run "404 → IndexNow"               seo:404-indexnow
    # The two below mutate the live Google Business Profile — uncomment intentionally:
    # run "GBP services sync"          gbp:services-sync
    # run "GBP update-profile"         google-business-profile:update-profile
fi

echo "" | tee -a "$LOG"
echo "═══ Summary ═══" | tee -a "$LOG"
echo "  passed: $pass  findings: $warn  failed: $fail" | tee -a "$LOG"
echo "  log:    $LOG" | tee -a "$LOG"

exit $(( fail > 0 ? 1 : 0 ))
