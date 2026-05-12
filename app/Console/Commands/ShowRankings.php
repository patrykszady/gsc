<?php

namespace App\Console\Commands;

use App\Models\SeoRankSnapshot;
use Illuminate\Console\Command;

class ShowRankings extends Command
{
    protected $signature = 'seo:show-rankings {--engine= : google | google_maps}';

    protected $description = 'Print the latest stored ranking snapshots, including delta vs the previous run.';

    public function handle(): int
    {
        $engine = $this->option('engine');

        $latest = SeoRankSnapshot::latestForEach()
            ->when($engine, fn ($c) => $c->where('engine', $engine))
            ->sortBy(['engine', 'query'])
            ->values();

        if ($latest->isEmpty()) {
            $this->warn('No rank snapshots yet. Run: php artisan seo:track-rankings');
            return self::SUCCESS;
        }

        $rows = $latest->map(function (SeoRankSnapshot $s) {
            $prev = SeoRankSnapshot::query()
                ->forQuery($s->engine, $s->query, $s->location)
                ->where('id', '<', $s->id)
                ->latest('id')
                ->first();

            $delta = '—';
            if ($prev && $prev->gsc_position !== null && $s->gsc_position !== null) {
                $d = $prev->gsc_position - $s->gsc_position;
                $delta = $d === 0 ? '=' : ($d > 0 ? "▲{$d}" : '▼' . abs($d));
            } elseif ($s->gsc_position !== null && $prev && $prev->gsc_position === null) {
                $delta = 'NEW';
            } elseif ($s->gsc_position === null && $prev && $prev->gsc_position !== null) {
                $delta = 'DROPPED';
            }

            return [
                $s->engine,
                $s->query,
                $s->location ?? '',
                $s->gsc_position === null ? '—' : '#' . $s->gsc_position,
                $delta,
                $s->gsc_match_title ?? '',
                $s->fetched_at?->diffForHumans() ?? '',
            ];
        })->all();

        $this->table(['Engine', 'Query', 'Location', 'Pos', 'Δ', 'Matched as', 'When'], $rows);

        return self::SUCCESS;
    }
}
