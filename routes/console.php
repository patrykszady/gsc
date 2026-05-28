<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Project;
use App\Models\Testimonial;
use App\Services\TestimonialProjectTypeClassifier;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule sitemap regeneration daily
Schedule::command('sitemap:generate')->daily();

// Hive (hive.contractors) project zip-counts sync — feeds the homepage map
Schedule::command('hive:sync')->dailyAt('02:00')
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->onFailure(fn () => logger()->error('Scheduled hive:sync failed'));

// Weekly health check of social/sameAs URLs
Schedule::command('socials:check --quiet-on-success')->weekly()
    ->appendOutputTo(storage_path('logs/socials-check.log'));

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

// Yelp: check for new reviews weekly (create-only; skip existing)
Schedule::command('testimonials:sync-yelp-reviews --only-new')->weeklyOn(1, '07:00')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->onFailure(fn () => logger()->error('Scheduled Yelp review sync failed'))
    ->when(fn () => filled(config('services.serpapi.api_key')));

// Yelp business photos: uploaded on-demand by ProjectImageObserver when a
// new image is added (mirrors GBP flow). No scheduled batch needed.

// Google: probe daily via free Places API; only call SerpApi when review count changes.
Schedule::command('testimonials:sync-google-reviews-serpapi --only-new')
    ->dailyAt('07:15')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->onFailure(fn () => logger()->error('Scheduled Google (SerpApi) review sync failed'))
    ->when(fn () => filled(config('services.serpapi.api_key')))
    ->when(fn () => filled(config('services.serpapi.google_data_id')) || filled(config('services.google.business_profile.place_id')));

// SEO: weekly rank snapshot (Google + Google Maps) for tracked queries.
Schedule::command('seo:track-rankings --engine=both')
    ->weeklyOn(1, '08:00') // Mondays 08:00 CT
    ->timezone('America/Chicago')
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
    ->weeklyOn(2, '09:02')
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
    ->weeklyOn(4, '08:30')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-schema-audit.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:schema-audit failed'));

// SEO: weekly content-decay scan (clicks/position regressions, 28-day windows).
Schedule::command('seo:content-decay --window=28 --markdown')
    ->weeklyOn(4, '08:45')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-content-decay.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:content-decay failed'));

// SEO: weekly rank-band content-gap clusters (queries ranking 8-20).
Schedule::command('seo:content-gap --markdown')
    ->weeklyOn(4, '09:00')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-content-gap.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:content-gap failed'));

// SEO: weekly internal-link opportunity finder (unlinked anchor mentions).
Schedule::command('seo:internal-link-suggest --markdown')
    ->weeklyOn(5, '08:30')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-internal-link-suggest.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:internal-link-suggest failed'));

// SEO: weekly Core Web Vitals per-template regression check.
Schedule::command('seo:cwv-template --markdown')
    ->weeklyOn(5, '08:45')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-cwv-template.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:cwv-template failed'));

// SEO: weekly GBP / local-SEO NAP + service parity audit.
Schedule::command('seo:gbp-parity --markdown')
    ->weeklyOn(5, '09:00')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-gbp-parity.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:gbp-parity failed'));

// SEO: weekly backlink / mention monitor (referring-host snapshot via SerpApi).
Schedule::command('seo:backlinks-monitor --markdown')
    ->weeklyOn(5, '09:15')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/seo-backlinks-monitor.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:backlinks-monitor failed'));

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

// GBP: daily check for reviews >24h old without an owner reply (with email alert).
Schedule::command('gbp:unresponded-reviews --max-age=24 --notify')
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

// SEO: daily Google Search Console sync (free, official API).
// GSC data lags ~2 days, so we always pull the last 7-day window and upsert.
Schedule::command('seo:gsc-sync --days=7')
    ->dailyAt('05:00')
    ->timezone('America/Chicago')
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

// SEO: weekly PageSpeed Insights snapshot for key pages (mobile + desktop).
Schedule::command('seo:psi-sync')
    ->weeklyOn(3, '04:00')
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

// Instagram: 2 posts per day — morning + late afternoon (Central Time)
// Random delay spreads posts naturally within each window
Schedule::command('social:post --platform=instagram --queue --random-delay=150')
    ->dailyAt('07:00')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->onFailure(fn () => logger()->error('Scheduled Instagram morning post failed'))
    ->when(fn () => config('services.meta.enabled'));

Schedule::command('social:post --platform=instagram --queue --random-delay=120')
    ->dailyAt('15:00')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->onFailure(fn () => logger()->error('Scheduled Instagram afternoon post failed'))
    ->when(fn () => config('services.meta.enabled'));

// Facebook + Google Business Profile: 1 post daily at 10:00 AM CT
Schedule::command('social:post --platform=facebook --queue')->dailyAt('10:00')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->onFailure(fn () => logger()->error('Scheduled Facebook post failed'))
    ->when(fn () => config('services.meta.enabled'));

// Google Business Profile: 1 weekly post (image + Gemini-generated caption) on Mondays at 10:00 AM CT.
// Picks a random project image not yet posted to GBP, generates SEO caption via Gemini, and creates a Local Post.
Schedule::command('social:post --platform=google_business --queue')->weeklyOn(1, '10:00')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->onFailure(fn () => logger()->error('Scheduled GBP weekly post failed'))
    ->when(fn () => config('services.google.business_profile.enabled'));

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
