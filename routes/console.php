<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Jobs\SendLeadToHive;
use App\Models\ContactSubmission;
use App\Models\Project;
use App\Models\Testimonial;
use App\Services\TestimonialProjectTypeClassifier;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Backward-compatible alias for legacy schedulers/cron entries.
Artisan::command('seo:gbp-metrics-sync
    {--days=14 : Days back to sync for daily metrics}
    {--location= : Override location ID (default from config)}
    {--with-keywords : Also sync monthly search keywords}
    {--queue : Deprecated no-op alias for compatibility}
    {--dry-run}
', function () {
    $this->warn('Deprecated command: use gbp:metrics-sync instead. Forwarding...');

    if ((bool) $this->option('queue')) {
        logger('seo-sync')->warning('Deprecated --queue option used for seo:gbp-metrics-sync alias', [
            'command_line' => implode(' ', $_SERVER['argv'] ?? []),
            'timestamp' => now()->toIso8601String(),
        ]);
        $this->warn('Option --queue is deprecated and ignored for seo:gbp-metrics-sync.');
    }

    return Artisan::call('gbp:metrics-sync', [
        '--days' => (int) $this->option('days'),
        '--location' => $this->option('location') ?: null,
        '--with-keywords' => (bool) $this->option('with-keywords'),
        '--queue' => (bool) $this->option('queue'),
        '--dry-run' => (bool) $this->option('dry-run'),
    ]);
})->purpose('Alias for legacy seo:gbp-metrics-sync command.');

// Schedule sitemap regeneration daily
Schedule::command('sitemap:generate')->daily();

// GEO/AI: regenerate AI-crawler feeds daily so llms.txt, llms-full.txt and the
// product feed stay fresh for ChatGPT/Gemini/Perplexity crawlers as content changes.
Schedule::command('geo:llms-txt')->dailyAt('01:40')
    ->appendOutputTo(storage_path('logs/geo-feeds.log'))
    ->onFailure(fn () => logger()->error('Scheduled geo:llms-txt failed'));
Schedule::command('geo:llms-txt --full')->dailyAt('01:42')
    ->appendOutputTo(storage_path('logs/geo-feeds.log'))
    ->onFailure(fn () => logger()->error('Scheduled geo:llms-txt --full failed'));
// NOTE: `geo:feed` (vendor ProductFeedGenerator) is intentionally NOT scheduled.
// It produced an empty /ai-product-feed.json for this site. The rich AI feed is
// served dynamically by App\Http\Controllers\AiFeedController at /ai-feed.json
// (linked from llms.txt via config('geo.feed.route')). Running geo:feed would
// write an empty static public/ai-feed.json that shadows the dynamic route.

// Hive (hive.contractors) project zip-counts sync — feeds the homepage map
Schedule::command('hive:sync')->dailyAt('02:00')
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->onFailure(fn () => logger()->error('Scheduled hive:sync failed'));

// Weekly health check of social/sameAs URLs
Schedule::command('socials:check --quiet-on-success')->weekly()
    ->onOneServer()
    ->withoutOverlapping(30)
    ->appendOutputTo(storage_path('logs/socials-check.log'));

// Weekly cleanup of stale front-end JS errors (/admin/js-errors). Resolves rows
// not seen in 30+ days so the dashboard reflects only active regressions.
Schedule::command('js-errors:resolve --stale=30 --force')->weekly()
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->onFailure(fn () => logger()->error('Scheduled js-errors:resolve failed'));

// Google Business Profile: health check + daily media sync
Schedule::command('google-business-profile:health')->daily()
    ->appendOutputTo(storage_path('logs/schedule.log'));
Schedule::command('google-business-profile:sync --upload --queue')->dailyAt('02:30')
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->onFailure(fn () => logger()->error('Scheduled GBP sync failed'));
Schedule::command('gsc:cleanup-gbp-jpegs --age=24')->dailyAt('03:30')
    ->appendOutputTo(storage_path('logs/schedule.log'));

// Yelp biz: verify the persisted browser session is still authenticated once a day.
Schedule::command('yelp:check-session')->dailyAt('04:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/schedule.log'));

// Google Business Profile: sync new reviews daily at 06:00 AM CT
Schedule::command('google-business-profile:sync-reviews')->dailyAt('06:00')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->onFailure(fn () => logger()->error('Scheduled GBP review sync failed'))
    ->when(fn () => config('services.google.business_profile.enabled'));

// Google Business Profile: harvest deep links daily until all matched reviews have data URLs.
Schedule::command('google-business-profile:match-reviews --normalize-google-urls')->dailyAt('06:15')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->onFailure(fn () => logger()->error('Scheduled GBP review match failed'))
    ->when(function () {
        if (! config('services.google.business_profile.enabled')) {
            return false;
        }

        return \App\Models\ReviewUrl::query()
            ->where('platform', 'google')
            ->whereNotNull('external_id')
            ->where('external_id', '!=', '')
            ->where(function ($q) {
                $q->whereNull('url')
                    ->orWhere('url', 'not like', '%/maps/reviews/data=%');
            })
            ->exists();
    });

// Houzz: check for new reviews weekly (create-only; skip existing)
Schedule::command('testimonials:sync-houzz-reviews --browser-scrape --only-new')->weeklyOn(1, '06:30')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->onFailure(fn () => logger()->error('Scheduled Houzz review sync failed'));

// Yelp: check for new reviews weekly (create-only; skip existing).
// Scrapes the public review feed / stealth browser through the 2captcha
// residential proxy.
Schedule::command('testimonials:sync-yelp-reviews --only-new')->weeklyOn(1, '07:00')
    ->timezone('America/Chicago')
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->onFailure(fn () => logger()->error('Scheduled Yelp review sync failed'));

// Yelp business photos: uploaded on-demand by ProjectImageObserver when a
// new image is added (mirrors GBP flow). No scheduled batch needed.

// Google reviews are synced by google-business-profile:sync-reviews
// (official GBP API, 06:00 daily above) directly into testimonials.

// SEO: weekly rank snapshot from Search Console data. GSC gives real Google
// positions for queries with impressions; map-pack visibility is covered by
// gbp:metrics-sync.
Schedule::command('seo:track-rankings')
    ->weeklyOn(1, '08:00') // Mondays 08:00 CT
    ->timezone('America/Chicago')
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-rankings.log'))
    ->onFailure(fn () => logger()->error('Scheduled SEO rank tracker failed'));

// SEO: weekly title-CTR audit — flag pages with high impressions but low CTR.
Schedule::command('seo:title-audit --days=28 --min-impr=20 --max-ctr=2.0')
    ->weeklyOn(1, '08:15')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-title-audit.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:title-audit failed'));

// SEO: weekly content strategy backlog from GSC opportunity data.
Schedule::command('seo:content-strategy --days=28 --limit=30 --markdown')
    ->dailyAt('08:20')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-content-strategy.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:content-strategy failed'));

// SEO: daily competitor SERP gap report (signals only, no copied content).
Schedule::command('seo:competitor-gap --top=5 --markdown')
    ->dailyAt('08:25')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-competitor-gap.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:competitor-gap failed'));

// SEO: daily competitor-brand & comparison-intent query tracker (GSC-based).
Schedule::command('seo:competitor-brand-track --days=28 --markdown')
    ->dailyAt('08:28')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-competitor-brand.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:competitor-brand-track failed'));

// SEO/GEO: daily Microsoft Clarity behavioral metrics sync.
Schedule::command('seo:clarity-sync --days=3')
    ->dailyAt('08:32')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-clarity-sync.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:clarity-sync failed'));

// SEO: weekly GSC week-over-week regression monitor (runs after Mon sync).
Schedule::command('seo:gsc-monitor --window=7 --markdown')
    ->weeklyOn(2, '09:00')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-gsc-monitor.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:gsc-monitor failed'));

// SEO: weekly Clarity integration health check (paired with GSC monitor window).
Schedule::command('seo:clarity-health --markdown')
    ->dailyAt('10:10')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-clarity-health.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:clarity-health failed'));

// SEO: weekly competitor rank-gap (where configured competitors outrank us).
Schedule::command('seo:competitor-rank-gap --max-queries=16 --markdown')
    ->weeklyOn(3, '08:30')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-competitor-rank-gap.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:competitor-rank-gap failed'));

// SEO: weekly competitor schema-coverage diff (rich-result type comparison).
Schedule::command('seo:competitor-schema-gap --markdown')
    ->weeklyOn(3, '08:45')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-competitor-schema-gap.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:competitor-schema-gap failed'));

// SEO: weekly self-audit of JSON-LD schema coverage and validity.
Schedule::command('seo:schema-audit --limit=120 --markdown')
    ->dailyAt('08:30')
    ->timezone('America/Chicago')
    ->onOneServer()
    ->withoutOverlapping(45)
    ->appendOutputTo(storage_path('logs/seo-schema-audit.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:schema-audit failed'));

// SEO: weekly content-decay scan (clicks/position regressions, 28-day windows).
Schedule::command('seo:content-decay --window=28 --markdown')
    ->dailyAt('08:45')
    ->timezone('America/Chicago')
    ->onOneServer()
    ->withoutOverlapping(45)
    ->appendOutputTo(storage_path('logs/seo-content-decay.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:content-decay failed'));

// SEO: weekly rank-band content-gap clusters (queries ranking 8-20).
Schedule::command('seo:content-gap --markdown')
    ->dailyAt('09:00')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-content-gap.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:content-gap failed'));

// SEO: weekly internal-link opportunity finder (unlinked anchor mentions).
Schedule::command('seo:internal-link-suggest --markdown')
    ->dailyAt('09:10')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-internal-link-suggest.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:internal-link-suggest failed'));

// SEO: weekly Core Web Vitals per-template regression check.
Schedule::command('seo:cwv-template --markdown')
    ->dailyAt('09:20')
    ->timezone('America/Chicago')
    ->onOneServer()
    ->withoutOverlapping(30)
    ->appendOutputTo(storage_path('logs/seo-cwv-template.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:cwv-template failed'));

// SEO: weekly GBP / local-SEO NAP + service parity audit.
Schedule::command('seo:gbp-parity --markdown')
    ->dailyAt('09:30')
    ->timezone('America/Chicago')
    ->onOneServer()
    ->withoutOverlapping(30)
    ->appendOutputTo(storage_path('logs/seo-gbp-parity.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:gbp-parity failed'));

// SEO: weekly backlink / mention monitor (referring-host snapshot via Brave Search).
Schedule::command('seo:backlinks-monitor --markdown')
    ->dailyAt('09:40')
    ->timezone('America/Chicago')
    ->onOneServer()
    ->withoutOverlapping(30)
    ->appendOutputTo(storage_path('logs/seo-backlinks-monitor.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:backlinks-monitor failed'));

// SEO: weekly composite Local SEO health-check (0–100 per URL).
// --min-score=0 keeps the scheduled run report-only (never fails the task).
Schedule::command('seo:health-check --markdown --min-score=0')
    ->dailyAt('09:50')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-health-check.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:health-check failed'));

// SEO: weekly per-area landing-page audit (thin content + near-duplicates).
Schedule::command('seo:area-pages-audit --markdown')
    ->dailyAt('10:00')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-area-pages-audit.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:area-pages-audit failed'));

// SEO: self-improving autopilot. Runs AFTER the analysis commands above have
// refreshed their signals for the day. It measures previously-applied actions
// (auto-reverting regressions), synthesizes a fresh scored ledger from GSC +
// coverage data, then auto-applies the top safe/reversible fixes (title/meta
// path overrides, reindex pings, llms.txt regen). Everything it does is
// baselined and revertible; GBP + body-copy changes stay as admin proposals.
// Runs on the queue-free scheduler process so it isn't bound by worker timeouts.
Schedule::command('seo:autopilot --markdown --max=25')
    ->dailyAt('10:40')
    ->timezone('America/Chicago')
    ->onOneServer()
    ->withoutOverlapping(30)
    ->appendOutputTo(storage_path('logs/seo-autopilot.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:autopilot failed'));

// SEO: weekly internal-link audit — orphans + weakly linked pages.
Schedule::command('seo:internal-link-audit --min=3')
    ->weeklyOn(1, '08:30')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-internal-links.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:internal-link-audit failed'));

// SEO: weekly image audit — missing alt text, weak alts, bad filenames.
Schedule::command('seo:image-audit --missing')
    ->weeklyOn(1, '08:45')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-image-audit.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:image-audit failed'));

// SEO: weekly on-page quick-wins crawler (titles, descriptions, canonicals, schema, TTFB).
Schedule::command('seo:audit-quickwins --limit=40 --markdown')
    ->weeklyOn(1, '08:50')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-audit-quickwins.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:audit-quickwins failed'));

// SEO: weekly content-depth audit — find AreaServed pages without unique per-city content.
Schedule::command('seo:content-depth-audit --missing')
    ->weeklyOn(1, '09:00')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-content-depth.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:content-depth-audit failed'));

// SEO: weekly JSON-LD ImageObject audit — flag pages missing contentUrl on schema images.
Schedule::command('seo:image-schema-audit --only-errors')
    ->weeklyOn(1, '09:15')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-image-schema.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:image-schema-audit failed'));

// SEO: weekly unified health dashboard — score 0-100 across five pillars.
// Runs after the other audits so its freshness metrics reflect the latest logs.
Schedule::command('seo:health')
    ->weeklyOn(1, '09:30')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-health.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:health failed'));

// SEO: daily markdown snapshot for /admin/seo-reports.
Schedule::command('seo:health --markdown')
    ->dailyAt('09:35')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-health.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:health --markdown failed'));

// GBP: daily check for reviews >24h old without an owner reply.
// Email only when a NEW needs-reply review was posted/edited in last 24h.
Schedule::command('gbp:unresponded-reviews --max-age=24 --notify --notify-recent-hours=24')
    ->dailyAt('09:00')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/gbp-unresponded-reviews.log'))
    ->when(fn () => config('services.google.business_profile.enabled'));

// FAQ: weekly generation for website + AI model training.
Schedule::command('faq:generate --ai')
    ->weeklyOn(2, '08:00') // Tuesdays 08:00 CT
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/faq-generate.log'))
    ->onFailure(fn () => logger()->error('Scheduled faq:generate failed'));

// SEO: weekly submission of persistent 404 URLs to IndexNow (re-crawl + deindex).
Schedule::command('seo:404-indexnow --min-hits=3')
    ->weeklyOn(2, '09:30')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-404-indexnow.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:404-indexnow failed'));

// SEO: weekly URL Inspection sweep — persists coverage states to
// gsc_coverage_states and re-pushes "Crawled - currently not indexed" /
// "Blocked due to access forbidden" pages through IndexNow + cache warm.
Schedule::command('seo:reindex-problem-pages --auto')
    ->weeklyOn(2, '09:45')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-reindex-problem-pages.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:reindex-problem-pages failed'))
    ->when(fn () => config('services.google.search_console.enabled'));

// SEO: weekly Cloudflare/WAF Googlebot-403 probe (detects bot-fight blocks).
Schedule::command('seo:cloudflare-403-audit --markdown')
    ->weeklyOn(2, '10:00')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-cloudflare-403-audit.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:cloudflare-403-audit failed'));

// SEO: nightly full-sitemap URL Inspection sweep. One run/day keeps us under
// the ~2,000 calls/day URL Inspection quota while keeping coverage +
// enhancements/shopping signals fresh in admin.
Schedule::command('seo:gsc-inspect-bulk --limit=0 --markdown')
    ->dailyAt('04:00')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-gsc-inspect-bulk.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:gsc-inspect-bulk failed'))
    ->when(fn () => config('services.google.search_console.enabled'));

// SEO: daily sitemap submission-status check (errors, warnings, stale lastDownloaded).
$seoAlertEmail = (string) env('SEO_ALERT_EMAIL', '');
$sitemapStatus = Schedule::command('seo:gsc-sitemap-status --markdown')
    ->dailyAt('05:30')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-gsc-sitemap-status.log'))
    ->when(fn () => config('services.google.search_console.enabled'));
if ($seoAlertEmail !== '') {
    $sitemapStatus->emailOutputOnFailure($seoAlertEmail);
}

// SEO: weekly canonical-conflict report + auto re-warm (Google chose different canonical).
Schedule::command('seo:gsc-canonical-conflicts --warm --markdown')
    ->weeklyOn(3, '05:00')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-gsc-canonical-conflicts.log'));

// SEO: daily critical-page health canary (manual-action / security-issue proxy).
$criticalHealth = Schedule::command('seo:gsc-critical-health --markdown')
    ->dailyAt('05:45')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-gsc-critical-health.log'))
    ->when(fn () => config('services.google.search_console.enabled'));
if ($seoAlertEmail !== '') {
    $criticalHealth->emailOutputOnFailure($seoAlertEmail);
}

// SEO: weekly crawl-budget staleness report (derived from URL Inspection lastCrawlTime).
Schedule::command('seo:gsc-crawl-budget --markdown')
    ->weeklyOn(3, '05:30')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-gsc-crawl-budget.log'));

// SEO: Google Search Console sync (free, official API).
// GSC data lags ~2 days at the source, so the freshest day Google returns is
// always ~2 days old regardless of how often we sync. We still pull every few
// hours so a newly-published day (and late-arriving revisions to recent days)
// lands on /admin within hours instead of waiting for a once-daily run. The
// upsert is keyed on dim_hash, so re-running is idempotent.
Schedule::command('seo:gsc-sync --days=7')
    ->everyThreeHours()
    ->timezone('America/Chicago')
    ->withoutOverlapping(60) // a full paginated pull can take a couple minutes
    ->appendOutputTo(storage_path('logs/seo-gsc-sync.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:gsc-sync failed'))
    ->when(fn () => config('services.google.search_console.enabled'));

// GBP: daily Performance API sync (impressions/calls/website clicks/direction requests).
Schedule::command('gbp:metrics-sync --days=14')
    ->dailyAt('05:15')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/gbp-metrics-sync.log'))
    ->onFailure(fn () => logger()->error('Scheduled gbp:metrics-sync failed'))
    ->when(fn () => config('services.google.business_profile.enabled'));

// GBP: weekly Performance API search-keyword sync (monthly granularity from Google).
Schedule::command('gbp:metrics-sync --days=3 --with-keywords')
    ->weeklyOn(1, '05:30')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/gbp-metrics-sync.log'))
    ->when(fn () => config('services.google.business_profile.enabled'));

// SEO: daily PageSpeed Insights snapshot for key pages (mobile + desktop).
Schedule::command('seo:psi-sync')
    ->dailyAt('04:00')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-psi-sync.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:psi-sync failed'));

// SEO: daily Bing Webmaster Tools sync (query stats).
Schedule::command('seo:bing-sync')
    ->dailyAt('05:45')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-bing-sync.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:bing-sync failed'))
    ->when(fn () => ! empty(config('services.bing.webmaster_api_key')));

// Social uploads run every other day in Chicago time (not daily).
// The random delay keeps the exact publish minute less predictable.
// Uses --via=puppeteer so the post is also location-tagged via the IG web UI
// (Graph API can't tag location without App Review).
Schedule::command('social:post --platform=instagram --via=puppeteer --yes --random-delay=180')
    ->cron('0 16 */2 * *')
    ->timezone('America/Chicago')
    ->withoutOverlapping(60 * 4) // command can sleep up to 3h + run ~1m, give it 4h
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->onFailure(fn () => logger()->error('Scheduled Instagram every-other-day post failed'))
    ->when(fn () => config('services.meta.enabled'));

// Facebook follows the same every-other-day cadence.
// Random delay spreads the post time across the late morning/afternoon window.
Schedule::command('social:post --platform=facebook --yes --random-delay=240')
    ->cron('0 10 */2 * *')
    ->timezone('America/Chicago')
    ->withoutOverlapping(60 * 5) // command can sleep up to 4h + run ~1m, give it 5h
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->onFailure(fn () => logger()->error('Scheduled Facebook every-other-day post failed'))
    ->when(fn () => config('services.meta.enabled'));

// Google Business Profile: twice-weekly posts (image + Gemini-generated caption)
// on TWO RANDOM days each week rather than fixed weekdays, so the cadence looks
// natural instead of clockwork. Queued so processing runs on the social-media
// worker (AI caption generation — never a direct API publish). The command runs
// daily at 09:30 CT but the ->when() gate only lets it through on the two days
// chosen for the current ISO week; --random-delay then spreads the actual post
// time across a ~4h window (posts land ~09:30–13:30 CT).
Schedule::command('social:post --platform=google_business --queue --random-delay=240')
    ->dailyAt('09:30')
    ->timezone('America/Chicago')
    ->onOneServer()
    ->withoutOverlapping(30)
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->onFailure(fn () => logger()->error('Scheduled GBP post failed'))
    ->when(function (): bool {
        if (! config('services.google.business_profile.enabled')) {
            return false;
        }

        // Two distinct weekdays per ISO week, deterministically seeded by the
        // week number: stable within the week, but different (and unpredictable)
        // week to week. This is what makes it "2× a week on random days".
        $now = now('America/Chicago');
        $randomizer = new \Random\Randomizer(new \Random\Engine\Mt19937(crc32($now->format('o-W'))));
        $chosenDays = array_slice($randomizer->shuffleArray(range(1, 7)), 0, 2);

        return in_array($now->dayOfWeekIso, $chosenDays, true);
    });

// GBP safety-net: if cadence slips (no published GBP post in 6+ days), queue
// one catch-up post daily. This avoids long dry spells — e.g. when the two
// random days land far apart across a week boundary — while deferring to the
// normal 2×/week random cadence when healthy.
Schedule::call(function (): void {
    if (! config('services.google.business_profile.enabled')) {
        return;
    }

    $lastPublishedAt = \App\Models\ImageSocialPost::query()
        ->where('platform', 'google_business')
        ->where('status', 'published')
        ->max('published_at');

    if ($lastPublishedAt !== null && \Illuminate\Support\Carbon::parse($lastPublishedAt)->greaterThanOrEqualTo(now()->subDays(6))) {
        return;
    }

    $exitCode = \Illuminate\Support\Facades\Artisan::call('social:post', [
        '--platform' => 'google_business',
        '--queue' => true,
    ]);

    if ($exitCode !== 0) {
        logger()->error('GBP safety-net could not queue catch-up post', [
            'last_published_at' => $lastPublishedAt,
            'exit_code' => $exitCode,
            'output' => trim(\Illuminate\Support\Facades\Artisan::output()),
        ]);

        return;
    }

    logger()->warning('GBP safety-net queued catch-up post', [
        'last_published_at' => $lastPublishedAt,
    ]);
})
    ->dailyAt('10:20')
    ->timezone('America/Chicago')
    ->name('gbp-safety-net-catchup-post')
    ->onOneServer()
    ->withoutOverlapping(30)
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->onFailure(fn () => logger()->error('Scheduled GBP safety-net post failed'));

// Social Media: weekly health check
Schedule::command('social:health')->weeklyOn(1, '09:00') // Monday 9 AM
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->when(fn () => config('services.meta.enabled'));

Artisan::command('gsc:cleanup-gbp-jpegs
    {--age=24 : Delete GBP JPGs older than this many hours}
', function () {
    $ageHours = (int) $this->option('age');
    if ($ageHours < 1) {
        $this->error('Age must be at least 1 hour.');
        return 1;
    }

    $cutoff = now()->subHours($ageHours);
    $disk = Storage::disk('public');
    $files = $disk->allFiles('projects');
    $deleted = 0;

    foreach ($files as $file) {
        if (! Str::endsWith($file, '_gbp.jpg')) {
            continue;
        }

        $lastModified = $disk->lastModified($file);
        if ($lastModified === false) {
            continue;
        }

        if ($cutoff->greaterThanOrEqualTo(\Illuminate\Support\Carbon::createFromTimestamp($lastModified))) {
            $disk->delete($file);
            $deleted++;
        }
    }

    $this->info("Deleted {$deleted} GBP JPG files.");
    return 0;
})->purpose('Delete temporary GBP JPG uploads after a retention window');

Artisan::command('gsc:classify-testimonials
    {--only-missing : Only update rows where project_type is null/empty}
    {--limit= : Limit number of testimonials processed}
    {--dry-run : Print proposed changes without writing to DB}
    {--model= : Override OpenAI model (defaults to services.openai.model)}
', function () {
    $onlyMissing = (bool) $this->option('only-missing');
    $dryRun = (bool) $this->option('dry-run');
    $limit = $this->option('limit');
    $model = $this->option('model');

    $allowedTypes = array_keys(Project::projectTypes());

    $query = Testimonial::query()->orderBy('id');

    if ($onlyMissing) {
        $query->where(function ($q) {
            $q->whereNull('project_type')->orWhere('project_type', '');
        });
    }

    if ($limit !== null && $limit !== '') {
        $query->limit((int) $limit);
    }

    $classifier = app(TestimonialProjectTypeClassifier::class);

    $count = 0;
    $changed = 0;

    $this->info('Allowed project types: '.implode(', ', $allowedTypes));
    if ($dryRun) {
        $this->warn('Dry-run mode: no DB changes will be saved.');
    }

    $query->chunkById(50, function ($rows) use (&$count, &$changed, $classifier, $allowedTypes, $model, $dryRun) {
        foreach ($rows as $t) {
            $count++;

            $suggested = $classifier->classify($t->review_description ?? '', $allowedTypes, $model ?: null);

            if (! $suggested) {
                $this->line("#{$t->id} {$t->reviewer_name}: unable to classify");
                continue;
            }

            $current = $t->project_type;
            if ($current === $suggested) {
                $this->line("#{$t->id} {$t->reviewer_name}: unchanged ({$suggested})");
                continue;
            }

            $this->line("#{$t->id} {$t->reviewer_name}: {$current} -> {$suggested}");

            if (! $dryRun) {
                $t->project_type = $suggested;
                $t->save();
            }

            $changed++;
        }
    });

    $this->newLine();
    $this->info("Processed: {$count}");
    $this->info("Updated: {$changed}".($dryRun ? ' (dry-run)' : ''));
})->purpose('Classify testimonial project_type via OpenAI (with fallback)');

// Safety-net: sweep contact_submissions that never reached Hive (e.g. queue was down).
// The primary path is ContactSection dispatching SendLeadToHive immediately on submit.
Schedule::call(function () {
    ContactSubmission::query()
        ->where('status', 'pending')
        ->whereNull('hive_sent_at')
        ->whereNull('hive_send_error')
        ->orderBy('id')
        ->limit(50)
        ->pluck('id')
        ->each(fn (int $id) => SendLeadToHive::dispatch($id));
})->name('hive:resend-leads-safety-net')->everyMinute()->withoutOverlapping();
