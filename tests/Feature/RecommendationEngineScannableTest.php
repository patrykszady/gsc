<?php

namespace Tests\Feature;

use App\Services\Seo\RecommendationEngine;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The published list holds eight recommendations. It used to sort "do now"
 * first and slice, which meant that on any day with eight "do now" items —
 * production, 2026-09-19 — every "next" recommendation was computed, stored
 * and never shown. Two slots are now reserved for "next" whenever it has two
 * to fill; either side backfills what the other cannot use.
 */
class RecommendationEngineScannableTest extends TestCase
{
    /** @return array<int, array{t:string,d:string,p:string}> */
    private function items(string $priority, int $count, string $prefix): array
    {
        return array_map(fn (int $i) => ['t' => "{$prefix} {$i}", 'd' => '', 'p' => $priority], range(1, $count));
    }

    /**
     * @param  array<int, array{t:string,d:string,p:string}>  $recs
     * @return array<int, string>
     */
    private function titles(array $recs): array
    {
        $method = new ReflectionMethod(app(RecommendationEngine::class), 'scannable');

        return array_column($method->invoke(app(RecommendationEngine::class), $recs), 't');
    }

    public function test_eight_do_now_items_no_longer_bury_every_next(): void
    {
        // The production shape that exposed this: 10 now, 3 next → 6 + 2.
        $titles = $this->titles(array_merge($this->items('now', 10, 'now'), $this->items('next', 3, 'next')));

        $this->assertCount(8, $titles);
        $this->assertSame(['now 1', 'now 2', 'now 3', 'now 4', 'now 5', 'now 6', 'next 1', 'next 2'], $titles);
    }

    public function test_do_now_takes_everything_when_there_is_nothing_next(): void
    {
        $titles = $this->titles($this->items('now', 10, 'now'));

        $this->assertSame(['now 1', 'now 2', 'now 3', 'now 4', 'now 5', 'now 6', 'now 7', 'now 8'], $titles);
    }

    public function test_next_backfills_when_do_now_is_short(): void
    {
        $titles = $this->titles(array_merge($this->items('now', 3, 'now'), $this->items('next', 10, 'next')));

        $this->assertSame(['now 1', 'now 2', 'now 3', 'next 1', 'next 2', 'next 3', 'next 4', 'next 5'], $titles);
    }

    public function test_a_single_next_item_reserves_only_one_slot(): void
    {
        $titles = $this->titles(array_merge($this->items('now', 10, 'now'), $this->items('next', 1, 'next')));

        $this->assertCount(8, $titles);
        $this->assertSame('next 1', end($titles));
        $this->assertSame(7, count(array_filter($titles, fn ($t) => str_starts_with($t, 'now'))));
    }

    public function test_a_short_list_is_kept_whole_and_still_do_now_first(): void
    {
        // Interleaved input: "next" arrived from an earlier rule than "now".
        $titles = $this->titles([
            ['t' => 'next 1', 'd' => '', 'p' => 'next'],
            ['t' => 'now 1', 'd' => '', 'p' => 'now'],
            ['t' => 'next 2', 'd' => '', 'p' => 'next'],
        ]);

        $this->assertSame(['now 1', 'next 1', 'next 2'], $titles);
    }

    public function test_rule_order_survives_within_each_side(): void
    {
        // Within a priority, the earlier rule still leads — same ranking as
        // the sort-and-slice it replaces.
        $titles = $this->titles(array_merge($this->items('now', 9, 'now'), $this->items('next', 2, 'next')));

        $this->assertSame('now 1', $titles[0]);
        $this->assertSame('now 6', $titles[5]);
        $this->assertSame(['next 1', 'next 2'], array_slice($titles, 6));
    }
}
