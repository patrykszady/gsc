<?php

namespace App\Console\Commands;

use App\Console\Commands\Seo\KitReportCommand;
use App\Support\SeoStorage;
use Illuminate\Support\Facades\Storage;
use SsSystems\Platform\Reports\HealthReport;

/**
 * Unified SEO health dashboard — thin wrapper. The five pillars, their
 * weights, and the health-score ledger merge live in the kit's HealthReport
 * now (it appends the ledger itself on every run — see
 * vendor/ss-systems/platform-kit/docs/REPORTS-PORTING.md); this command is
 * left with only its own artisan surface: --json, --quiet-on-pass, and the
 * exit-code rule tied to the numeric score, not to the report's ok/degraded
 * status (a "degraded" result here just means some pillar is unmeasured,
 * which is orthogonal to whether the measured score cleared 70).
 */
class SeoHealth extends KitReportCommand
{
    protected $signature = 'seo:health
        {--json : Output JSON only}
        {--markdown : Save markdown report to storage/app/reports/health.md}
        {--quiet-on-pass : Exit silently when score >= 90}';

    protected $description = 'Unified local-SEO health dashboard (score 0-100 across five pillars).';

    public function handle(HealthReport $report): int
    {
        $quietOnPass = (bool) $this->option('quiet-on-pass');
        $result = $report->generate(['quiet_on_pass' => $quietOnPass]);
        $total = $result->data['score'];

        if ($this->option('json')) {
            $this->line(json_encode([
                'score' => $total,
                'pillars' => $result->data['pillars'],
                'generated_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($quietOnPass && $total !== null && $total >= 90) {
            return self::SUCCESS;
        }

        if ($this->option('markdown')) {
            Storage::disk('local')->put(SeoStorage::path('reports/'.HealthReport::key().'.md'), $result->markdown);
        }

        $this->line($result->summary);
        $this->renderReport($total, $result->data['pillars'], $result->data['grade']);

        return $total !== null && $total >= 70 ? self::SUCCESS : self::FAILURE;
    }

    /** @param  list<array<string, mixed>>  $pillars */
    protected function renderReport(?int $total, array $pillars, string $grade): void
    {
        $this->newLine();
        $scoreLabel = $total === null ? 'not measured yet' : "{$total}/100";
        $this->line("<options=bold>SEO Health Score:</> {$scoreLabel} ({$grade})");
        $this->newLine();

        $rows = array_map(fn (array $p): array => [
            'pillar' => $p['name'],
            'score' => $p['score'] === null ? 'not measured' : $p['score'],
            'bar' => $p['bar'],
        ], $pillars);
        $this->table(['Pillar', 'Score', 'Bar'], array_map('array_values', $rows));

        foreach ($pillars as $p) {
            $this->line('<options=bold>'.$p['name'].'</> — '.($p['score'] === null ? 'not measured yet' : "{$p['score']}/100"));
            foreach ($p['metrics'] as $key => $val) {
                $this->line("  · {$key}: {$val}");
            }
            if (! empty($p['fix'])) {
                $this->line("  <fg=yellow>→ {$p['fix']}</>");
            }
            $this->newLine();
        }
    }
}
