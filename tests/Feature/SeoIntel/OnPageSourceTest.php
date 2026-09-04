<?php

namespace Tests\Feature\SeoIntel;

use App\Services\Seo\Intel\IntelRunner;
use App\Services\Seo\Intel\Sources\OnPageSource;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OnPageSourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://gs.construction',
            'services.dataforseo.login' => 'u',
            'services.dataforseo.password' => 'p',
            'seo-intel.sources' => [OnPageSource::class],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Wires Http::fake for a two-run lifecycle: healthy crawl, then one page goes broken and the score drops. */
    protected function fakeTwoRunCrawl(): void
    {
        $taskCounter = 0;
        $summaryCalls = [];

        $run1Summary = [
            'crawl_progress' => 'finished',
            'crawl_status' => ['pages_crawled' => 50, 'pages_in_queue' => 0, 'max_crawl_pages' => 600],
            'page_metrics' => [
                'onpage_score' => 85.0,
                'duplicate_title' => 1, 'duplicate_description' => 0, 'duplicate_content' => 0,
                'broken_links' => 0, 'broken_resources' => 0, 'non_indexable' => 0, 'redirect_loop' => 0,
                'checks' => [
                    'no_title' => 0, 'no_description' => 1, 'no_h1_tag' => 1, 'is_4xx_code' => 0, 'is_5xx_code' => 0,
                    'low_content_rate' => 1, 'high_loading_time' => 0, 'large_page_size' => 0, 'no_image_alt' => 3,
                ],
            ],
        ];
        $run1Pages = [
            ['url' => 'https://gs.construction/kitchen-remodeling', 'status_code' => 200, 'onpage_score' => 70,
                'meta' => ['title' => 'Kitchen Remodeling', 'description' => '', 'plain_text_word_count' => 120, 'htags' => ['h1' => []]],
                'page_timing' => ['duration_time' => 800], 'checks' => ['no_description' => true, 'low_content_rate' => true, 'no_h1_tag' => true]],
            ['url' => 'https://gs.construction/about', 'status_code' => 200, 'onpage_score' => 95,
                'meta' => ['title' => 'About Us', 'description' => 'About the company', 'plain_text_word_count' => 500, 'htags' => ['h1' => ['About Us']]],
                'page_timing' => ['duration_time' => 300], 'checks' => []],
        ];
        $run1DupTitle = [
            ['accumulator' => 'Bath & Kitchen Remodeling', 'total_count' => 2, 'pages' => [
                ['url' => 'https://gs.construction/kitchen-remodeling'],
                ['url' => 'https://gs.construction/bath-remodeling'],
            ]],
        ];

        $run2Summary = [
            'crawl_progress' => 'finished',
            'crawl_status' => ['pages_crawled' => 40, 'pages_in_queue' => 0, 'max_crawl_pages' => 600],
            'page_metrics' => [
                'onpage_score' => 78.0,
                'duplicate_title' => 0, 'duplicate_description' => 0, 'duplicate_content' => 0,
                'broken_links' => 0, 'broken_resources' => 0, 'non_indexable' => 1, 'redirect_loop' => 0,
                'checks' => [
                    'no_title' => 0, 'no_description' => 0, 'no_h1_tag' => 0, 'is_4xx_code' => 0, 'is_5xx_code' => 1,
                    'low_content_rate' => 0, 'high_loading_time' => 0, 'large_page_size' => 0, 'no_image_alt' => 0,
                ],
            ],
        ];
        $run2Pages = [
            ['url' => 'https://gs.construction/kitchen-remodeling', 'status_code' => 500, 'onpage_score' => 0,
                'meta' => ['title' => 'Kitchen Remodeling', 'description' => '', 'plain_text_word_count' => 0, 'htags' => ['h1' => []]],
                'page_timing' => ['duration_time' => 0], 'checks' => ['is_5xx_code' => true]],
            ['url' => 'https://gs.construction/about', 'status_code' => 200, 'onpage_score' => 95,
                'meta' => ['title' => 'About Us', 'description' => 'About the company', 'plain_text_word_count' => 500, 'htags' => ['h1' => ['About Us']]],
                'page_timing' => ['duration_time' => 300], 'checks' => []],
        ];

        Http::fake(function ($request) use (&$taskCounter, &$summaryCalls, $run1Summary, $run1Pages, $run1DupTitle, $run2Summary, $run2Pages) {
            $url = $request->url();

            if (str_contains($url, 'user_data')) {
                return Http::response(['tasks' => [['result' => [['money' => ['balance' => 20]]]]]]);
            }
            if (str_contains($url, 'on_page/task_post')) {
                $taskCounter++;
                $id = 't-' . $taskCounter;

                return Http::response(['tasks' => [['id' => $id, 'status_code' => 20100, 'status_message' => 'Task Created.', 'cost' => 0.09]]]);
            }
            if (str_contains($url, 'on_page/summary/')) {
                $id = mb_substr($url, strrpos($url, '/') + 1);
                $summaryCalls[$id] = ($summaryCalls[$id] ?? 0) + 1;
                if ($summaryCalls[$id] === 1) {
                    return Http::response(['tasks' => [['cost' => 0, 'status_code' => 20000, 'result' => [['crawl_progress' => 'in_progress']]]]]);
                }

                return Http::response(['tasks' => [['cost' => 0, 'status_code' => 20000, 'result' => [$id === 't-1' ? $run1Summary : $run2Summary]]]]);
            }
            if (str_contains($url, 'on_page/pages')) {
                $id = $request->data()[0]['id'] ?? null;
                $items = $id === 't-1' ? $run1Pages : $run2Pages;

                return Http::response(['tasks' => [['cost' => 0, 'status_code' => 20000, 'result' => [['items' => $items, 'total_items_count' => count($items)]]]]]);
            }
            if (str_contains($url, 'on_page/duplicate_tags')) {
                $body = $request->data()[0] ?? [];
                $items = ($body['id'] === 't-1' && $body['type'] === 'duplicate_title') ? $run1DupTitle : [];

                return Http::response(['tasks' => [['cost' => 0, 'status_code' => 20000, 'result' => [['items' => $items]]]]]);
            }
            if (str_contains($url, 'on_page/redirect_chains')) {
                return Http::response(['tasks' => [['cost' => 0, 'status_code' => 20000, 'result' => [['items' => []]]]]]);
            }

            return Http::response(['tasks' => [['cost' => 0, 'status_code' => 20000, 'result' => []]]]);
        });
    }

    public function test_first_crawl_stores_snapshots_and_opens_page_level_findings(): void
    {
        $this->fakeTwoRunCrawl();
        Carbon::setTestNow('2026-09-05 06:00:00');

        $this->artisan('seo:intel', ['family' => ['onpage'], '--budget' => 1])->assertExitCode(0);

        $this->assertSame(3, DB::table('seo_intel_snapshots')->where('kind', 'page')->count(), 'kitchen, about, and the bath-remodeling dup-title sibling');
        $summary = DB::table('seo_intel_snapshots')->where('kind', 'summary')->first();
        $metrics = json_decode((string) $summary->metrics, true);
        $this->assertEquals(85.0, $metrics['onpage_score']);
        $this->assertSame(50, $metrics['pages_crawled']);
        $this->assertSame(2, $metrics['pages_with_issues']);

        $codes = DB::table('seo_intel_findings')->pluck('severity', 'code');
        $this->assertSame('warn', $codes['onpage.duplicate_title']);
        $this->assertSame('warn', $codes['onpage.no_description']);
        $this->assertSame('warn', $codes['onpage.thin_content']);
        $this->assertSame('info', $codes['onpage.no_h1']);
        $this->assertSame(5, DB::table('seo_intel_findings')->count());
        $this->assertSame(0, DB::table('seo_intel_findings')->whereNotNull('resolved_at')->count());

        $dupSubjects = DB::table('seo_intel_findings')->where('code', 'onpage.duplicate_title')->pluck('subject')->sort()->values()->all();
        $this->assertSame(['/bath-remodeling', '/kitchen-remodeling'], $dupSubjects);

        $action = json_decode((string) DB::table('seo_intel_findings')->where('code', 'onpage.thin_content')->value('action'), true);
        $this->assertSame(['type' => 'content_refresh', 'path' => '/kitchen-remodeling'], $action);
    }

    public function test_second_crawl_opens_broken_and_site_level_findings_and_resolves_the_first_runs(): void
    {
        $this->fakeTwoRunCrawl();
        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->artisan('seo:intel', ['family' => ['onpage'], '--budget' => 1])->assertExitCode(0);

        Carbon::setTestNow('2026-09-12 06:00:00');
        $this->artisan('seo:intel', ['family' => ['onpage'], '--budget' => 1])->assertExitCode(0);

        $summary = DB::table('seo_intel_snapshots')->where('kind', 'summary')->orderByDesc('taken_on')->first();
        $metrics = json_decode((string) $summary->metrics, true);
        $this->assertEquals(78.0, $metrics['onpage_score']);
        $this->assertSame(40, $metrics['pages_crawled']);
        $this->assertSame(1, $metrics['is_5xx']);

        // Only kitchen-remodeling (now broken) and about remain this run — the
        // dup-title sibling snapshot from run 1 is gone because the pair resolved.
        $this->assertSame(2, DB::table('seo_intel_snapshots')->where('kind', 'page')->where('taken_on', '2026-09-12')->count());

        $open = DB::table('seo_intel_findings')->whereNull('resolved_at')->get()->keyBy('code');
        $this->assertCount(4, $open);
        $this->assertSame('critical', $open['onpage.broken']->severity);
        $this->assertSame('/kitchen-remodeling', $open['onpage.broken']->subject);
        $this->assertSame(['status_code' => ['prev' => null, 'now' => 500]], json_decode((string) $open['onpage.broken']->delta, true));

        $this->assertSame('warn', $open['onpage.score_drop']->severity);
        $this->assertEquals(['onpage_score' => ['prev' => 85.0, 'now' => 78.0]], json_decode((string) $open['onpage.score_drop']->delta, true));

        $this->assertSame('warn', $open['onpage.crawl_shrunk']->severity);
        $this->assertSame('critical', $open['onpage.broken_pages_increase']->severity);
        $this->assertSame(['broken_pages' => ['prev' => 0, 'now' => 1]], json_decode((string) $open['onpage.broken_pages_increase']->delta, true));

        // The five findings opened by run 1 (duplicate title x2, no_description,
        // thin_content, no_h1) are all gone from the broken/clean run-2 page set.
        $this->assertSame(5, DB::table('seo_intel_findings')->whereNotNull('resolved_at')->count());
        $this->assertSame(9, DB::table('seo_intel_findings')->count());
    }

    public function test_report_shape_before_and_after_a_run(): void
    {
        $source = app(OnPageSource::class);
        $empty = $source->report();
        $this->assertSame([], $empty['tiles']);
        $this->assertSame([], $empty['tables']);
        $this->assertNotEmpty($empty['note']);

        $this->fakeTwoRunCrawl();
        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->artisan('seo:intel', ['family' => ['onpage'], '--budget' => 1])->assertExitCode(0);

        $report = app(OnPageSource::class)->report();
        $tiles = collect($report['tiles'])->keyBy('label');
        $this->assertEquals(85.0, $tiles['On-page score']['value']);
        $this->assertSame(50, $tiles['Pages crawled']['value']);
        $this->assertSame(2, $tiles['Pages with issues']['value']);
        $this->assertSame(0, $tiles['Broken pages']['value']);

        $table = $report['tables'][0];
        $this->assertSame(['Page', 'Score', 'Issues'], $table['columns']);
        $this->assertSame('/kitchen-remodeling', $table['rows'][0][0], 'the worst-scoring page sorts first');
        $this->assertStringContainsString('2026-09-05', $report['note']);
    }

    public function test_cost_cap_clamps_crawl_size_and_stops_the_free_followup_calls_once_over_budget(): void
    {
        config(['seo-intel.families.onpage' => ['max_pages' => 100000, 'max_cost' => 0.0005]]);
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'user_data')) {
                return Http::response(['tasks' => [['result' => [['money' => ['balance' => 20]]]]]]);
            }
            if (str_contains($url, 'on_page/task_post')) {
                // A single crawl this expensive already blows the family's cost cap.
                return Http::response(['tasks' => [['id' => 't-1', 'status_code' => 20100, 'status_message' => 'Task Created.', 'cost' => 5.0]]]);
            }
            if (str_contains($url, 'on_page/summary/')) {
                return Http::response(['tasks' => [['cost' => 0, 'status_code' => 20000, 'result' => [[
                    'crawl_progress' => 'finished',
                    'crawl_status' => ['pages_crawled' => 10, 'pages_in_queue' => 0, 'max_crawl_pages' => 10],
                    'page_metrics' => ['onpage_score' => 90.0, 'duplicate_title' => 0, 'duplicate_description' => 0, 'duplicate_content' => 0, 'broken_links' => 0, 'broken_resources' => 0, 'non_indexable' => 0, 'redirect_loop' => 0, 'checks' => []],
                ]]]]]);
            }

            return Http::response(['tasks' => [['cost' => 0, 'status_code' => 20000, 'result' => [['items' => []]]]]]);
        });

        $runner = app(IntelRunner::class);
        $source = $runner->sources(['onpage'])['onpage'];
        $result = $runner->run($source);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['snapshots'], 'only the summary — no follow-up detail once over budget');

        $post = Http::recorded(fn ($r) => str_contains($r->url(), 'on_page/task_post'))->first()[0];
        $this->assertSame(10, $post->data()[0]['max_crawl_pages'], 'clamped from 100000 to what $0.0005 buys at $0.00015/page');

        $this->assertSame(0, Http::recorded(fn ($r) => str_contains($r->url(), 'on_page/pages'))->count());
        $this->assertSame(0, Http::recorded(fn ($r) => str_contains($r->url(), 'on_page/duplicate_tags'))->count());
        $this->assertSame(0, Http::recorded(fn ($r) => str_contains($r->url(), 'on_page/redirect_chains'))->count());
    }
}
