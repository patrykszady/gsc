<?php

namespace Tests\Feature;

use App\Models\ClarityPageMetric;
use App\Services\Seo\RecommendationEngine;
use App\Support\Seo\FrustratedPages;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The behaviour data sat on the SEO screen for months as a site-wide tile and
 * the engine never read it. This rule turns a page whose frustration rose —
 * and that search already sends people to — into a "do now" recommendation.
 */
class RecommendationEngineFrustratedPagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const END = '2026-09-18';

    /** Fourteen daily rows for one path: the last 7 at $now, the 7 before at $prior. */
    private function seedPage(string $path, array $prior, array $now): void
    {
        $end = Carbon::parse(self::END);
        for ($i = 0; $i < 14; $i++) {
            $m = $i < 7 ? $now : $prior;
            ClarityPageMetric::create([
                'project_id' => 'proj-test',
                'date' => (clone $end)->subDays($i)->toDateString(),
                'path' => $path,
                'path_hash' => ClarityPageMetric::hashPath($path),
                'sessions' => $m['sessions'],
                'rage_clicks' => $m['rage'] ?? 0,
                'dead_clicks' => $m['dead'] ?? 0,
                'quickbacks' => $m['quick'] ?? 0,
            ]);
        }
    }

    private function seedSearchImpressions(string $page, int $impressions): void
    {
        DB::table('gsc_query_metrics')->insert([
            'site_id' => null,
            'date' => Carbon::parse(self::END)->subDays(3)->toDateString(),
            'site_url' => 'sc-domain:example.test',
            'query' => 'kitchen remodel',
            'page' => $page,
            'impressions' => $impressions,
            'clicks' => 3,
            'ctr' => 0.01,
            'position' => 6.0,
            'dim_hash' => sha1($page.'kitchen remodel'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<int, array{t:string,d:string,p:string,source?:string}> */
    private function recs(): array
    {
        $method = new ReflectionMethod(app(RecommendationEngine::class), 'frustratedPagesRecs');

        return $method->invoke(app(RecommendationEngine::class));
    }

    public function test_a_page_whose_frustration_rose_is_named_with_its_search_traffic(): void
    {
        // 5% → 30% on 40 visits/week: exactly the page worth fixing first.
        $this->seedPage('/kitchens', prior: ['sessions' => 6, 'rage' => 0, 'dead' => 0], now: ['sessions' => 6, 'rage' => 1, 'dead' => 1]);
        $this->seedSearchImpressions('https://gs.construction/kitchens/', 1200);

        $recs = $this->recs();

        $this->assertCount(1, $recs);
        $this->assertSame('now', $recs[0]['p']);
        $this->assertStringContainsString('/kitchens — 14 of 42 visits hit a dead end (was 0%)', $recs[0]['d']);
        $this->assertStringContainsString('1,200 search views this month', $recs[0]['d']);
        // Vendor names stay in the accordion.
        $this->assertStringContainsString('Clarity', $recs[0]['source']);
        $this->assertStringNotContainsString('Clarity', $recs[0]['t'].$recs[0]['d']);
    }

    public function test_a_page_that_was_always_rough_is_not_news(): void
    {
        // 25% last week, 25% this week: high, but not RISING.
        $this->seedPage('/rough', prior: ['sessions' => 6, 'rage' => 1, 'dead' => 1], now: ['sessions' => 6, 'rage' => 1, 'dead' => 1]);

        $this->assertSame([], $this->recs());
    }

    public function test_too_few_visits_cannot_make_a_rate(): void
    {
        // 1 of 2 visits is "50%" and means nothing.
        $this->seedPage('/quiet', prior: ['sessions' => 1], now: ['sessions' => 1, 'rage' => 1]);

        $this->assertSame([], $this->recs());
    }

    public function test_a_page_with_no_prior_week_has_nothing_to_rise_from(): void
    {
        $end = Carbon::parse(self::END);
        for ($i = 0; $i < 7; $i++) {
            ClarityPageMetric::create([
                'project_id' => 'proj-test', 'date' => (clone $end)->subDays($i)->toDateString(),
                'path' => '/new', 'path_hash' => ClarityPageMetric::hashPath('/new'),
                'sessions' => 10, 'rage_clicks' => 4,
            ]);
        }

        $this->assertSame([], $this->recs());
    }

    public function test_the_biggest_page_leads_and_the_list_is_capped(): void
    {
        $this->seedPage('/small', prior: ['sessions' => 4], now: ['sessions' => 4, 'rage' => 1]);
        $this->seedPage('/big', prior: ['sessions' => 30], now: ['sessions' => 30, 'rage' => 6]);
        $this->seedPage('/mid', prior: ['sessions' => 10], now: ['sessions' => 10, 'rage' => 2]);
        $this->seedPage('/also', prior: ['sessions' => 8], now: ['sessions' => 8, 'rage' => 2]);

        $pages = FrustratedPages::rising(7, 3);

        $this->assertSame(['/big', '/mid', '/also'], array_column($pages, 'path'));
        $this->assertSame(210, $pages[0]['sessions']);
    }

    public function test_the_card_and_the_recommendation_see_the_same_pages(): void
    {
        $this->seedPage('/kitchens', prior: ['sessions' => 6], now: ['sessions' => 6, 'rage' => 2]);

        $card = FrustratedPages::rising(7, 5);
        $recs = $this->recs();

        $this->assertSame('/kitchens', $card[0]['path']);
        $this->assertStringContainsString('/kitchens', $recs[0]['d']);
    }

    public function test_it_reaches_the_published_recommendations_as_do_now(): void
    {
        $this->seedPage('/kitchens', prior: ['sessions' => 6], now: ['sessions' => 6, 'rage' => 2]);

        $payload = app(RecommendationEngine::class)->refresh(allowHealing: false);
        $first = $payload['recommendations'][0];

        $this->assertSame('now', $first['p']);
        $this->assertSame('Fix the pages that frustrate the visitors search already sends', $first['t']);
    }
}
