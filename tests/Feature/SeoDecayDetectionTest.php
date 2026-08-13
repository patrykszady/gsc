<?php

namespace Tests\Feature;

use App\Models\SeoAction;
use App\Services\Seo\RecommendationEngine;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * "Refresh pages losing visibility" must mean the page actually lost ranking.
 *
 * The detector compared trailing 7d impressions against the previous 7d with no
 * rank check and no baseline. On production it flagged /areas-served/schaumburg
 * for falling 1,312 -> 104 impressions while its position went 3.9 -> 4.0: the
 * SERP simply stopped showing it as often. Rewriting the page could not recover
 * those impressions, and two of the three flagged pages had title experiments
 * mid-flight, so acting on the advice would have destroyed the measurement.
 */
class SeoDecayDetectionTest extends TestCase
{
    /** @return array<int, array<string, mixed>> */
    private function decay(): array
    {
        $engine = app(RecommendationEngine::class);
        $method = new ReflectionMethod($engine, 'decayRecs');
        $method->setAccessible(true);

        return $method->invoke($engine);
    }

    private function seedPage(string $page, int $priorImp, int $recentImp, float $priorPos, float $recentPos): void
    {
        $end = now()->startOfDay();
        $rows = [];

        // Prior week (days 13..7 back) and recent week (days 6..0 back).
        foreach ([[13, 7, $priorImp, $priorPos], [6, 0, $recentImp, $recentPos]] as [$from, $to, $imp, $pos]) {
            $days = $from - $to + 1;
            for ($d = $from; $d >= $to; $d--) {
                $rows[] = [
                    'site_id' => 1,
                    'date' => $end->copy()->subDays($d)->toDateString(),
                    'site_url' => 'https://gs.construction/',
                    'query' => 'seeded query',
                    'page' => $page,
                    'country' => 'usa',
                    'device' => 'DESKTOP',
                    'impressions' => intdiv($imp, $days),
                    'clicks' => 0,
                    'ctr' => 0,
                    'position' => $pos,
                    'dim_hash' => sha1($page . $d . microtime(true) . random_int(0, PHP_INT_MAX)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        DB::table('gsc_query_metrics')->insert($rows);
    }

    public function test_a_flat_ranking_impression_drop_is_not_reported_as_decay(): void
    {
        DB::table('gsc_query_metrics')->delete();
        // The Schaumburg shape: impressions collapse, position essentially still.
        $this->seedPage('https://gs.construction/areas-served/flat-rank-town', 1400, 100, 3.9, 4.0);

        $this->assertSame([], $this->decay(), 'a rank-neutral impression drop is SERP volatility, not decay');
    }

    public function test_a_real_ranking_loss_is_still_reported(): void
    {
        DB::table('gsc_query_metrics')->delete();
        $this->seedPage('https://gs.construction/areas-served/sinking-town', 1400, 100, 6.0, 18.0);

        $recs = $this->decay();

        $this->assertNotEmpty($recs, 'a genuine rank collapse must still be surfaced');
        $this->assertStringContainsString('sinking-town', $recs[0]['d']);
    }

    public function test_a_page_with_an_experiment_in_flight_is_never_flagged(): void
    {
        DB::table('gsc_query_metrics')->delete();
        $url = 'https://gs.construction/areas-served/under-test-town';
        $this->seedPage($url, 1400, 100, 6.0, 18.0);

        SeoAction::create([
            'site_id' => 1,
            'fingerprint' => sha1('test-in-flight'),
            'source' => 'test',
            'category' => 'title_meta',
            'target_url' => $url,
            'title' => 'Title experiment',
            'status' => SeoAction::STATUS_APPLIED,
            'applied_at' => now()->subDays(3),
            'measured_at' => null,
            'metric' => 'clicks',
            'baseline_value' => 0,
        ]);

        $this->assertSame([], $this->decay(), 'never ask for an edit to a page whose experiment is still running');
    }
}
