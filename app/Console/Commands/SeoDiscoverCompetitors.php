<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Competitor discovery via Brave Search.
 *
 * Runs first-page Google searches for each area we serve, using query
 * templates such as "{city} kitchen remodeling", "{city} bathroom remodeling"
 * and "home remodeling in {city}". It aggregates the organic domains that show
 * up on page one across every area, excludes our own site plus the usual
 * directories/aggregators (Yelp, Houzz, Angi, Google, Facebook, etc.), and
 * ranks the remaining remodeling companies by how many area-SERPs they appear
 * on. The output is a prioritized list of real local competitors worth adding
 * to config/competitors.php — already-configured ones are flagged as "known".
 *
 * Search cost: 1 search per (area × template). 87 areas × 3 templates = 261
 * searches for a full sweep, so use --max-queries / --max-areas to stay within
 * your plan. A weekly partial sweep keeps the candidate list fresh cheaply.
 */
class SeoDiscoverCompetitors extends Command
{
    protected $signature = 'seo:discover-competitors
        {--templates=}
        {--areas= : CSV of city names (defaults to all AreaServed up to --max-areas)}
        {--max-areas=0 : Cap number of areas (0 = all)}
        {--max-queries=60 : Hard cap on total search calls}
        {--top=10 : Page-one result depth to scan}
        {--min-appearances=2 : Only report domains seen on this many area-SERPs}
        {--exclude= : Extra CSV of hosts to exclude (substring match)}
        {--location=Illinois, United States : Legacy location hint (unused; queries carry city names)}
        {--markdown : Save report to storage/app/reports/competitor-discovery.md}';

    protected $description = 'Discover local remodeling competitors that rank on Google page one across the areas we serve (Brave Search).';

    /**
     * Directories, aggregators, marketplaces, media and big-box hosts that are
     * not direct remodeling-company competitors. Substring-matched on host.
     *
     * @var array<int, string>
     */
    protected array $defaultExclusions = [
        'google.', 'yelp.', 'houzz.', 'angi.', 'angieslist.', 'homeadvisor.', 'thumbtack.',
        'bbb.org', 'facebook.', 'instagram.', 'pinterest.', 'youtube.', 'tiktok.', 'linkedin.',
        'nextdoor.', 'mapquest.', 'yellowpages.', 'superpages.', 'manta.', 'porch.com',
        'buildzoom.', 'expertise.com', 'wikipedia.', 'reddit.', 'tripadvisor.', 'indeed.',
        'glassdoor.', 'craigslist.', 'amazon.', 'homedepot.', 'lowes.', 'menards.', 'wayfair.',
        'ikea.', 'apple.', 'bing.', 'yahoo.', 'duckduckgo.', 'birdeye.', 'trustpilot.',
        'better business', 'forbes.', 'bobvila.', 'thisoldhouse.', 'architecturaldigest.',
        'hgtv.', 'fixr.com', 'modernize.', 'networx.', 'angi', 'chamberofcommerce.',
        'zillow.', 'redfin.', 'realtor.com', 'guildquality.', 'homeguide.', 'countryliving.',
        'servpro.', 'mrhandyman.', 'rebath.', 'westshorehome.', 'jacuzzibathremodel',
        'apartments.com', 'trulia.', 'yellowbook.', 'mapcarta.', 'cylex', 'opendoor.',
        // Our own production + staging hosts — never report ourselves as a competitor.
        'gs.construction',
    ];

    public function handle(): int
    {
        if (! app(\App\Services\BraveSearchService::class)->isConfigured()) {
            $this->error('BRAVE_SEARCH_API_KEY is not set.');
            return self::FAILURE;
        }

        $templates = $this->resolveTemplates();
        $areas = $this->resolveAreas();
        if (empty($areas)) {
            $this->error('No areas resolved. Provide --areas or seed the areas_served table.');
            return self::FAILURE;
        }

        $ourHost = $this->normalizeHost((string) parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'gs.construction');
        $exclusions = $this->resolveExclusions();
        $known = $this->knownCompetitorHosts();

        $top = max(1, (int) $this->option('top'));
        $maxQueries = max(1, (int) $this->option('max-queries'));
        $location = (string) $this->option('location');

        // Build the (city × template) query list, then cap it.
        $queries = [];
        foreach ($areas as $city) {
            foreach ($templates as $tpl) {
                $queries[] = [
                    'city' => $city,
                    'q' => trim(str_ireplace('{city}', $city, $tpl)),
                ];
            }
        }
        if (count($queries) > $maxQueries) {
            $queries = array_slice($queries, 0, $maxQueries);
        }

        $this->info(sprintf(
            'Running %d Brave searches (%d areas × %d templates, capped at %d).',
            count($queries), count($areas), count($templates), $maxQueries
        ));

        // host => aggregate stats
        $hosts = [];
        $searched = 0;
        $failed = 0;

        foreach ($queries as $item) {
            $results = $this->fetchSerp($item['q'], $location, $top);
            if ($results === null) {
                $failed++;
                $this->warn('  Search failed for: ' . $item['q']);
                usleep(250000);
                continue;
            }
            $searched++;

            $seenThisQuery = [];
            foreach ($results as $pos => $r) {
                $host = $this->normalizeHost($r['host']);
                if ($host === '' || $host === $ourHost) {
                    continue;
                }
                if ($this->isExcluded($host, $exclusions)) {
                    continue;
                }
                // Only count a host once per query (best position).
                if (isset($seenThisQuery[$host])) {
                    continue;
                }
                $seenThisQuery[$host] = true;

                if (! isset($hosts[$host])) {
                    $hosts[$host] = [
                        'host' => $host,
                        'appearances' => 0,
                        'best_pos' => $pos + 1,
                        'sum_pos' => 0,
                        'cities' => [],
                        'sample_query' => $item['q'],
                        'sample_title' => $r['title'],
                        'sample_link' => $r['link'],
                        'known' => $this->matchesKnown($host, $known),
                    ];
                }
                $hosts[$host]['appearances']++;
                $hosts[$host]['sum_pos'] += ($pos + 1);
                $hosts[$host]['best_pos'] = min($hosts[$host]['best_pos'], $pos + 1);
                $hosts[$host]['cities'][$item['city']] = true;
            }

            usleep(250000); // be gentle on the API
        }

        $minAppear = max(1, (int) $this->option('min-appearances'));

        $rows = collect($hosts)
            ->map(function (array $h) {
                $h['area_count'] = count($h['cities']);
                $h['avg_pos'] = $h['appearances'] > 0 ? round($h['sum_pos'] / $h['appearances'], 1) : null;
                return $h;
            })
            ->filter(fn (array $h) => $h['area_count'] >= $minAppear)
            ->sortByDesc(fn (array $h) => [$h['area_count'], -$h['best_pos']])
            ->values();

        $this->renderTable($rows, $minAppear);

        $this->newLine();
        $this->info(sprintf(
            'Done. searched=%d  failed=%d  unique_domains=%d  candidates(>=%d areas)=%d',
            $searched, $failed, count($hosts), $minAppear, $rows->count()
        ));

        if ($this->option('markdown')) {
            $this->saveMarkdown($rows, $searched, $failed, $minAppear);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function resolveTemplates(): array
    {
        $raw = trim((string) $this->option('templates'));
        if ($raw === '') {
            return [
                '{city} kitchen remodeling',
                '{city} bathroom remodeling',
                'home remodeling in {city}',
            ];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * @return array<int, string>
     */
    protected function resolveAreas(): array
    {
        $raw = trim((string) $this->option('areas'));
        if ($raw !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $raw))));
        }

        $query = AreaServed::query()->orderBy('id');
        $max = (int) $this->option('max-areas');
        if ($max > 0) {
            $query->limit($max);
        }
        return $query->pluck('city')->filter()->values()->all();
    }

    /**
     * @return array<int, string>
     */
    protected function resolveExclusions(): array
    {
        $extra = array_values(array_filter(array_map(
            fn ($s) => strtolower(trim($s)),
            explode(',', (string) $this->option('exclude')),
        )));
        return array_values(array_unique(array_merge($this->defaultExclusions, $extra)));
    }

    /**
     * Hosts already configured in config/competitors.php (normalized).
     *
     * @return array<int, string>
     */
    protected function knownCompetitorHosts(): array
    {
        return collect(config('competitors.competitors', []))
            ->map(fn ($c) => $this->normalizeHost((string) parse_url((string) ($c['website'] ?? ''), PHP_URL_HOST)))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param array<int, string> $known
     */
    protected function matchesKnown(string $host, array $known): bool
    {
        foreach ($known as $k) {
            if ($k !== '' && ($host === $k || Str::endsWith($host, '.' . $k) || Str::endsWith($k, '.' . $host))) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, string> $exclusions
     */
    protected function isExcluded(string $host, array $exclusions): bool
    {
        foreach ($exclusions as $ex) {
            if ($ex !== '' && str_contains($host, $ex)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<int, array{title:string,link:string,host:string}>|null
     */
    protected function fetchSerp(string $query, string $location, int $top): ?array
    {
        // Discovery queries embed the city
        // name, which localizes results well enough to surface competitors.
        $rows = app(\App\Services\BraveSearchService::class)
            ->organicResults($query, max(10, $top));

        if ($rows === null) {
            return null;
        }

        $out = [];
        foreach ($rows as $row) {
            $link = (string) ($row['link'] ?? '');
            $host = $link !== '' ? (string) parse_url($link, PHP_URL_HOST) : '';
            if ($host === '') {
                continue;
            }
            $out[] = [
                'title' => (string) ($row['title'] ?? ''),
                'link' => $link,
                'host' => $host,
            ];
            if (count($out) >= $top) {
                break;
            }
        }
        return $out;
    }

    protected function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        return preg_replace('/^www\./', '', $host) ?? $host;
    }

    /**
     * @param \Illuminate\Support\Collection<int, array<string, mixed>> $rows
     */
    protected function renderTable($rows, int $minAppear): void
    {
        $this->newLine();
        $this->line('<fg=cyan>--- Competitor candidates (ranked by area coverage) ---</>');
        if ($rows->isEmpty()) {
            $this->line('  (no domains met the --min-appearances=' . $minAppear . ' threshold)');
            return;
        }

        $table = [];
        foreach ($rows as $h) {
            $table[] = [
                $h['known'] ? '✓ known' : 'new',
                Str::limit($h['host'], 34),
                $h['area_count'],
                '#' . $h['best_pos'],
                $h['avg_pos'],
                Str::limit($h['sample_query'], 30),
            ];
        }
        $this->table(
            ['Status', 'Domain', 'Areas', 'Best', 'Avg pos', 'Sample query'],
            $table
        );
    }

    /**
     * @param \Illuminate\Support\Collection<int, array<string, mixed>> $rows
     */
    protected function saveMarkdown($rows, int $searched, int $failed, int $minAppear): void
    {
        $md = "# Competitor discovery report\n\n";
        $md .= 'Run: ' . now()->toIso8601String() . "\n\n";
        $md .= "Searched {$searched} SERPs (failed: {$failed}). Showing domains on >= {$minAppear} area-SERPs.\n\n";
        $md .= "Status `new` = not yet in config/competitors.php. `✓ known` = already configured.\n\n";
        $md .= "| Status | Domain | Areas | Best pos | Avg pos | Sample query | Sample title |\n";
        $md .= "|---|---|---:|---:|---:|---|---|\n";
        foreach ($rows as $h) {
            $md .= sprintf(
                "| %s | %s | %d | #%d | %s | %s | %s |\n",
                $h['known'] ? 'known' : 'new',
                $h['host'],
                $h['area_count'],
                $h['best_pos'],
                $h['avg_pos'] ?? '',
                str_replace('|', '\|', (string) $h['sample_query']),
                str_replace('|', '\|', Str::limit((string) $h['sample_title'], 60)),
            );
        }

        $md .= "\n## Suggested config/competitors.php stubs (new domains)\n\n";
        $md .= "```php\n";
        foreach ($rows as $h) {
            if ($h['known']) {
                continue;
            }
            $slug = Str::slug(preg_replace('/\.[a-z.]+$/', '', $h['host']));
            $name = Str::title(str_replace('-', ' ', $slug));
            $md .= "[\n";
            $md .= "    'slug' => '{$slug}',\n";
            $md .= "    'name' => '{$name}', // VERIFY real business name\n";
            $md .= "    'website' => 'https://{$h['host']}/',\n";
            $md .= "    'location' => '', // VERIFY\n";
            $md .= "    'focus' => '', // VERIFY (kitchen / bath / whole-home)\n";
            $md .= "    'them' => [],\n";
            $md .= "],\n";
        }
        $md .= "```\n";

        Storage::disk('local')->put('reports/competitor-discovery.md', $md);
        $this->info('Saved report to storage/app/reports/competitor-discovery.md');
    }
}
