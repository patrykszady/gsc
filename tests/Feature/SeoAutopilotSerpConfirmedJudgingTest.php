<?php

namespace Tests\Feature;

use App\Models\SeoAction;
use App\Models\SeoRankSnapshot;
use App\Services\Seo\SeoAutopilotService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A5: MetricProbe alone produced the Aug 2026 false ranking-collapse
 * documented on DataForSeoService (a flood of new deep-position impressions
 * read as a collapse with nothing wrong in the SERP). measure() now checks
 * the nearest real DataForSEO position (seo_rank_snapshots, engine=google)
 * around baseline and re-measurement: when it disagrees with a GSC-measured
 * regression, the outcome is 'inconclusive' — never auto-reverted — instead
 * of 'regressed'.
 */
class SeoAutopilotSerpConfirmedJudgingTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeAppliedAction(array $overrides = []): SeoAction
    {
        return SeoAction::create(array_merge([
            'fingerprint' => 'fp-'.md5(serialize($overrides)),
            'source' => 'striking_distance',
            'category' => 'title_meta',
            'risk' => SeoAction::RISK_SAFE,
            'status' => SeoAction::STATUS_APPLIED,
            'auto_applied' => true,
            'target_url' => 'https://gs.construction/areas-served/example',
            'title' => 'Rewrite title/meta: /areas-served/example',
            'hypothesis' => 'h',
            'metric' => 'clicks',
            'payload' => ['new_title' => 'New Title'],
            'baseline_value' => 100.0,
            'baseline_at' => now()->subDays(21),
            'measure_after' => now()->subDay(),
            'outcome' => SeoAction::OUTCOME_PENDING,
        ], $overrides));
    }

    private function seedGsc(string $url, int $clicks, int $impressions, Carbon $when): void
    {
        DB::table('gsc_query_metrics')->insert([
            'site_id' => null,
            'date' => $when->toDateString(),
            'site_url' => 'sc-domain:gs.construction',
            'query' => 'irrelevant query',
            'page' => $url,
            'country' => 'usa',
            'device' => 'DESKTOP',
            'impressions' => $impressions,
            'clicks' => $clicks,
            'position' => 5.0,
            'ctr' => 0,
            'dim_hash' => md5($url.$when->toDateString().$clicks.$impressions),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeSerpSnapshot(string $matchedUrl, ?int $position, Carbon $fetchedAt): void
    {
        SeoRankSnapshot::create([
            'engine' => 'google',
            'query' => 'kitchen remodeling example il',
            'location' => 'Example, Illinois, United States',
            'city_slug' => 'example',
            'gsc_position' => $position,
            'top_results' => [],
            'meta' => ['source' => 'dataforseo_standard_advanced', 'matched_url' => $matchedUrl],
            'fetched_at' => $fetchedAt,
        ]);
    }

    public function test_gsc_regression_contradicted_by_a_stable_serp_is_inconclusive_not_reverted(): void
    {
        Carbon::setTestNow('2026-09-16 08:00:00');
        $url = 'https://gs.construction/areas-served/disagree';
        $action = $this->makeAppliedAction(['fingerprint' => 'fp-disagree', 'target_url' => $url]);
        $this->seedGsc($url, 30, 300, now()); // 100 -> 30 clicks = -70%, a judge()-level regression

        $this->makeSerpSnapshot($url, 4, $action->baseline_at); // real position at baseline: #4
        $this->makeSerpSnapshot($url, 3, now());                // real position now: #3 — improved, not collapsed

        $result = app(SeoAutopilotService::class)->measure();

        $action->refresh();
        $this->assertSame(SeoAction::OUTCOME_INCONCLUSIVE, $action->outcome);
        $this->assertSame(4, $action->payload['serp_position_before']);
        $this->assertSame(3, $action->payload['serp_position_after']);
        $this->assertSame(0, $result['regressed'], 'the disagreement must not count toward the regressed tally the auto-revert safety net reads');
    }

    public function test_gsc_regression_confirmed_by_a_worse_serp_position_stays_regressed(): void
    {
        Carbon::setTestNow('2026-09-16 08:00:00');
        $url = 'https://gs.construction/areas-served/agree';
        $action = $this->makeAppliedAction(['fingerprint' => 'fp-agree', 'target_url' => $url]);
        $this->seedGsc($url, 30, 300, now());

        $this->makeSerpSnapshot($url, 4, $action->baseline_at);
        $this->makeSerpSnapshot($url, 15, now()); // also fell — agrees with GSC

        app(SeoAutopilotService::class)->measure();

        $action->refresh();
        $this->assertSame(SeoAction::OUTCOME_REGRESSED, $action->outcome, 'when GSC and the real SERP agree, behaviour is unchanged');
        $this->assertSame(15, $action->payload['serp_position_after']);
    }

    public function test_no_serp_snapshot_on_either_side_leaves_the_gsc_verdict_untouched(): void
    {
        Carbon::setTestNow('2026-09-16 08:00:00');
        $url = 'https://gs.construction/areas-served/nodata';
        $action = $this->makeAppliedAction(['fingerprint' => 'fp-nodata', 'target_url' => $url]);
        $this->seedGsc($url, 30, 300, now());
        // No seo_rank_snapshots rows at all — the guard must not invent a disagreement from silence.

        app(SeoAutopilotService::class)->measure();

        $action->refresh();
        $this->assertSame(SeoAction::OUTCOME_REGRESSED, $action->outcome);
        $this->assertArrayNotHasKey('serp_position_before', (array) $action->payload);
    }

    public function test_categories_outside_the_serp_confirmed_allowlist_are_never_overridden(): void
    {
        Carbon::setTestNow('2026-09-16 08:00:00');
        $url = 'https://gs.construction/llms.txt';
        $action = $this->makeAppliedAction([
            'fingerprint' => 'fp-llms',
            'category' => 'llms_regen',
            'target_url' => $url,
            'metric' => 'impressions',
            'baseline_value' => 200.0,
        ]);
        $this->seedGsc($url, 5, 50, now()); // 200 -> 50 impressions = -75%

        // Even an unmistakably-improved SERP must not touch a category A5
        // doesn't cover — only title_meta/content_refresh/reindex/create_page do.
        $this->makeSerpSnapshot($url, 2, $action->baseline_at);
        $this->makeSerpSnapshot($url, 1, now());

        app(SeoAutopilotService::class)->measure();

        $action->refresh();
        $this->assertSame(SeoAction::OUTCOME_REGRESSED, $action->outcome);
        $this->assertArrayNotHasKey('serp_position_before', (array) $action->payload);
    }
}
