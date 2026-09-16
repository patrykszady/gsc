<?php

namespace Tests\Feature;

use App\Models\SeoRankSnapshot;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * seo:track-rankings' DataForSEO pass (A1-A4): a budget guard where there
 * was none, fetch failures persisted instead of silently dropped, the
 * DataForSEO engine checked from each query's own suburb instead of a
 * hardcoded Chicago vantage, and a cheaper Standard-queue path with an
 * automatic fallback to Live.
 */
class SeoTrackRankingsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const QUERIES = [
        ['q' => 'kitchen remodeling Palatine IL', 'location' => 'Palatine, Illinois, United States', 'city_slug' => 'palatine'],
        ['q' => 'bathroom remodeling Schaumburg IL', 'location' => 'Schaumburg, Illinois, United States', 'city_slug' => 'schaumburg'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.dataforseo.login' => 'u',
            'services.dataforseo.password' => 'p',
            'seo.rank_tracker.web_queries' => self::QUERIES,
        ]);
    }

    private function organic(int $rank, string $domain): array
    {
        return ['type' => 'organic', 'rank_absolute' => $rank, 'rank_group' => $rank, 'domain' => $domain, 'url' => "https://{$domain}/page"];
    }

    private function fakeBalance(?float $balance): array
    {
        return $balance === null
            ? ['*/appendix/user_data' => Http::response([], 500)]
            : ['*/appendix/user_data' => Http::response(['tasks' => [['result' => [['money' => ['balance' => $balance]]]]]])];
    }

    public function test_fails_closed_when_the_balance_check_fails(): void
    {
        config(['seo.rank_tracker.serp_mode' => 'live']);
        Http::fake($this->fakeBalance(null));

        $this->artisan('seo:track-rankings')->assertExitCode(1);

        $this->assertSame(0, SeoRankSnapshot::where('engine', 'google')->count());
    }

    public function test_fails_when_the_estimate_exceeds_the_budget(): void
    {
        Http::fake($this->fakeBalance(50));

        $this->artisan('seo:track-rankings', ['--budget' => 0.0001])->assertExitCode(1);

        $this->assertSame(0, SeoRankSnapshot::where('engine', 'google')->count());
        $this->assertEmpty(Http::recorded(fn ($r) => str_contains($r->url(), 'task_post') || str_contains($r->url(), 'live/advanced')));
    }

    public function test_mid_loop_spend_stops_further_live_queries_within_budget(): void
    {
        config(['seo.rank_tracker.serp_mode' => 'live', 'seo.rank_tracker.web_queries' => array_merge(self::QUERIES, [
            ['q' => 'general contractor Barrington IL', 'location' => 'Barrington, Illinois, United States', 'city_slug' => 'barrington'],
        ])]);
        Http::fake(array_merge($this->fakeBalance(50), [
            '*/serp/google/organic/live/advanced' => Http::response(['tasks' => [['cost' => 0.05, 'status_code' => 20000, 'result' => [['items' => [$this->organic(5, 'gs.construction')]]]]]]),
        ]));

        // Estimate ($0.002 x 3 = $0.006) clears the $0.06 budget, but each
        // call's REAL cost ($0.05) does not — the second call already spends
        // $0.10, so the third query must never fire.
        $this->artisan('seo:track-rankings', ['--budget' => 0.06])
            ->expectsOutputToContain('Budget reached mid-run')
            ->assertExitCode(0);

        $this->assertSame(2, SeoRankSnapshot::where('engine', 'google')->count());
        $this->assertCount(2, Http::recorded(fn ($r) => str_contains($r->url(), 'live/advanced')));
    }

    public function test_fetch_failures_are_persisted_distinct_from_not_ranking_and_run_still_succeeds(): void
    {
        config(['seo.rank_tracker.serp_mode' => 'live', 'seo.rank_tracker.web_queries' => [
            ['q' => 'kitchen remodeling Palatine IL', 'location' => 'Palatine, Illinois, United States', 'city_slug' => 'palatine'],
            ['q' => 'bathroom remodeling Schaumburg IL', 'location' => 'Schaumburg, Illinois, United States', 'city_slug' => 'schaumburg'],
            ['q' => 'general contractor Barrington IL', 'location' => 'Barrington, Illinois, United States', 'city_slug' => 'barrington'],
        ]]);
        Http::fake(array_merge($this->fakeBalance(50), [
            '*/serp/google/organic/live/advanced' => function ($request) {
                $keyword = json_decode($request->body(), true)[0]['keyword'] ?? '';

                return match ($keyword) {
                    'kitchen remodeling Palatine IL' => Http::response(['tasks' => [['cost' => 0.002, 'status_code' => 20000, 'result' => [['items' => [$this->organic(5, 'gs.construction')]]]]]]),
                    'bathroom remodeling Schaumburg IL' => Http::response(['tasks' => [['cost' => 0.002, 'status_code' => 20000, 'result' => [['items' => []]]]]]), // answered: simply not ranking
                    default => Http::response(['tasks' => [['cost' => 0, 'status_code' => 40501, 'status_message' => 'Invalid Field.']]]),
                };
            },
        ]));

        $this->artisan('seo:track-rankings', ['--budget' => 1])->assertExitCode(0);

        $rows = SeoRankSnapshot::where('engine', 'google')->get()->keyBy('query');
        $this->assertCount(3, $rows);

        $ranked = $rows['kitchen remodeling Palatine IL'];
        $this->assertSame(5, $ranked->gsc_position);
        $this->assertArrayNotHasKey('fetch_failed', $ranked->meta);

        $notRanking = $rows['bathroom remodeling Schaumburg IL'];
        $this->assertNull($notRanking->gsc_position);
        $this->assertArrayNotHasKey('fetch_failed', $notRanking->meta, 'answered-but-unranked must not be flagged as a fetch failure');

        $failed = $rows['general contractor Barrington IL'];
        $this->assertNull($failed->gsc_position);
        $this->assertTrue($failed->meta['fetch_failed']);
        $this->assertStringContainsString('40501', $failed->meta['error']);
    }

    public function test_exits_nonzero_when_every_dataforseo_query_fails(): void
    {
        config(['seo.rank_tracker.serp_mode' => 'live']);
        Http::fake(array_merge($this->fakeBalance(50), [
            '*/serp/google/organic/live/advanced' => Http::response(['tasks' => [['cost' => 0, 'status_code' => 40501, 'status_message' => 'Invalid Field.']]]),
        ]));

        $this->artisan('seo:track-rankings', ['--budget' => 1])->assertExitCode(1);

        $rows = SeoRankSnapshot::where('engine', 'google')->get();
        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn ($r) => $r->meta['fetch_failed'] === true));
    }

    public function test_each_query_checks_the_dataforseo_engine_from_its_own_suburb(): void
    {
        config(['seo.rank_tracker.serp_mode' => 'live']);
        $seenLocations = [];
        Http::fake(array_merge($this->fakeBalance(50), [
            '*/serp/google/organic/live/advanced' => function ($request) use (&$seenLocations) {
                $body = json_decode($request->body(), true)[0];
                $seenLocations[$body['keyword']] = $body['location_name'] ?? null;

                return Http::response(['tasks' => [['cost' => 0.002, 'status_code' => 20000, 'result' => [['items' => [$this->organic(3, 'gs.construction')]]]]]]);
            },
        ]));

        $this->artisan('seo:track-rankings', ['--budget' => 1])->assertExitCode(0);

        $this->assertSame('Palatine, Illinois, United States', $seenLocations['kitchen remodeling Palatine IL']);
        $this->assertSame('Schaumburg, Illinois, United States', $seenLocations['bathroom remodeling Schaumburg IL']);

        $rows = SeoRankSnapshot::where('engine', 'google')->get()->keyBy('query');
        $this->assertSame('Palatine, Illinois, United States', $rows['kitchen remodeling Palatine IL']->location);
        $this->assertSame('Schaumburg, Illinois, United States', $rows['bathroom remodeling Schaumburg IL']->location);
    }

    public function test_default_serp_mode_uses_the_standard_queue_and_skips_live(): void
    {
        $this->assertSame('standard', config('seo.rank_tracker.serp_mode'), 'the config default this test relies on');
        $idsByKeyword = [];
        Http::fake(array_merge($this->fakeBalance(50), [
            '*/serp/google/organic/task_post' => function ($request) use (&$idsByKeyword) {
                $tasks = (array) json_decode($request->body(), true);
                $out = [];
                foreach ($tasks as $i => $task) {
                    $id = 'task-'.$i;
                    $idsByKeyword[$id] = $task;
                    $out[] = ['id' => $id, 'status_code' => 20000, 'status_message' => 'Ok.'];
                }

                return Http::response(['tasks' => $out]);
            },
            '*/serp/google/organic/tasks_ready' => function () use (&$idsByKeyword) {
                return Http::response(['tasks' => [['result' => array_map(fn ($id) => ['id' => $id], array_keys($idsByKeyword))]]]);
            },
            '*/serp/google/organic/task_get/advanced/*' => function ($request) use (&$idsByKeyword) {
                $id = last(explode('/', rtrim($request->url(), '/')));
                $task = $idsByKeyword[$id] ?? [];
                $items = ($task['keyword'] ?? '') === 'kitchen remodeling Palatine IL' ? [$this->organic(2, 'gs.construction')] : [];

                return Http::response(['tasks' => [['status_code' => 20000, 'result' => [['items' => $items]]]]]);
            },
        ]));

        $this->artisan('seo:track-rankings', ['--budget' => 1])->assertExitCode(0);

        $this->assertEmpty(Http::recorded(fn ($r) => str_contains($r->url(), 'live/advanced')));
        $rows = SeoRankSnapshot::where('engine', 'google')->get()->keyBy('query');
        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows['kitchen remodeling Palatine IL']->gsc_position);
        $this->assertSame('dataforseo_standard_advanced', $rows['kitchen remodeling Palatine IL']->meta['source']);
        $this->assertSame('Palatine, Illinois, United States', $idsByKeyword['task-0']['location_name'] ?? null);
    }

    public function test_falls_back_to_live_when_the_standard_queue_fails_to_post(): void
    {
        Http::fake(array_merge($this->fakeBalance(50), [
            '*/serp/google/organic/task_post' => Http::response(['tasks' => [['status_code' => 40501, 'status_message' => 'Invalid Field.']]]),
            '*/serp/google/organic/live/advanced' => Http::response(['tasks' => [['cost' => 0.002, 'status_code' => 20000, 'result' => [['items' => [$this->organic(6, 'gs.construction')]]]]]]),
        ]));

        $this->artisan('seo:track-rankings', ['--budget' => 1])
            ->expectsOutputToContain('falling back to Live')
            ->assertExitCode(0);

        $this->assertCount(2, Http::recorded(fn ($r) => str_contains($r->url(), 'live/advanced')));
        $rows = SeoRankSnapshot::where('engine', 'google')->get();
        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn ($r) => $r->meta['source'] === 'dataforseo_live_advanced'));
    }
}
