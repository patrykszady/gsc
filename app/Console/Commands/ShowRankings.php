<?php

namespace App\Console\Commands;

use App\Models\SeoRankSnapshot;
use Illuminate\Console\Command;

class ShowRankings extends Command
{
    protected $signature = 'seo:show-rankings {--engine= : google | google_maps} {--competitors : Show competitor positions per query}';

    protected $description = 'Print the latest stored ranking snapshots, including delta vs the previous run.';

    public function handle(): int
    {
        $engine = $this->option('engine');
        $showCompetitors = (bool) $this->option('competitors');

        $latest = SeoRankSnapshot::latestForEach()
            ->when($engine, fn ($c) => $c->where('engine', $engine))
            ->sortBy(['engine', 'query'])
            ->values();

        if ($latest->isEmpty()) {
            $this->warn('No rank snapshots yet. Run: php artisan seo:track-rankings');
            return self::SUCCESS;
        }

        if ($showCompetitors) {
            return $this->renderCompetitors($latest);
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

    /**
     * Render the latest snapshot as a competitor share-of-voice table:
     * one row per query, columns = us + each tracked competitor.
     *
     * @param \Illuminate\Support\Collection<int,SeoRankSnapshot> $latest
     */
    protected function renderCompetitors(\Illuminate\Support\Collection $latest): int
    {
        $competitorKeys = array_keys((array) config('seo.rank_tracker.competitor_patterns', []));
        if (empty($competitorKeys)) {
            $this->warn('No competitors configured in config/seo.php → rank_tracker.competitor_patterns');
            return self::SUCCESS;
        }

        $headers = array_merge(['Engine', 'Query', 'Us'], $competitorKeys);
        $rows = $latest->map(function (SeoRankSnapshot $s) use ($competitorKeys) {
            $row = [
                $s->engine,
                \Illuminate\Support\Str::limit($s->query, 38, ''),
                $s->gsc_position === null ? '—' : '#' . $s->gsc_position,
            ];
            $comp = $s->meta['competitors'] ?? [];
            foreach ($competitorKeys as $key) {
                $pos = $comp[$key]['position'] ?? null;
                $row[] = $pos === null ? '—' : '#' . $pos;
            }
            return $row;
        })->all();

        $this->table($headers, $rows);

        return self::SUCCESS;
    }
}
