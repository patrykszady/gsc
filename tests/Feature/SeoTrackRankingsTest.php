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
        ['q' => 'kitchen remodeling Palatine IL', 'location' => 'Palatine,Illinois,United States', 'city_slug' => 'palatine'],
        ['q' => 'bathroom remodeling Schaumburg IL', 'location' => 'Schaumburg,Illinois,United States', 'city_slug' => 'schaumburg'],
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
            ['q' => 'general contractor Barrington IL', 'location' => 'Barrington,Illinois,United States', 'city_slug' => 'barrington'],
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
            ['q' => 'kitchen remodeling Palatine IL', 'location' => 'Palatine,Illinois,United States', 'city_slug' => 'palatine'],
            ['q' => 'bathroom remodeling Schaumburg IL', 'location' => 'Schaumburg,Illinois,United States', 'city_slug' => 'schaumburg'],
            ['q' => 'general contractor Barrington IL', 'location' => 'Barrington,Illinois,United States', 'city_slug' => 'barrington'],
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

        $this->assertSame('Palatine,Illinois,United States', $seenLocations['kitchen remodeling Palatine IL']);
        $this->assertSame('Schaumburg,Illinois,United States', $seenLocations['bathroom remodeling Schaumburg IL']);

        $rows = SeoRankSnapshot::where('engine', 'google')->get()->keyBy('query');
        $this->assertSame('Palatine,Illinois,United States', $rows['kitchen remodeling Palatine IL']->location);
        $this->assertSame('Schaumburg,Illinois,United States', $rows['bathroom remodeling Schaumburg IL']->location);
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
        $this->assertSame('Palatine,Illinois,United States', $idsByKeyword['task-0']['location_name'] ?? null);
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

    public function test_a_location_written_with_spaces_reaches_dataforseo_in_its_own_spelling(): void
    {
        config(['seo.rank_tracker.web_queries' => [
            ['q' => 'kitchen remodeling Palatine IL', 'location' => 'Palatine, Illinois, United States', 'city_slug' => 'palatine'],
        ]]);
        $sent = [];
        Http::fake(array_merge($this->fakeBalance(50), [
            '*/serp/google/organic/task_post' => function ($request) use (&$sent) {
                foreach ($request->data() as $task) {
                    $sent[] = $task['location_name'] ?? null;
                }

                return Http::response(['tasks' => [['id' => 'task-0', 'status_code' => 20100, 'cost' => 0.0006, 'data' => ['keyword' => 'kitchen remodeling Palatine IL']]]]);
            },
            '*/serp/google/organic/tasks_ready' => Http::response(['tasks' => [['result' => [['id' => 'task-0', 'endpoint_advanced' => '/v3/serp/google/organic/task_get/advanced/task-0']]]]]),
            '*/serp/google/organic/task_get/advanced/*' => Http::response(['tasks' => [['status_code' => 20000, 'result' => [['items' => [$this->organic(3, 'https://gs.construction/areas-served/palatine')]]]]]]),
        ]));

        $this->artisan('seo:track-rankings');

        // DataForSEO rejects "Palatine, Illinois, United States" (40501
        // Invalid Field); the comma spelling is corrected at the boundary.
        $this->assertSame(['Palatine,Illinois,United States'], $sent);
    }

    public function test_a_failed_standard_row_keeps_its_task_id_and_is_filled_by_a_later_collect(): void
    {
        Http::fake(array_merge($this->fakeBalance(50), [
            '*/serp/google/organic/task_post' => Http::response(['tasks' => [
                ['id' => 'task-a', 'status_code' => 20100, 'status_message' => 'Task Created.'],
                ['id' => 'task-b', 'status_code' => 20100, 'status_message' => 'Task Created.'],
            ]]),
            // Only the first task answers inside the wait window.
            '*/serp/google/organic/tasks_ready' => Http::response(['tasks' => [['result' => [['id' => 'task-a']]]]]),
            '*/serp/google/organic/task_get/advanced/task-a' => Http::response(['tasks' => [['status_code' => 20000, 'result' => [['items' => [$this->organic(4, 'gs.construction')]]]]]]),
        ]));

        $this->artisan('seo:track-rankings', ['--budget' => 1])->assertExitCode(0);

        $late = SeoRankSnapshot::where('engine', 'google')->where('query', 'bathroom remodeling Schaumburg IL')->firstOrFail();
        $this->assertTrue($late->meta['fetch_failed']);
        $this->assertSame('task-b', $late->meta['dataforseo_task_id']);
        $this->assertNull($late->gsc_position);

        // Still queued at the first collect: nothing changes, the id is kept.
        // (One stub whose answer changes: Http::fake keeps earlier stubs first.)
        $taskB = ['tasks' => [['status_code' => 40602, 'status_message' => 'Task In Queue.']]];
        Http::fake(['*/serp/google/organic/task_get/advanced/task-b' => function () use (&$taskB) {
            return Http::response($taskB);
        }]);
        $this->artisan('seo:track-rankings', ['--collect' => true])
            ->expectsOutputToContain('filled=0  still pending=1')
            ->assertExitCode(0);
        $this->assertSame('task-b', $late->fresh()->meta['dataforseo_task_id']);
        $this->assertTrue($late->fresh()->meta['fetch_failed']);

        // Answered later: the row is filled in place, no second row, no spend.
        $taskB = ['tasks' => [['status_code' => 20000, 'result' => [['keyword' => 'bathroom remodeling Schaumburg IL', 'items' => [$this->organic(7, 'gs.construction')]]]]]];
        $this->artisan('seo:track-rankings', ['--collect' => true])
            ->expectsOutputToContain('filled=1')
            ->assertExitCode(0);

        $late->refresh();
        $this->assertSame(7, $late->gsc_position);
        $this->assertArrayNotHasKey('fetch_failed', $late->meta);
        $this->assertSame('kept task id', $late->meta['collected_late']['how']);
        $this->assertSame('dataforseo_standard_advanced', $late->meta['source']);
        $this->assertSame(2, SeoRankSnapshot::where('engine', 'google')->count());
        $this->assertEmpty(Http::recorded(fn ($r) => str_contains($r->url(), 'task_post') || str_contains($r->url(), 'live/advanced')));
    }

    public function test_collect_matches_a_ready_task_without_a_kept_id_by_keyword_and_posting_time(): void
    {
        // Rows written before ids were kept: the 2026-10-12 Monday run.
        $row = SeoRankSnapshot::create([
            'engine' => 'google', 'query' => 'kitchen remodeling Palatine IL', 'location' => 'Palatine,Illinois,United States',
            'top_results' => [], 'meta' => ['source' => 'dataforseo_standard_advanced', 'fetch_failed' => true, 'error' => 'failed'],
            'fetched_at' => now()->subMinutes(50),
        ]);
        $other = SeoRankSnapshot::create([
            'engine' => 'google', 'query' => 'kitchen remodeling Palatine IL', 'location' => 'Palatine,Illinois,United States',
            'top_results' => [], 'meta' => ['source' => 'dataforseo_standard_advanced', 'fetch_failed' => true, 'error' => 'failed'],
            'fetched_at' => now()->subDays(8),
        ]);

        Http::fake([
            '*/serp/google/organic/tasks_ready' => Http::response(['tasks' => [['result' => [
                ['id' => 'orphan-1', 'date_posted' => now()->subMinutes(54)->format('Y-m-d H:i:s').' +00:00'],
                ['id' => 'stranger', 'date_posted' => now()->subMinutes(54)->format('Y-m-d H:i:s').' +00:00'],
            ]]]]),
            '*/serp/google/organic/task_get/advanced/orphan-1' => Http::response(['tasks' => [['status_code' => 20000, 'result' => [['keyword' => 'kitchen remodeling Palatine IL', 'items' => [$this->organic(5, 'gs.construction')]]]]]]),
            '*/serp/google/organic/task_get/advanced/stranger' => Http::response(['tasks' => [['status_code' => 20000, 'result' => [['keyword' => 'plumber Palatine IL', 'items' => []]]]]]),
        ]);

        $this->artisan('seo:track-rankings', ['--collect' => true])
            ->expectsOutputToContain('filled=1')
            ->assertExitCode(0);

        $row->refresh();
        $this->assertSame(5, $row->gsc_position);
        $this->assertSame('orphan-1', $row->meta['dataforseo_task_id']);
        $this->assertSame('matched by keyword', $row->meta['collected_late']['how']);
        $this->assertArrayNotHasKey('fetch_failed', $row->meta);
        // The week-old row with the same keyword is not the one that task belongs to.
        $this->assertTrue($other->fresh()->meta['fetch_failed']);
    }

    public function test_collect_with_nothing_open_calls_dataforseo_not_at_all(): void
    {
        Http::fake();

        $this->artisan('seo:track-rankings', ['--collect' => true])->assertExitCode(0);

        Http::assertNothingSent();
    }
}
