<?php

namespace App\Console\Commands;

use App\Models\ClarityDailyMetric;
use App\Services\MicrosoftClarityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SeoClarityHealth extends Command
{
    protected $signature = 'seo:clarity-health
        {--markdown : Save markdown report to storage/app/reports/clarity-health.md}';

    protected $description = 'Health check for Microsoft Clarity integration and latest metric freshness';

    public function handle(MicrosoftClarityService $svc): int
    {
        $isConfigured = $svc->isConfigured();
        $projectId = (string) config('services.microsoft.clarity.project_id');

        $apiReachable = false;
        $apiError = null;
        if ($isConfigured) {
            $rows = $svc->fetchDailyMetrics(MicrosoftClarityService::MAX_DAYS);
            if ($rows !== null) {
                $apiReachable = true;
            } else {
                $apiError = $svc->getLastError();
            }
        }

        $latest = ClarityDailyMetric::query()
            ->where('project_id', $projectId)
            ->orderByDesc('date')
            ->first();

        $rowsCount = ClarityDailyMetric::query()
            ->where('project_id', $projectId)
            ->count();

        $latestDate = $latest?->date?->toDateString();
        $latestAge = $latest?->date?->diffForHumans();

        $this->newLine();
        $this->info('=== Clarity Health ===');
        $this->line('Configured: ' . ($isConfigured ? 'yes' : 'no'));
        $this->line('API reachable: ' . ($apiReachable ? 'yes' : 'no'));
        $this->line('Project ID: ' . ($projectId !== '' ? $projectId : '(empty)'));
        $this->line('Stored rows: ' . $rowsCount);
        $this->line('Latest date: ' . ($latestDate ?? '(none)'));
        $this->line('Latest age: ' . ($latestAge ?? '(none)'));

        if ($latest) {
            $this->line('Latest metrics: ' . json_encode([
                'sessions' => $latest->sessions,
                'users' => $latest->users,
                'pageviews' => $latest->pageviews,
                'scroll_depth' => $latest->scroll_depth,
                'active_time_seconds' => $latest->active_time_seconds,
                'bounce_rate' => $latest->bounce_rate,
                'dead_clicks' => $latest->dead_clicks,
                'rage_clicks' => $latest->rage_clicks,
                'quickbacks' => $latest->quickbacks,
                'script_errors' => $latest->script_errors,
                'error_clicks' => $latest->error_clicks,
            ], JSON_UNESCAPED_SLASHES));
        }

        // JS error spike detection. Clarity's API gives a count only (no message
        // or stack — see ClientErrorController for that). Compare the latest
        // day's errors-per-session rate against the trailing baseline so a
        // regression (e.g. a broken deploy) is flagged proactively.
        $spike = $this->detectScriptErrorSpike($projectId, $latest);
        if ($spike !== null) {
            $this->newLine();
            $this->error('⚠ JS error spike: ' . $spike['summary']);
        }

        if (! $apiReachable && $apiError) {
            $this->warn('API error: ' . $apiError);
        }

        if ((bool) $this->option('markdown')) {
            $md = $this->buildMarkdown(
                isConfigured: $isConfigured,
                apiReachable: $apiReachable,
                projectId: $projectId,
                rowsCount: $rowsCount,
                latest: $latest,
                apiError: $apiError,
                spike: $spike,
            );

            Storage::disk('local')->put('reports/clarity-health.md', $md);
            $this->info('Saved: storage/app/private/reports/clarity-health.md');
        }

        // A completely unconfigured integration is a real setup problem.
        if (! $isConfigured) {
            return self::FAILURE;
        }

        // The meaningful failure is STALE stored data — that means the daily
        // sync pipeline is actually broken. Clarity's Data Export API has a very
        // small daily request quota that the 08:32 sync usually consumes, so the
        // health check's own live call at 10:10 often returns a rate-limit error
        // even when the integration is perfectly healthy. Treat an unreachable
        // live API as a hard failure ONLY when stored data is also stale;
        // otherwise it is noise and was producing a false-positive daily error.
        $staleThresholdDays = 3;
        $isStale = $latest === null
            || $latest->date === null
            || $latest->date->lt(now()->subDays($staleThresholdDays)->startOfDay());

        if ($isStale) {
            $this->error('Clarity data is stale (latest: ' . ($latestDate ?? 'none') . ') — sync pipeline may be broken.');

            return self::FAILURE;
        }

        if (! $apiReachable) {
            $this->warn('Clarity live API unreachable at health-check time, but stored data is fresh (likely the daily export quota). Not treating as a failure.');
        }

        // Non-zero exit on a confirmed spike so the scheduler's onFailure hook
        // logs it (matches seo:gsc-critical-health behaviour) — but only when
        // our own error beacon corroborates it. Clarity counts ALL script
        // errors including cross-origin ones (Cloudflare challenge scripts,
        // tag managers) that the beacon deliberately drops as un-actionable;
        // a spike with zero beacon-captured errors is third-party noise, not
        // a broken deploy (confirmed 2026-07-14: 48% spike, all CF challenge
        // noise, site JS clean under a rendered-browser audit).
        if ($spike !== null) {
            $beaconErrors = \App\Models\ClientError::where('last_seen_at', '>=', now()->subHours(36))->count();

            if ($beaconErrors > 0) {
                $this->error("Beacon corroborates the spike ({$beaconErrors} client errors in 36h) — treating as a real regression.");

                return self::FAILURE;
            }

            $this->warn('Spike NOT corroborated by the on-site error beacon (0 client errors in 36h) — almost certainly cross-origin/third-party noise. Not failing.');
            logger()->warning('seo:clarity-health: JS error spike without beacon corroboration (third-party noise)', [
                'spike' => $spike['summary'],
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Compare the latest day's JS-error-per-session rate against a trailing
     * baseline. Returns null when there is no spike (or insufficient data).
     *
     * @return array{summary:string,latest_rate:float,baseline_rate:float,errors:int}|null
     */
    protected function detectScriptErrorSpike(string $projectId, ?ClarityDailyMetric $latest): ?array
    {
        if (! $latest || (int) $latest->sessions <= 0) {
            return null;
        }

        // Need at least a handful of errors before alerting — tiny counts on
        // low-traffic days are noise, not regressions.
        $errors = (int) $latest->script_errors;
        if ($errors < 5) {
            return null;
        }

        // Trailing baseline: up to 14 prior days, excluding the latest row.
        $baselineRows = ClarityDailyMetric::query()
            ->where('project_id', $projectId)
            ->where('date', '<', $latest->date)
            ->orderByDesc('date')
            ->limit(14)
            ->get(['sessions', 'script_errors']);

        if ($baselineRows->count() < 3) {
            return null; // not enough history to judge
        }

        $baseSessions = (int) $baselineRows->sum('sessions');
        $baseErrors = (int) $baselineRows->sum('script_errors');
        if ($baseSessions <= 0) {
            return null;
        }

        $latestRate = $errors / max((int) $latest->sessions, 1);
        $baselineRate = $baseErrors / $baseSessions;

        // Alert when the latest rate is at least 2x the baseline. Use a small
        // floor so a baseline of ~0 still requires a meaningful absolute rate.
        $threshold = max($baselineRate * 2, 0.05);
        if ($latestRate < $threshold) {
            return null;
        }

        $summary = sprintf(
            '%d errors across %d sessions (%.1f%% of sessions) vs baseline %.1f%% — %s.',
            $errors,
            (int) $latest->sessions,
            $latestRate * 100,
            $baselineRate * 100,
            $baselineRate > 0 ? sprintf('%.1fx', $latestRate / $baselineRate) : 'new (no prior errors)',
        );

        return [
            'summary' => $summary,
            'latest_rate' => round($latestRate, 4),
            'baseline_rate' => round($baselineRate, 4),
            'errors' => $errors,
        ];
    }

    protected function buildMarkdown(
        bool $isConfigured,
        bool $apiReachable,
        string $projectId,
        int $rowsCount,
        ?ClarityDailyMetric $latest,
        ?string $apiError,
        ?array $spike = null,
    ): string {
        $lines = [];
        $lines[] = '# Clarity health';
        $lines[] = '';
        $lines[] = 'Generated: ' . now()->toIso8601String();
        $lines[] = '';
        $lines[] = '## Status';
        $lines[] = '';
        $lines[] = '- Configured: ' . ($isConfigured ? 'yes' : 'no');
        $lines[] = '- API reachable: ' . ($apiReachable ? 'yes' : 'no');
        $lines[] = '- Project ID: ' . ($projectId !== '' ? $projectId : '(empty)');
        $lines[] = '- Stored rows: ' . $rowsCount;
        $lines[] = '- Latest row date: ' . ($latest?->date?->toDateString() ?? '(none)');
        $lines[] = '- Latest row age: ' . ($latest?->date?->diffForHumans() ?? '(none)');

        if ($latest) {
            $lines[] = '';
            $lines[] = '## Latest metrics';
            $lines[] = '';
            $lines[] = '| Metric | Value |';
            $lines[] = '|---|---:|';
            $lines[] = '| sessions | ' . (int) $latest->sessions . ' |';
            $lines[] = '| users | ' . (int) $latest->users . ' |';
            $lines[] = '| pageviews | ' . (int) $latest->pageviews . ' |';
            $lines[] = '| scroll depth | ' . (float) $latest->scroll_depth . ' |';
            $lines[] = '| active time seconds | ' . (int) $latest->active_time_seconds . ' |';
            $lines[] = '| bounce rate | ' . (float) $latest->bounce_rate . ' |';
            $lines[] = '| dead clicks | ' . (int) $latest->dead_clicks . ' |';
            $lines[] = '| rage clicks | ' . (int) $latest->rage_clicks . ' |';
            $lines[] = '| quickbacks | ' . (int) $latest->quickbacks . ' |';
            $lines[] = '| script errors | ' . (int) $latest->script_errors . ' |';
            $lines[] = '| error clicks | ' . (int) $latest->error_clicks . ' |';
        }

        if ($spike !== null) {
            $lines[] = '';
            $lines[] = '## ⚠ JavaScript error spike';
            $lines[] = '';
            $lines[] = '- ' . $spike['summary'];
            $lines[] = '- Latest rate: ' . ($spike['latest_rate'] * 100) . '% of sessions';
            $lines[] = '- Baseline rate: ' . ($spike['baseline_rate'] * 100) . '% of sessions';
            $lines[] = '';
            $lines[] = 'Clarity reports counts only. See `storage/logs/client-errors-*.log`'
                . ' (via /log-viewer) for the actual messages and stack traces.';
        }

        if (! $apiReachable && $apiError) {
            $lines[] = '';
            $lines[] = '## API error';
            $lines[] = '';
            $lines[] = '- ' . $apiError;
        }

        return implode("\n", $lines) . "\n";
    }
}
