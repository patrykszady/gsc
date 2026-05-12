<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Purge specific URL prefixes (or explicit URLs) from Cloudflare's edge cache.
 *
 * Requires:
 *   CLOUDFLARE_ZONE_ID=<zone-id>       (from Cloudflare dashboard → zone overview → right column)
 *   CLOUDFLARE_API_TOKEN=<api-token>   (Scoped to "Cache Purge" permission on this zone)
 *
 * Usage:
 *   php artisan cloudflare:purge-cache                            # default: homepage + /areas-served/* + /services/*
 *   php artisan cloudflare:purge-cache --all                      # purge EVERYTHING (use carefully)
 *   php artisan cloudflare:purge-cache --paths=/blog/*,/projects  # custom paths
 *   php artisan cloudflare:purge-cache --urls=https://gs.construction/specific-page
 */
class CloudflarePurgeCache extends Command
{
    protected $signature = 'cloudflare:purge-cache
        {--all : Purge the entire zone cache (nuclear option)}
        {--paths= : Comma-separated path prefixes to purge, e.g. /services/*,/areas-served/*}
        {--urls= : Comma-separated exact URLs to purge}
        {--dry-run : Show what would be purged without hitting Cloudflare}';

    protected $description = 'Purge Cloudflare edge cache for SEO-critical URL groups.';

    /**
     * Default URL patterns to purge (covers our main SEO surfaces).
     * Cloudflare does not support wildcard purge by prefix in the "files" list —
     * we must enumerate the distinct paths we care about.
     *
     * For wildcard coverage we rely on Cloudflare's "Cache Everything" page rule
     * and purge the home page + representative first-level URLs per section.
     * The key SEO pages (areas-served, services) are purged exhaustively.
     */
    private const DEFAULT_PATHS = [
        '/',
        // All area-served city pages + their /services sub-pages
        '/areas-served',
        // All /services top-level pages
        '/services',
        '/projects',
        '/testimonials',
        '/about',
        '/contact',
        '/sitemap.xml',
    ];

    public function handle(): int
    {
        $zoneId = config('services.cloudflare.zone_id');
        $token  = config('services.cloudflare.api_token');
        $base   = rtrim((string) config('app.url'), '/');

        // ── Guard ─────────────────────────────────────────────────────────────
        if (! $zoneId || ! $token) {
            $this->error('Missing CLOUDFLARE_ZONE_ID or CLOUDFLARE_API_TOKEN in your .env.');
            $this->line('  Add them and run again. See: https://developers.cloudflare.com/api/tokens/create');
            return self::FAILURE;
        }

        if (! $this->option('dry-run') && str_contains($base, '127.0.0.1')) {
            $this->error('APP_URL is a local address. Set it to your production URL before purging.');
            return self::FAILURE;
        }

        // ── Purge everything ─────────────────────────────────────────────────
        if ($this->option('all')) {
            if (! $this->confirm('This will purge the ENTIRE Cloudflare zone cache. Continue?')) {
                return self::SUCCESS;
            }
            return $this->purgeAll($zoneId, $token);
        }

        // ── Build URL list ────────────────────────────────────────────────────
        $urls = collect();

        // --urls flag: exact URLs supplied by caller
        if ($this->option('urls')) {
            $urls = $urls->merge(
                array_filter(array_map('trim', explode(',', (string) $this->option('urls'))))
            );
        }

        // --paths flag: convert paths to absolute URLs
        if ($this->option('paths')) {
            $paths = array_filter(array_map('trim', explode(',', (string) $this->option('paths'))));
            $urls = $urls->merge(array_map(fn ($p) => $base . '/' . ltrim($p, '/'), $paths));
        }

        // Default: homepage + all area-served + all service pages
        if ($urls->isEmpty()) {
            // Static defaults
            $defaults = collect(self::DEFAULT_PATHS)->map(fn ($p) => $base . $p);

            // Discover service slugs from named routes (services.*)
            $serviceSlugs = collect(app('router')->getRoutes()->getRoutesByMethod()['GET'] ?? [])
                ->keys()
                ->filter(fn ($u) => str_starts_with($u, 'services/') && ! str_contains($u, '{'))
                ->map(fn ($u) => substr($u, strlen('services/')))
                ->values();

            // All /areas-served/{city} URLs + per-city service pages
            $areaUrls = \App\Models\AreaServed::orderBy('slug')
                ->pluck('slug')
                ->flatMap(fn ($slug) => collect([
                    $base . '/areas-served/' . $slug,
                ])->merge($serviceSlugs->map(fn ($k) => $base . '/areas-served/' . $slug . '/services/' . $k)));

            // All /services/{service} top-level pages
            $serviceUrls = $serviceSlugs->map(fn ($k) => $base . '/services/' . $k);

            $urls = $defaults->merge($areaUrls)->merge($serviceUrls)->unique();
        }

        return $this->purgeFiles($zoneId, $token, $urls->values()->all());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function purgeAll(string $zoneId, string $token): int
    {
        if ($this->option('dry-run')) {
            $this->warn('[dry-run] Would POST to Cloudflare: purge_everything=true');
            return self::SUCCESS;
        }

        $resp = Http::withToken($token)
            ->post("https://api.cloudflare.com/client/v4/zones/{$zoneId}/purge_cache", [
                'purge_everything' => true,
            ]);

        return $this->handleResponse($resp, 1);
    }

    private function purgeFiles(string $zoneId, string $token, array $urls): int
    {
        // Cloudflare accepts max 30 URLs per request
        $chunks = array_chunk($urls, 30);
        $total  = count($urls);
        $errors = 0;

        $this->info("Purging {$total} URLs across " . count($chunks) . " API request(s)…");

        if ($this->option('dry-run')) {
            $this->table(['URL'], array_map(fn ($u) => [$u], $urls));
            $this->warn('[dry-run] No requests sent.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar(count($chunks));

        foreach ($chunks as $chunk) {
            $resp = Http::withToken($token)
                ->post("https://api.cloudflare.com/client/v4/zones/{$zoneId}/purge_cache", [
                    'files' => $chunk,
                ]);

            if (! $resp->successful() || ! ($resp->json('success') ?? false)) {
                $errors++;
                $errs = collect($resp->json('errors') ?? [])->pluck('message')->join(', ');
                $this->newLine();
                $this->error("Chunk failed: {$errs}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        if ($errors === 0) {
            $this->info("✓ All {$total} URLs purged from Cloudflare.");
            return self::SUCCESS;
        }

        $this->warn("{$errors}/" . count($chunks) . " chunk(s) failed.");
        return self::FAILURE;
    }

    private function handleResponse(\Illuminate\Http\Client\Response $resp, int $chunks): int
    {
        if ($resp->successful() && ($resp->json('success') ?? false)) {
            $this->info('✓ Cloudflare zone cache purged.');
            return self::SUCCESS;
        }

        $errs = collect($resp->json('errors') ?? [])->pluck('message')->join(', ');
        $this->error("Cloudflare purge failed: {$errs}");
        return self::FAILURE;
    }
}
