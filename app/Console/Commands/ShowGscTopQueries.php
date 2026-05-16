<?php

namespace App\Console\Commands;

use App\Models\GscQueryMetric;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Show top queries from Search Console data, ranked by opportunity:
 * impressions × (1 - CTR) × position-decay. Highlights pages where you're
 * showing up but not getting clicks — usually a meta/title problem.
 */
class ShowGscTopQueries extends Command
{
    protected $signature = 'seo:gsc-top
        {--days=28}
        {--limit=30}
        {--min-impressions=10}
        {--country=usa}
        {--mode=opportunity : opportunity|clicks|impressions|striking-distance}';

    protected $description = 'Show top GSC queries (opportunity / clicks / impressions / striking-distance)';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $limit = (int) $this->option('limit');
        $minImp = (int) $this->option('min-impressions');
        $country = $this->option('country');
        $mode = $this->option('mode');

        $from = now()->subDays($days)->toDateString();

        $q = GscQueryMetric::query()
            ->where('date', '>=', $from)
            ->when($country, fn ($qq) => $qq->where('country', $country))
            ->groupBy('query')
            ->select([
                'query',
                DB::raw('SUM(impressions) as imp'),
                DB::raw('SUM(clicks) as clk'),
                DB::raw('CASE WHEN SUM(impressions) > 0 THEN SUM(clicks)*1.0/SUM(impressions) ELSE 0 END as ctr'),
                DB::raw('AVG(position) as pos'),
            ])
            ->having('imp', '>=', $minImp);

        match ($mode) {
            'clicks' => $q->orderByDesc('clk'),
            'impressions' => $q->orderByDesc('imp'),
            'striking-distance' => $q
                ->havingBetween('pos', [5, 20])
                ->orderByDesc('imp'),
            default => $q->orderByRaw('SUM(impressions) * (1 - (SUM(clicks)*1.0/NULLIF(SUM(impressions),0))) / GREATEST(AVG(position), 1) DESC'),
        };

        $rows = $q->limit($limit)->get();
        if ($rows->isEmpty()) {
            $this->warn('No GSC data. Run: php artisan seo:gsc-sync');
            return self::SUCCESS;
        }

        $this->table(
            ['Query', 'Imp', 'Clicks', 'CTR%', 'Avg Pos'],
            $rows->map(fn ($r) => [
                mb_strimwidth($r->query, 0, 60, '…'),
                $r->imp,
                $r->clk,
                sprintf('%.1f', $r->ctr * 100),
                sprintf('%.1f', $r->pos),
            ])->all(),
        );
        $this->info("Mode: {$mode} | Last {$days} days | Country: " . ($country ?: 'all'));
        return self::SUCCESS;
    }
}
