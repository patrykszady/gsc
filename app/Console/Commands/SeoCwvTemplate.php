<?php

namespace App\Console\Commands;

use App\Models\PsiSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Per-template Core Web Vitals tracker.
 *
 * Classifies psi_snapshots URLs into templates (home / service / area /
 * project / other) via URL patterns and aggregates p75 LCP, INP, CLS plus
 * Lighthouse perf scores for each template over a recent window and a prior
 * window. Surfaces regressions per template + strategy.
 *
 * Pure read-only against the existing psi_snapshots table populated by the
 * existing sync command.
 */
class SeoCwvTemplate extends Command
{
    protected $signature = 'seo:cwv-template
        {--window=7 : Days in each comparison window}
        {--lcp-regress=150 : Flag templates whose p75 LCP grew by >=N ms (field/CrUX-backed)}
        {--lab-lcp-regress=500 : LCP threshold (ms) for lab-only buckets; lab mobile LCP is synthetic and noisy}
        {--inp-regress=40 : Flag templates whose p75 INP grew by >=N ms}
        {--cls-regress=0.02 : Flag templates whose p75 CLS grew by >=N}
        {--min-samples=5 : Require at least N samples in BOTH windows before flagging a regression}
        {--markdown : Save report to storage/app/reports/cwv-template.md}';

    protected $description = 'Aggregate Core Web Vitals per page template and surface week-over-week regressions.';

    public function handle(): int
    {
        $win = max(1, (int) $this->option('window'));
        $today = Carbon::today();
        $rStart = $today->copy()->subDays($win - 1);
        $pEnd = $rStart->copy()->subDay();
        $pStart = $pEnd->copy()->subDays($win - 1);

        $this->info(sprintf('Recent: %s..%s   Prior: %s..%s', $rStart->toDateString(), $today->toDateString(), $pStart->toDateString(), $pEnd->toDateString()));

        $recent = $this->aggregateWindow($rStart, $today);
        $prior  = $this->aggregateWindow($pStart, $pEnd);

        $rows = [];
        $alerts = [];
        $minSamples = max(1, (int) $this->option('min-samples'));
        foreach ($recent as $key => $r) {
            [$template, $strategy] = explode('|', $key, 2);
            $p = $prior[$key] ?? null;
            $lcpΔ = ($p && $p['lcp']) ? $r['lcp'] - $p['lcp'] : null;
            $inpΔ = ($p && $p['inp']) ? $r['inp'] - $p['inp'] : null;
            $clsΔ = ($p && $p['cls'] !== null) ? round($r['cls'] - $p['cls'], 3) : null;
            $perfΔ = ($p && $p['perf'] !== null) ? $r['perf'] - $p['perf'] : null;

            $rows[] = compact('template', 'strategy') + [
                'samples' => $r['samples'],
                'lcp' => $r['lcp'], 'lcpΔ' => $lcpΔ,
                'inp' => $r['inp'], 'inpΔ' => $inpΔ,
                'cls' => $r['cls'], 'clsΔ' => $clsΔ,
                'perf' => $r['perf'], 'perfΔ' => $perfΔ,
            ];

            // Only flag regressions backed by enough data in BOTH windows; a
            // p75 derived from a handful of samples (e.g. when PSI gaps shrink a
            // bucket) swings wildly and produces false alerts.
            $enoughSamples = $r['samples'] >= $minSamples && $p && $p['samples'] >= $minSamples;
            if (! $enoughSamples) {
                continue;
            }

            // Lab-only LCP (no CrUX field data) is synthetic and noisy, so it
            // uses a more tolerant threshold than real-user field LCP.
            $lcpThreshold = ($r['lcp_field'] ?? false)
                ? (int) $this->option('lcp-regress')
                : (int) $this->option('lab-lcp-regress');
            if ($lcpΔ !== null && $lcpΔ >= $lcpThreshold) {
                $src = ($r['lcp_field'] ?? false) ? 'field' : 'lab';
                $alerts[] = "{$template} [{$strategy}] LCP +{$lcpΔ} ms ({$src})";
            }
            if ($inpΔ !== null && $inpΔ >= (int) $this->option('inp-regress')) {
                $alerts[] = "{$template} [{$strategy}] INP +{$inpΔ} ms";
            }
            if ($clsΔ !== null && $clsΔ >= (float) $this->option('cls-regress')) {
                $alerts[] = "{$template} [{$strategy}] CLS +{$clsΔ}";
            }
        }

        usort($rows, fn ($a, $b) => [$a['template'], $a['strategy']] <=> [$b['template'], $b['strategy']]);
        $this->renderTable($rows);

        if (! empty($alerts)) {
            $this->newLine();
            $this->line('<fg=yellow>--- Regressions ---</>');
            foreach ($alerts as $a) $this->line('  • ' . $a);
            logger()->warning('seo:cwv-template regressions', ['alerts' => $alerts]);
        } else {
            $this->newLine();
            $this->info('No CWV regressions detected.');
        }

        if ($this->option('markdown')) {
            $this->saveMarkdown($rows, $alerts, $rStart, $today, $pStart, $pEnd);
        }
        return self::SUCCESS;
    }

    /**
     * @return array<string, array{samples:int,lcp:?int,inp:?int,cls:?float,perf:?int}>
     */
    protected function aggregateWindow(Carbon $from, Carbon $to): array
    {
        $rows = PsiSnapshot::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get(['url', 'strategy', 'field_lcp_ms', 'field_inp_ms', 'field_cls', 'lab_lcp_ms', 'performance']);

        $buckets = [];
        foreach ($rows as $r) {
            $tpl = $this->classify((string) $r->url);
            $key = $tpl . '|' . $r->strategy;
            $buckets[$key][] = [
                // Prefer field (CrUX) when present, fall back to lab LCP.
                'lcp' => $r->field_lcp_ms ?? $r->lab_lcp_ms,
                'lcp_is_field' => $r->field_lcp_ms !== null,
                'inp' => $r->field_inp_ms,
                'cls' => $r->field_cls !== null ? (float) $r->field_cls : null,
                'perf' => $r->performance,
            ];
        }

        $out = [];
        foreach ($buckets as $key => $list) {
            $fieldLcp = count(array_filter(array_column($list, 'lcp_is_field')));
            $out[$key] = [
                'samples' => count($list),
                // LCP is "field-backed" only when CrUX data exists; otherwise the
                // value is synthetic Lighthouse lab LCP, which is far noisier.
                'lcp_field' => $fieldLcp > 0,
                'lcp'  => $this->p75Int(array_column($list, 'lcp')),
                'inp'  => $this->p75Int(array_column($list, 'inp')),
                'cls'  => $this->p75Float(array_column($list, 'cls')),
                'perf' => $this->p75Int(array_column($list, 'perf')),
            ];
        }
        return $out;
    }

    protected function classify(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        return match (true) {
            $path === '' || $path === '/' => 'home',
            str_starts_with($path, '/services/') => 'service',
            str_starts_with($path, '/areas-served/') => 'area',
            str_starts_with($path, '/projects/') || str_starts_with($path, '/portfolio') => 'project',
            str_starts_with($path, '/testimonials') || str_starts_with($path, '/reviews') => 'review',
            default => 'other',
        };
    }

    /** @param array<int, int|null> $vals */
    protected function p75Int(array $vals): ?int
    {
        $vals = array_values(array_filter($vals, fn ($v) => $v !== null));
        if (empty($vals)) return null;
        sort($vals);
        $i = (int) floor(0.75 * (count($vals) - 1));
        return (int) $vals[$i];
    }

    /** @param array<int, float|null> $vals */
    protected function p75Float(array $vals): ?float
    {
        $vals = array_values(array_filter($vals, fn ($v) => $v !== null));
        if (empty($vals)) return null;
        sort($vals);
        $i = (int) floor(0.75 * (count($vals) - 1));
        return round((float) $vals[$i], 3);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    protected function renderTable(array $rows): void
    {
        $this->newLine();
        $this->line('<fg=cyan>--- p75 CWV per template (recent | Δ vs prior) ---</>');
        $tbl = array_map(fn ($r) => [
            $r['template'],
            $r['strategy'],
            $r['samples'],
            $this->fmt($r['lcp'], $r['lcpΔ'], 'ms'),
            $this->fmt($r['inp'], $r['inpΔ'], 'ms'),
            $this->fmt($r['cls'], $r['clsΔ'], '', 3),
            $this->fmt($r['perf'], $r['perfΔ'], ''),
        ], $rows);
        $this->table(['Template', 'Strategy', 'Samples', 'LCP p75', 'INP p75', 'CLS p75', 'Perf'], $tbl);
    }

    protected function fmt(int|float|null $v, int|float|null $d, string $unit, int $decimals = 0): string
    {
        if ($v === null) return '—';
        $base = $decimals > 0 ? number_format((float) $v, $decimals) : (string) $v;
        if ($unit !== '') $base .= $unit;
        if ($d === null) return $base;
        $sign = $d > 0 ? '+' : '';
        $deltaStr = $decimals > 0 ? number_format((float) $d, $decimals) : (string) $d;
        return $base . ' (' . $sign . $deltaStr . ')';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $alerts
     */
    protected function saveMarkdown(array $rows, array $alerts, Carbon $rs, Carbon $re, Carbon $ps, Carbon $pe): void
    {
        $md = "# CWV per-template report\n\n";
        $md .= 'Run: ' . now()->toIso8601String() . "\n\n";
        $md .= sprintf("Recent: %s..%s   Prior: %s..%s\n\n", $rs->toDateString(), $re->toDateString(), $ps->toDateString(), $pe->toDateString());

        $md .= "## p75 metrics by template + strategy\n\n";
        $md .= "| Template | Strategy | Samples | LCP p75 (Δ) | INP p75 (Δ) | CLS p75 (Δ) | Perf (Δ) |\n|---|---|---:|---|---|---|---|\n";
        foreach ($rows as $r) {
            $md .= sprintf("| %s | %s | %d | %s | %s | %s | %s |\n",
                $r['template'], $r['strategy'], $r['samples'],
                $this->fmt($r['lcp'], $r['lcpΔ'], 'ms'),
                $this->fmt($r['inp'], $r['inpΔ'], 'ms'),
                $this->fmt($r['cls'], $r['clsΔ'], '', 3),
                $this->fmt($r['perf'], $r['perfΔ'], ''),
            );
        }

        $md .= "\n## Alerts\n\n";
        $md .= empty($alerts) ? "_None._\n" : ('- ' . implode("\n- ", $alerts) . "\n");

        Storage::disk('local')->put('reports/cwv-template.md', $md);
        $this->info('Saved: storage/app/reports/cwv-template.md');
    }
}
