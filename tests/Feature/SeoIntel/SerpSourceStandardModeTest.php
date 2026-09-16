<?php

namespace Tests\Feature\SeoIntel;

use App\Services\Seo\Intel\Sources\SerpSource;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A4: SerpSource's default serp_mode is 'standard' — one task_post for every
 * tracked query in a single batch, then tasks_ready/task_get, at
 * ~$0.0006/check instead of Live Advanced's ~$0.002. The main SerpSourceTest
 * suite pins serp_mode=live (it exercises collect()'s query-building,
 * findings and report logic against the well-understood Live loop); this
 * suite covers the Standard-queue path itself, and its automatic fallback.
 */
class SerpSourceStandardModeTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://gs.construction',
            'services.dataforseo.login' => 'u',
            'services.dataforseo.password' => 'p',
            'seo-intel.sources' => [SerpSource::class],
            'seo-intel.families.serp.queries' => [
                'kitchen remodeling Arlington Heights IL',
                'bathroom remodeling Buffalo Grove IL',
            ],
            'seo-intel.families.serp.depth' => 20,
        ]);
    }

    protected function organic(int $rank, string $domain): array
    {
        return ['type' => 'organic', 'rank_absolute' => $rank, 'rank_group' => $rank, 'domain' => $domain, 'url' => "https://{$domain}/page"];
    }

    public function test_default_mode_is_standard_and_never_calls_live(): void
    {
        $itemsByKeyword = [
            'kitchen remodeling Arlington Heights IL' => [$this->organic(1, 'competitor.com'), $this->organic(4, 'gs.construction')],
            'bathroom remodeling Buffalo Grove IL' => [$this->organic(2, 'gs.construction')],
        ];
        $idsByKeyword = [];

        Http::fake(function ($request) use ($itemsByKeyword, &$idsByKeyword) {
            $url = $request->url();

            if (str_contains($url, 'user_data')) {
                return Http::response(['tasks' => [['result' => [['money' => ['balance' => 50]]]]]]);
            }

            if (str_contains($url, '/serp/google/organic/task_post')) {
                $tasks = (array) json_decode($request->body(), true);
                $out = [];
                foreach ($tasks as $i => $task) {
                    $id = 'task-'.$i;
                    $idsByKeyword[$id] = $task['keyword'];
                    $out[] = ['id' => $id, 'status_code' => 20000, 'status_message' => 'Ok.'];
                }

                return Http::response(['tasks' => $out]);
            }

            if (str_contains($url, '/serp/google/organic/tasks_ready')) {
                return Http::response(['tasks' => [['result' => array_map(fn ($id) => ['id' => $id], array_keys($idsByKeyword))]]]);
            }

            if (str_contains($url, '/serp/google/organic/task_get/advanced/')) {
                $id = last(explode('/', rtrim($url, '/')));
                $keyword = $idsByKeyword[$id] ?? '';

                return Http::response(['tasks' => [['status_code' => 20000, 'result' => [['keyword' => $keyword, 'items' => $itemsByKeyword[$keyword] ?? []]]]]]);
            }

            return Http::response(['tasks' => []], 500);
        });

        $this->assertSame('standard', config('seo.rank_tracker.serp_mode'), 'the config default this test relies on');

        $this->artisan('seo:intel', ['family' => ['serp'], '--budget' => 1])->assertExitCode(0);

        $this->assertSame(2, DB::table('seo_intel_snapshots')->where('family', 'serp')->count());
        $this->assertNotEmpty(Http::recorded(fn ($r) => str_contains($r->url(), '/serp/google/organic/task_post')));
        $this->assertEmpty(
            Http::recorded(fn ($r) => str_contains($r->url(), '/serp/google/organic/live/advanced')),
            'the Live endpoint must not be called when the Standard queue succeeds'
        );

        $snap = DB::table('seo_intel_snapshots')->where('family', 'serp')->where('subject', 'bathroom remodeling Buffalo Grove IL')->first();
        $this->assertSame(2, json_decode((string) $snap->metrics, true)['position']);
    }

    /**
     * pricePerQuery() must track collect()'s own endpoint choice: the
     * Standard queue is a flat ~$0.0006/check regardless of depth, so
     * estimateCost() (the outer seo:intel budget gate) matches what a
     * standard-mode run actually spends.
     */
    public function test_estimate_cost_uses_the_flat_standard_rate(): void
    {
        $this->assertSame('standard', config('seo.rank_tracker.serp_mode'));

        // 2 tracked queries (see setUp) × $0.0006, independent of depth=20.
        $this->assertSame(0.0012, app(SerpSource::class)->estimateCost());
    }

    public function test_falls_back_to_live_when_the_queue_fails_to_post(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'user_data')) {
                return Http::response(['tasks' => [['result' => [['money' => ['balance' => 50]]]]]]);
            }
            if (str_contains($url, '/serp/google/organic/task_post')) {
                // Every task rejected — task_post itself failed to queue anything.
                return Http::response(['tasks' => [['status_code' => 40501, 'status_message' => 'Invalid Field.']]]);
            }
            if (str_contains($url, '/serp/google/organic/live/advanced')) {
                $keyword = json_decode($request->body(), true)[0]['keyword'] ?? '';

                return Http::response(['tasks' => [['cost' => 0.004, 'status_code' => 20000, 'result' => [['keyword' => $keyword, 'items' => [$this->organic(5, 'gs.construction')]]]]]]);
            }

            return Http::response(['tasks' => []], 500);
        });

        $this->artisan('seo:intel', ['family' => ['serp'], '--budget' => 1])->assertExitCode(0);

        $this->assertSame(2, DB::table('seo_intel_snapshots')->where('family', 'serp')->count());
        $this->assertCount(
            2,
            Http::recorded(fn ($r) => str_contains($r->url(), '/serp/google/organic/live/advanced')),
            'both queries fall back to the Live loop when the queue never posts'
        );
    }
}
