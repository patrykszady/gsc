<?php

namespace Tests\Feature;

use App\Models\BingDailyTotal;
use App\Models\GscDailyTotal;
use App\Services\Seo\RecommendationEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Bing and Google have been synced side by side for months and the engine
 * only ever checked that the syncs stayed fresh; nothing compared what came
 * through them. This rule does, because Bing is what the AI assistants draw
 * on, so Bing moving away from Google is an early signal in either direction.
 *
 * The thresholds are pinned against production's real shape: Bing is a few
 * hundred impressions a month next to Google's tens of thousands, and both
 * were flat (−1% / −2%) the day this was written. That must NOT fire.
 */
class RecommendationEngineDivergenceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const END = '2026-09-16';

    /** Fill 56 days: the current 28 at $now/day and the 28 before at $prior/day. */
    private function seedChannel(string $table, int $priorPerDay, int $nowPerDay): void
    {
        $model = $table === 'bing' ? BingDailyTotal::class : GscDailyTotal::class;
        $end = Carbon::parse(self::END);

        for ($i = 0; $i < 56; $i++) {
            $date = (clone $end)->subDays($i);
            $model::create([
                'date' => $date->toDateString(),
                'site_url' => 'sc-domain:example.test',
                'impressions' => $i < 28 ? $nowPerDay : $priorPerDay,
                'clicks' => 0,
                'ctr' => 0,
            ] + ($table === 'gsc' ? ['position' => 8.0] : []));
        }
    }

    /** @return array<int, array{t:string,d:string,p:string,source?:string}> */
    private function recs(): array
    {
        $method = new ReflectionMethod(app(RecommendationEngine::class), 'searchEngineDivergenceRecs');

        return $method->invoke(app(RecommendationEngine::class));
    }

    public function test_flat_channels_produce_nothing(): void
    {
        // Production on 2026-09-19: Bing 574 → 567, Google 55,491 → 54,229.
        $this->seedChannel('bing', 20, 20);
        $this->seedChannel('gsc', 2000, 1950);

        $this->assertSame([], $this->recs());
    }

    public function test_bing_rising_while_google_holds_is_the_ai_signal(): void
    {
        $this->seedChannel('bing', 20, 34);   // +70%
        $this->seedChannel('gsc', 2000, 2000); // flat

        $recs = $this->recs();

        $this->assertCount(1, $recs);
        $this->assertSame('next', $recs[0]['p']);
        $this->assertStringContainsString('AI assistants', $recs[0]['t']);
        $this->assertStringContainsString('+70%', $recs[0]['d']);
        $this->assertStringContainsString('+0%', $recs[0]['d']);
        // Vendor pipeline names live in the accordion, not the copy.
        $this->assertStringContainsString('Search Console', $recs[0]['source']);
        $this->assertStringNotContainsString('Search Console', $recs[0]['d']);
    }

    public function test_bing_falling_while_google_holds_is_ground_lost(): void
    {
        $this->seedChannel('bing', 20, 9);     // −55%
        $this->seedChannel('gsc', 2000, 2040); // +2%

        $recs = $this->recs();

        $this->assertCount(1, $recs);
        $this->assertStringContainsString('showing you less', $recs[0]['t']);
        $this->assertStringContainsString('−55%', $recs[0]['d']);
        $this->assertStringContainsString('Webmaster Tools', $recs[0]['d']);
    }

    public function test_a_google_only_move_is_not_this_rules_business(): void
    {
        // clickDropActionItems() owns a Google drop; saying it twice is noise.
        $this->seedChannel('bing', 20, 21);    // flat
        $this->seedChannel('gsc', 2000, 1100); // −45%

        $this->assertSame([], $this->recs());
    }

    public function test_a_handful_of_bing_impressions_cannot_trip_it(): void
    {
        // 2/day → 4/day is "+100%" and means nothing.
        $this->seedChannel('bing', 2, 4);
        $this->seedChannel('gsc', 2000, 2000);

        $this->assertSame([], $this->recs());
    }

    public function test_both_moving_together_is_not_divergence(): void
    {
        $this->seedChannel('bing', 20, 30);    // +50%
        $this->seedChannel('gsc', 2000, 3000); // +50%

        $this->assertSame([], $this->recs());
    }

    public function test_it_reaches_the_published_recommendations(): void
    {
        $this->seedChannel('bing', 20, 34);
        $this->seedChannel('gsc', 2000, 2000);

        $payload = app(RecommendationEngine::class)->refresh(allowHealing: false);
        $titles = array_column($payload['recommendations'], 't');

        $this->assertContains('Bing is showing you more often — an early sign from the AI assistants', $titles);
    }
}
