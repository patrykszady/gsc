<?php

namespace App\Support\Seo;

use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * The pages where visitors are getting frustrated — and getting MORE
 * frustrated than they were.
 *
 * One computation, two readers: the recommendation engine turns the top of
 * this list into an action, and the SEO screen's behaviour card shows it as
 * a list. Keeping it here means the card and the recommendation can never
 * disagree about which pages are the problem.
 *
 * "Frustration" is rage clicks + dead clicks + quickbacks, per session, over
 * the last `$days` against the `$days` before. A page qualifies when it has
 * enough visits for the rate to mean anything, the rate is materially high,
 * and it has risen — a page that was always a bit rough is a different
 * conversation from one that just got worse. Ranked by sessions at stake,
 * so the top entry is the page costing the most, not the worst ratio on a
 * page nobody visits.
 *
 * Search impressions over the last 28 days ride along so the reader can see
 * that the page is one search already sends people to: that is what makes
 * fixing it worth more than fixing a page nobody finds.
 */
final class FrustratedPages
{
    public const MIN_SESSIONS = 20;

    public const MIN_PRIOR_SESSIONS = 10;

    public const MIN_RATE = 0.10;

    /**
     * @return list<array{path:string, sessions:int, frustrations:int, rate:float, prior_rate:float|null, impressions:int}>
     */
    public static function rising(int $days = 7, int $limit = 3): array
    {
        if (! Schema::hasTable('clarity_page_metrics')) {
            return [];
        }

        $latest = Tenancy::table('clarity_page_metrics')->max('date');
        if (! $latest) {
            return [];
        }

        $end = Carbon::parse($latest);
        $window = function (Carbon $from, Carbon $to): array {
            $rows = Tenancy::table('clarity_page_metrics')
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->selectRaw('path, SUM(sessions) sessions, SUM(rage_clicks + dead_clicks + quickbacks) frustrations')
                ->groupBy('path')
                ->get();

            $out = [];
            foreach ($rows as $r) {
                $out[(string) $r->path] = ['sessions' => (int) $r->sessions, 'frustrations' => (int) $r->frustrations];
            }

            return $out;
        };

        $now = $window((clone $end)->subDays($days - 1), $end);
        $prior = $window((clone $end)->subDays(2 * $days - 1), (clone $end)->subDays($days));

        $candidates = [];
        foreach ($now as $path => $n) {
            if ($n['sessions'] < self::MIN_SESSIONS) {
                continue;
            }
            $p = $prior[$path] ?? null;
            if (! $p || $p['sessions'] < self::MIN_PRIOR_SESSIONS) {
                continue;
            }

            $rate = $n['frustrations'] / $n['sessions'];
            $priorRate = $p['frustrations'] / $p['sessions'];

            // Materially high, and materially worse than it was: half again
            // the old rate AND at least five points, so a page at 2% that
            // ticks to 3% does not read as a crisis.
            if ($rate < self::MIN_RATE || $rate < max($priorRate * 1.5, $priorRate + 0.05)) {
                continue;
            }

            $candidates[] = [
                'path' => $path,
                'sessions' => $n['sessions'],
                'frustrations' => $n['frustrations'],
                'rate' => round($rate, 4),
                'prior_rate' => round($priorRate, 4),
                'impressions' => 0,
            ];
        }

        if ($candidates === []) {
            return [];
        }

        usort($candidates, fn ($a, $b) => $b['sessions'] <=> $a['sessions']);
        $candidates = array_slice($candidates, 0, $limit);

        $impressions = self::searchImpressionsByPath((clone $end)->subDays(27), $end);
        foreach ($candidates as &$c) {
            $c['impressions'] = $impressions[$c['path']] ?? 0;
        }
        unset($c);

        return $candidates;
    }

    /**
     * Search impressions per normalised path — the join that makes a
     * frustrated page an SEO problem rather than only a UX one.
     *
     * @return array<string, int>
     */
    private static function searchImpressionsByPath(Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('gsc_query_metrics')) {
            return [];
        }

        $rows = Tenancy::table('gsc_query_metrics')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('page')
            ->selectRaw('page, SUM(impressions) impressions')
            ->groupBy('page')
            ->orderByDesc('impressions')
            ->limit(500)
            ->get();

        $byPath = [];
        foreach ($rows as $r) {
            $path = self::normalisePath((string) $r->page);
            $byPath[$path] = ($byPath[$path] ?? 0) + (int) $r->impressions;
        }

        return $byPath;
    }

    /** The same normalisation the Clarity sync applies before writing. */
    public static function normalisePath(string $urlOrPath): string
    {
        $path = parse_url($urlOrPath, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? rawurldecode($path) : '/';
        $path = '/'.ltrim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
