<?php

namespace App\Console\Commands;

use App\Models\SeoAction;
use App\Services\Seo\SeoAutopilotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The self-improving SEO/GEO loop. Runs after the seo:* analysis commands have
 * refreshed their signals for the day:
 *
 *   1. synthesize — build/refresh the scored action ledger from GSC + coverage
 *   2. measure    — close out applied actions whose learning window elapsed,
 *                   recording whether they actually moved the metric
 *   3. act        — auto-apply the top safe/reversible actions (full-auto)
 *
 * Everything applied is reversible and metric-baselined; run `--dry-run` to see
 * what it *would* do, or `--no-act` to only synthesize + measure.
 */
class SeoAutopilot extends Command
{
    protected $signature = 'seo:autopilot
        {--dry-run : Synthesize + score, print what would be applied, change nothing}
        {--no-act : Synthesize + measure only; do not auto-apply}
        {--measure-only : Only run the measurement/learning pass}
        {--max=25 : Max actions to auto-apply this run}
        {--markdown : Write reports/autopilot.md}';

    protected $description = 'Self-improving SEO/GEO autopilot: synthesize signals, auto-apply safe fixes, measure outcomes, learn.';

    public function handle(SeoAutopilotService $autopilot): int
    {
        $measureOnly = (bool) $this->option('measure-only');
        $dryRun = (bool) $this->option('dry-run');

        // --- Measure first so freshly-closed outcomes inform this run's scoring.
        $measured = $autopilot->measure();
        $this->info(sprintf(
            'Measured %d due action(s): %d worked, %d regressed, %d no-effect.',
            $measured['measured'], $measured['worked'], $measured['regressed'], $measured['no_effect']
        ));

        // Safety net: auto-revert anything that measured as a regression.
        $reverted = 0;
        foreach (SeoAction::applied()->where('outcome', SeoAction::OUTCOME_REGRESSED)->get() as $bad) {
            if ($bad->auto_applied && $bad->isRevertible()) {
                $autopilot->revert($bad);
                $reverted++;
            }
        }
        if ($reverted > 0) {
            $this->warn("Auto-reverted {$reverted} regressed action(s).");
        }

        if ($measureOnly) {
            $this->maybeWriteReport($autopilot);
            return self::SUCCESS;
        }

        // --- Synthesize.
        $created = $autopilot->synthesize();
        $this->info("Synthesized {$created} new action(s). Open ledger: " . SeoAction::open()->count());

        // --- Act.
        if ($this->option('no-act')) {
            $this->line('Skipping apply (--no-act).');
            $this->maybeWriteReport($autopilot);
            return self::SUCCESS;
        }

        $result = $autopilot->act($dryRun, (int) $this->option('max'));

        $this->table(
            ['ID', 'Priority', 'Result', 'Action'],
            collect($result['items'])->map(fn ($i) => [
                $i['id'] ?? '—',
                isset($i['priority']) ? number_format((float) $i['priority'], 1) : '—',
                $i['result'] ?? '—',
                \Illuminate\Support\Str::limit((string) ($i['title'] ?? ''), 60),
            ])->all()
        );

        if ($dryRun) {
            $this->comment('Dry run — nothing was changed.');
        } else {
            $this->info("Applied {$result['applied']}, failed {$result['failed']}.");
            Log::info('seo:autopilot run', [
                'created' => $created,
                'applied' => $result['applied'],
                'failed' => $result['failed'],
                'reverted' => $reverted,
                'measured' => $measured,
            ]);
        }

        $this->maybeWriteReport($autopilot);

        return self::SUCCESS;
    }

    private function maybeWriteReport(SeoAutopilotService $autopilot): void
    {
        if (! $this->option('markdown')) {
            return;
        }

        $open = SeoAction::open()->orderByDesc('priority')->limit(40)->get();
        $applied = SeoAction::whereIn('status', [SeoAction::STATUS_APPLIED, SeoAction::STATUS_REVERTED])
            ->orderByDesc('applied_at')->limit(40)->get();

        $lines = [];
        $lines[] = '# SEO Autopilot';
        $lines[] = '';
        $lines[] = '_Generated: ' . now()->toIso8601String() . '_';
        $lines[] = '';

        $lines[] = '## Learned weights (what works on this site)';
        $lines[] = '';
        $lines[] = '| Category | Weight | Worked | Regressed | No-effect |';
        $lines[] = '|---|---:|---:|---:|---:|';
        foreach (SeoAutopilotService::SAFE_ALLOWLIST as $cat) {
            $w = $autopilot->learnedWeight($cat);
            $worked = SeoAction::where('category', $cat)->where('outcome', SeoAction::OUTCOME_WORKED)->count();
            $reg = SeoAction::where('category', $cat)->where('outcome', SeoAction::OUTCOME_REGRESSED)->count();
            $ne = SeoAction::where('category', $cat)->where('outcome', SeoAction::OUTCOME_NO_EFFECT)->count();
            $lines[] = "| {$cat} | {$w} | {$worked} | {$reg} | {$ne} |";
        }
        $lines[] = '';

        $lines[] = '## Top open actions';
        $lines[] = '';
        $lines[] = '| Priority | Category | Action | Hypothesis |';
        $lines[] = '|---:|---|---|---|';
        foreach ($open as $a) {
            $lines[] = sprintf('| %.1f | %s | %s | %s |', $a->priority, $a->category, str_replace('|', '\\|', (string) $a->title), str_replace('|', '\\|', \Illuminate\Support\Str::limit((string) $a->hypothesis, 140)));
        }
        $lines[] = '';

        $lines[] = '## Recently applied / reverted';
        $lines[] = '';
        $lines[] = '| Applied | Status | Outcome | Δ% | Action |';
        $lines[] = '|---|---|---|---:|---|';
        foreach ($applied as $a) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s |',
                $a->applied_at?->diffForHumans() ?? '—',
                $a->status,
                $a->outcome ?? '—',
                $a->delta_pct !== null ? number_format($a->delta_pct, 0) : '—',
                str_replace('|', '\\|', (string) $a->title)
            );
        }

        Storage::disk('local')->put('reports/autopilot.md', implode("\n", $lines));
        $this->info('Wrote reports/autopilot.md');
    }
}
