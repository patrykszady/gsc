<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use Illuminate\Console\Command;

/**
 * Reports which AreaServed rows are missing the per-city content fields
 * (intro, local_intro, landmarks, permit_notes). Pages without these fields
 * render a near-duplicate template across cities, which Google may treat as
 * "thin local lander" content. Goal: drive these to zero.
 */
class SeoContentDepthAudit extends Command
{
    protected $signature = 'seo:content-depth-audit
        {--missing : Show only areas missing one or more content fields}
        {--json : Output JSON instead of table}
        {--limit=200 : Max rows shown}';

    protected $description = 'Audit AreaServed rows for unique per-city content depth (intro, local_intro, landmarks, permit_notes).';

    public function handle(): int
    {
        $rows = AreaServed::query()
            ->orderBy('city')
            ->limit((int) $this->option('limit'))
            ->get(['id', 'city', 'slug', 'intro', 'local_intro', 'landmarks', 'permit_notes']);

        $report = $rows->map(function (AreaServed $a) {
            $missing = [];
            foreach (['intro', 'local_intro', 'landmarks', 'permit_notes'] as $f) {
                if (blank($a->{$f})) {
                    $missing[] = $f;
                }
            }
            $filled = 4 - count($missing);

            return [
                'city'     => $a->city,
                'slug'     => $a->slug,
                'filled'   => "{$filled}/4",
                'missing'  => implode(', ', $missing) ?: '—',
                'score'    => $filled,
                'intro_chars' => mb_strlen((string) $a->intro),
            ];
        });

        if ($this->option('missing')) {
            $report = $report->filter(fn ($r) => $r['score'] < 4)->values();
        }

        if ($this->option('json')) {
            $this->line(json_encode($report->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        if ($report->isEmpty()) {
            $this->info('All areas have complete per-city content. ✓');
            return self::SUCCESS;
        }

        $this->table(
            ['City', 'Slug', 'Filled', 'Missing fields', 'intro chars'],
            $report->map(fn ($r) => [$r['city'], $r['slug'], $r['filled'], $r['missing'], $r['intro_chars']])->all()
        );

        $totalMissing = $report->where('score', '<', 4)->count();
        $totalEmpty   = $report->where('score', 0)->count();
        $this->newLine();
        $this->warn("Areas with at least one missing field: {$totalMissing}");
        $this->warn("Areas with NO unique content (will render generic template): {$totalEmpty}");
        $this->line('Tip: prioritise the busiest cities first (use seo:title-audit to rank by impressions).');

        return self::SUCCESS;
    }
}
