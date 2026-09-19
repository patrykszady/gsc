<?php

namespace Tests\Feature\Seo;

use App\Support\Seo\SearchAppearance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pins the shape the shared ss-systems admin renders its "How Your Results Look
 * on Google" card from. One view serves every tenant, so these keys are a
 * contract, not an implementation detail.
 */
class SearchAppearanceTest extends TestCase
{
    use RefreshDatabase;

    private function row(string $appearance, string $date, int $clicks, int $impressions, float $position = 8.0): void
    {
        DB::table(SearchAppearance::TABLE)->insert([
            'date' => $date,
            'appearance' => $appearance,
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $impressions > 0 ? round($clicks / $impressions, 5) : 0,
            'position' => $position,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function query(): \Closure
    {
        return fn () => DB::table(SearchAppearance::TABLE);
    }

    public function test_it_reports_unavailable_with_no_rows(): void
    {
        $snapshot = SearchAppearance::snapshot($this->query());

        $this->assertFalse($snapshot['available']);
        $this->assertSame([], $snapshot['rows']);
    }

    public function test_it_totals_across_days_and_sorts_by_impressions(): void
    {
        $this->row('REVIEW_SNIPPET', now()->subDays(2)->toDateString(), 1, 1000);
        $this->row('REVIEW_SNIPPET', now()->subDay()->toDateString(), 2, 1126);
        $this->row('PRODUCT_SNIPPETS', now()->subDay()->toDateString(), 3, 1988);

        $snapshot = SearchAppearance::snapshot($this->query());

        $this->assertTrue($snapshot['available']);
        $this->assertSame(4114, $snapshot['total_impressions']);
        $this->assertSame(6, $snapshot['total_clicks']);

        // Impressions, not clicks: the seen-and-skipped case is the point.
        $this->assertSame('REVIEW_SNIPPET', $snapshot['rows'][0]['appearance']);
        $this->assertSame(2126, $snapshot['rows'][0]['impressions']);
        $this->assertSame(3, $snapshot['rows'][0]['clicks']);
    }

    /**
     * Averaging the daily ctr column would weight a 10-impression day the same
     * as a 10,000-impression one.
     */
    public function test_click_rate_comes_from_the_totals_not_an_average_of_days(): void
    {
        $this->row('REVIEW_SNIPPET', now()->subDays(2)->toDateString(), 1, 10);     // 10% that day
        $this->row('REVIEW_SNIPPET', now()->subDay()->toDateString(), 1, 990);      // 0.1% that day

        $snapshot = SearchAppearance::snapshot($this->query());

        // 2 clicks / 1000 impressions = 0.2%, not the 5.05% a naive average gives.
        $this->assertSame(0.2, $snapshot['rows'][0]['ctr']);
    }

    public function test_average_position_is_weighted_by_impressions(): void
    {
        $this->row('REVIEW_SNIPPET', now()->subDays(2)->toDateString(), 0, 100, 2.0);
        $this->row('REVIEW_SNIPPET', now()->subDay()->toDateString(), 0, 900, 12.0);

        $snapshot = SearchAppearance::snapshot($this->query());

        // (2*100 + 12*900) / 1000 = 11.0 — not the flat mean of 7.0.
        $this->assertSame(11.0, $snapshot['rows'][0]['position']);
    }

    public function test_it_carries_the_prior_window_for_comparison(): void
    {
        $this->row('REVIEW_SNIPPET', now()->subDay()->toDateString(), 3, 2126);
        $this->row('REVIEW_SNIPPET', now()->subDays(30)->toDateString(), 1, 1800);

        $snapshot = SearchAppearance::snapshot($this->query(), days: 28);

        $this->assertSame(2126, $snapshot['rows'][0]['impressions']);
        $this->assertSame(1800, $snapshot['rows'][0]['prior_impressions']);
    }

    public function test_raw_dimension_keys_are_turned_into_words(): void
    {
        $this->assertSame('Star ratings', SearchAppearance::label('REVIEW_SNIPPET'));
        $this->assertSame('AI Overviews', SearchAppearance::label('AI_OVERVIEW'));

        // An appearance Google adds later must still read as words, never as
        // SCREAMING_SNAKE — the admin prints this straight at the owner.
        $this->assertSame('Some new thing', SearchAppearance::label('SOME_NEW_THING'));
    }
}
