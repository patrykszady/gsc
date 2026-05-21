<?php

namespace App\Console\Commands;

use App\Models\PsiSnapshot;
use App\Services\PageSpeedInsightsService;
use Illuminate\Console\Command;

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
        {--dry-run}';

    protected $description = 'Snapshot PageSpeed Insights (Lighthouse + CrUX) for key pages';

    public function handle(PageSpeedInsightsService $svc): int
    {
        $strategies = match ($this->option('strategy')) {
            'mobile' => ['mobile'],
            'desktop' => ['desktop'],
            default => ['mobile', 'desktop'],
        };

        $urls = $this->option('urls') ?: config('seo.psi_urls', $this->defaultUrls());
        $dry = (bool) $this->option('dry-run');

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
}
