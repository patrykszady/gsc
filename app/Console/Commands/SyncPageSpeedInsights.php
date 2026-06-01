<?php

namespace App\Console\Commands;

use App\Models\PsiSnapshot;
use App\Services\PageSpeedInsightsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Run PageSpeed Insights for a set of important pages and persist scores.
 *
 * URL list comes from config('seo.psi_urls'); falls back to a few key
 * routes if unset. Idempotent per (date, url, strategy).
 */
class SyncPageSpeedInsights extends Command
{
    protected $signature = 'seo:psi-sync
        {--strategy=both : mobile, desktop, or both}
        {--urls=* : Override URLs (defaults to config(seo.psi_urls))}
        {--max-urls= : Cap URL count (defaults to config(seo.psi_max_urls))}
        {--dry-run}';

    protected $description = 'Snapshot PageSpeed Insights (Lighthouse + CrUX) for key pages';

    public function handle(PageSpeedInsightsService $svc): int
    {
        $strategies = match ($this->option('strategy')) {
            'mobile' => ['mobile'],
            'desktop' => ['desktop'],
            default => ['mobile', 'desktop'],
        };

        $urls = $this->resolveUrls();
        $dry = (bool) $this->option('dry-run');

        $this->info('PSI URL pool: ' . count($urls) . ' URL(s)');

        $today = now()->toDateString();
        $totalOk = 0;
        $totalFail = 0;

        foreach ($urls as $url) {
            foreach ($strategies as $strategy) {
                $this->line("PSI: {$strategy} {$url}");
                
                try {
                    $result = $svc->run($url, $strategy);
                } catch (\Exception $e) {
                    $totalFail++;
                    $this->warn(" ↳ error: " . $e->getMessage());
                    continue;
                }

                if (! $result) {
                    $totalFail++;
                    $this->warn(" ↳ failed");
                    continue;
                }

                $this->line(sprintf(
                    ' ↳ perf=%s a11y=%s bp=%s seo=%s lab_lcp=%sms cls=%s field=%s',
                    $result['performance'] ?? '?',
                    $result['accessibility'] ?? '?',
                    $result['best_practices'] ?? '?',
                    $result['seo'] ?? '?',
                    $result['lab_lcp_ms'] ?? '?',
                    $result['lab_cls'] ?? '?',
                    $result['field_overall'] ?? 'n/a',
                ));

                if ($dry) {
                    continue;
                }

                PsiSnapshot::updateOrCreate(
                    ['date' => $today, 'url' => $url, 'strategy' => $strategy],
                    $result,
                );
                $totalOk++;
            }
        }

        $this->info("Done. ok={$totalOk} failed={$totalFail}" . ($dry ? ' (dry-run)' : ''));
        
        // Return success as long as the command ran without errors.
        // Individual URL failures are logged and monitored separately;
        // a transient Lighthouse API error (5xx) shouldn't fail the entire scheduled task.
        return self::SUCCESS;
    }

    protected function defaultUrls(): array
    {
        $base = rtrim((string) config('app.url'), '/');
        return [
            $base . '/',
            $base . '/about',
            $base . '/projects',
            $base . '/services/kitchen-remodeling',
            $base . '/services/bathroom-remodeling',
            $base . '/services/home-remodeling',
            $base . '/services/basement-remodeling',
            $base . '/services/home-additions',
            $base . '/contact',
        ];
    }

    protected function resolveUrls(): array
    {
        $explicitUrls = (array) $this->option('urls');
        if (! empty($explicitUrls)) {
            return array_values(array_unique(array_filter($explicitUrls)));
        }

        $base = rtrim((string) config('app.url'), '/');
        $maxUrls = max(1, (int) ($this->option('max-urls') ?: config('seo.psi_max_urls', 60)));

        $pinned = (array) config('seo.psi_urls', $this->defaultUrls());
        $pinned = array_values(array_unique(array_filter($pinned, fn ($u) => is_string($u) && str_starts_with($u, $base))));

        $targetDynamic = max(0, $maxUrls - count($pinned));
        $dynamic = $targetDynamic > 0 ? $this->topGscUrls($targetDynamic, $base) : [];

        $urls = array_values(array_unique(array_merge($pinned, $dynamic)));
        if (count($urls) > $maxUrls) {
            $urls = array_slice($urls, 0, $maxUrls);
        }

        return $urls;
    }

    protected function topGscUrls(int $limit, string $base): array
    {
        try {
            $rows = DB::table('gsc_query_metrics')
                ->selectRaw('page, SUM(impressions) AS impr')
                ->where('date', '>=', now()->subDays(28)->toDateString())
                ->whereNotNull('page')
                ->where('page', '!=', '')
                ->groupBy('page')
                ->orderByDesc('impr')
                ->limit($limit * 3)
                ->pluck('page')
                ->all();
        } catch (\Throwable $e) {
            $this->warn('PSI dynamic URL expansion skipped: ' . $e->getMessage());
            return [];
        }

        $urls = [];
        $skipFragments = [
            '/admin',
            '/geo',
            '/login',
            '/logout',
            '/api/',
            '/livewire',
        ];

        foreach ($rows as $url) {
            if (! is_string($url) || $url === '' || ! str_starts_with($url, $base)) {
                continue;
            }

            $path = parse_url($url, PHP_URL_PATH) ?: '/';
            if ($path === '') {
                $path = '/';
            }

            $skip = false;
            foreach ($skipFragments as $fragment) {
                if (str_contains($path, $fragment)) {
                    $skip = true;
                    break;
                }
            }

            if ($skip) {
                continue;
            }

            $urls[] = rtrim($base, '/') . $path;
            if (count($urls) >= $limit) {
                break;
            }
        }

        return array_values(array_unique($urls));
    }
}
