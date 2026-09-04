<?php

namespace Tests\Feature\SeoIntel;

use App\Services\Seo\Intel\Sources\ContentAnalysisSource;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContentAnalysisSourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://gs.construction',
            'services.dataforseo.login' => 'u',
            'services.dataforseo.password' => 'p',
            'seo-intel.sources' => [ContentAnalysisSource::class],
            'seo-intel.families.content_analysis' => [
                'brand_terms' => ['GS Construction'],
                'competitors' => 1,
                'mention_limit' => 10,
                'trend_phrases' => ['kitchen remodeling'],
                'max_findings' => 20,
                'max_cost' => 0.3,
            ],
        ]);

        DB::table('map_pack_competitors')->insert([
            'place_id' => 'p-acme', 'keyword' => 'kitchen remodeling', 'name' => 'Acme Remodeling',
            'pack_points' => 12, 'seen_points' => 12, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected string $fixtureDay = 'day1';

    protected bool $httpFaked = false;

    /**
     * Switches the fixture day the (single, lazily-registered) Http::fake
     * responder reads. Http::fake(Closure) appends to a stub list matched in
     * registration order — a second call would register a second closure
     * that never wins because the first already matches every request — so
     * this registers once and flips a property instead of re-faking.
     */
    protected function fake(string $day): void
    {
        $this->fixtureDay = $day;
        if ($this->httpFaked) {
            return;
        }
        $this->httpFaked = true;
        Http::fake(function ($request) {
            $day = $this->fixtureDay;
            $url = $request->url();
            if (str_contains($url, 'user_data')) {
                return Http::response(['tasks' => [['result' => [['money' => ['balance' => 500]]]]]]);
            }

            $body = (array) $request->data();
            $task = (array) ($body[0] ?? []);
            $keyword = trim((string) ($task['keyword'] ?? ''), '"'); // sent as an exact phrase

            if (str_contains($url, 'content_analysis/summary/live')) {
                return Http::response(['tasks' => [['cost' => 0.02, 'status_code' => 20000, 'result' => [$this->summaryFor($keyword, $day)]]]]);
            }

            if (str_contains($url, 'content_analysis/search/live')) {
                $items = $this->mentionsFor($keyword, $day);

                return Http::response(['tasks' => [['cost' => 0.02, 'status_code' => 20000, 'result' => [
                    ['total_count' => count($items), 'items_count' => count($items), 'items' => $items],
                ]]]]);
            }

            if (str_contains($url, 'content_analysis/phrase_trends/live')) {
                return Http::response(['tasks' => [['cost' => 0.02, 'status_code' => 20000, 'result' => $this->trendSeries($day)]]]);
            }

            return Http::response(['tasks' => [['cost' => 0, 'status_code' => 20000, 'result' => []]]]);
        });
    }

    protected function summaryFor(string $keyword, string $day): array
    {
        $isCompetitor = $keyword === 'Acme Remodeling';
        if ($day === 'day1') {
            $counts = $isCompetitor ? ['total' => 20, 'pos' => 15, 'neg' => 1, 'neu' => 4] : ['total' => 40, 'pos' => 30, 'neg' => 2, 'neu' => 8];
        } else {
            // Us: +5 (win threshold), negative grows 2->5 (warn). Competitor: +15, which
            // outpaces our +5 by >=10 (info).
            $counts = $isCompetitor ? ['total' => 35, 'pos' => 25, 'neg' => 2, 'neu' => 8] : ['total' => 45, 'pos' => 30, 'neg' => 5, 'neu' => 10];
        }

        return [
            'type' => 'content_analysis_summary', 'total_count' => $counts['total'], 'rank' => 500,
            'connotation_types' => ['positive' => $counts['pos'], 'negative' => $counts['neg'], 'neutral' => $counts['neu']],
            'top_domains' => [['domain' => 'houzz.com', 'count' => 3]],
            'countries' => ['us' => $counts['total']],
        ];
    }

    protected function mentionsFor(string $keyword, string $day): array
    {
        // search/live's content_info.connotation_types are 0-1 probability
        // fractions per citation (NOT integer counts like summary/live) —
        // https://docs.dataforseo.com/v3/content_analysis/search/live/. The
        // rating sub-object key is rating_value, not value.
        $good = [
            'type' => 'content_analysis_search', 'url' => 'https://good-mention.test/a', 'domain' => 'good-mention.test', 'main_domain' => 'good-mention.test',
            'content_info' => ['title' => 'Great remodel', 'date_published' => '2026-08-01', 'connotation_types' => ['positive' => 0.91, 'negative' => 0.02, 'neutral' => 0.07], 'rating' => ['rating_value' => 4.8]],
        ];
        if ($day === 'day1') {
            return [$good];
        }

        $bad = [
            'type' => 'content_analysis_search', 'url' => 'https://bad-mention.test/x', 'domain' => 'bad-mention.test', 'main_domain' => 'bad-mention.test',
            'content_info' => ['title' => 'Complaint thread', 'date_published' => '2026-09-10', 'connotation_types' => ['positive' => 0.05, 'negative' => 0.83, 'neutral' => 0.12], 'rating' => null],
        ];
        // A porch.com directory page reads as "negative" (its template says "unscreened"): a citation, not a complaint.
        $directory = [
            'type' => 'content_analysis_search', 'url' => 'https://pro.porch.com/zion-il/bathtub-installation/cs', 'domain' => 'pro.porch.com', 'main_domain' => 'porch.com',
            'content_info' => ['title' => 'Are there any unscreened bathtub installation services in Zion I can browse?', 'date_published' => '2026-09-09', 'connotation_types' => ['positive' => 0.05, 'negative' => 0.9, 'neutral' => 0.05], 'rating' => null],
        ];
        $cited = [
            'type' => 'content_analysis_search', 'url' => 'https://cited-us.test/no-link', 'domain' => 'cited-us.test', 'main_domain' => 'cited-us.test',
            'content_info' => ['title' => 'Local remodelers roundup', 'date_published' => '2026-09-11', 'connotation_types' => ['positive' => 0.76, 'negative' => 0.03, 'neutral' => 0.21], 'rating' => null],
        ];

        return [$good, $bad, $directory, $cited];
    }

    protected function trendSeries(string $day): array
    {
        $months = [];
        for ($i = 11; $i >= 1; $i--) {
            $months[] = ['type' => 'content_analysis_trends', 'date' => now()->subMonths($i)->startOfMonth()->toDateString(), 'total_count' => 80];
        }
        // Day 1: last month in line with the average (no finding). Day 2: last
        // month spikes >=25% above the 12-month average (finding fires).
        $months[] = ['type' => 'content_analysis_trends', 'date' => now()->startOfMonth()->toDateString(), 'total_count' => $day === 'day1' ? 82 : 110];

        return $months;
    }

    public function test_two_runs_store_snapshots_and_open_the_expected_findings(): void
    {
        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->fake('day1');
        $this->artisan('seo:intel', ['family' => ['content_analysis'], '--budget' => 1])->assertExitCode(0);

        // 2 brand-kind snapshots (us + competitor) + 1 mention + 1 phrase.
        $this->assertSame(4, DB::table('seo_intel_snapshots')->count());
        $this->assertSame('20260905', substr((string) DB::table('seo_intel_runs')->value('run_id'), 0, 8));

        // First sighting of the one mention opens a WIN "new mention" finding.
        $this->assertNotNull(DB::table('seo_intel_findings')->where('code', 'content_analysis.new_mention')->where('subject', 'https://good-mention.test/a')->first());

        Carbon::setTestNow('2026-09-12 06:00:00');
        $this->fake('day2');
        $this->artisan('seo:intel', ['family' => ['content_analysis']])->assertExitCode(0);

        $findings = DB::table('seo_intel_findings')->whereNull('resolved_at')->pluck('severity', 'code');
        $this->assertSame('win', $findings['content_analysis.brand_mentions_up'] ?? null);
        $this->assertSame('warn', $findings['content_analysis.negative_mentions_up'] ?? null);
        $this->assertSame('info', $findings['content_analysis.competitor_mentions_outpacing'] ?? null);
        $this->assertSame('warn', $findings['content_analysis.negative_mention'] ?? null);
        $this->assertSame('info', $findings['content_analysis.phrase_trending'] ?? null);

        $mentionsUp = DB::table('seo_intel_findings')->where('code', 'content_analysis.brand_mentions_up')->first();
        $this->assertSame(['total_count' => ['prev' => 40, 'now' => 45]], json_decode((string) $mentionsUp->delta, true));

        $negMentions = DB::table('seo_intel_findings')->where('code', 'content_analysis.negative_mention')->get();
        $this->assertCount(1, $negMentions, 'the directory page is a citation, not a negative mention');
        $this->assertSame('https://bad-mention.test/x', $negMentions->first()->subject);
        $this->assertNotNull(DB::table('seo_intel_snapshots')->where('kind', 'mention')->where('subject', 'https://pro.porch.com/zion-il/bathtub-installation/cs')->first(), 'still stored as a mention');

        // The docs' search/live response carries no "links to target" field, so
        // link_to_us starts out null (unknown) and the citation opens a WIN
        // "new mention" — not "unlinked". If a future response ever exposes
        // that field as false, findings() must react by swapping to the INFO
        // "ask for a link" finding and resolving the WIN. Simulate that by
        // patching the stored payload the way a richer API response would.
        $this->assertNotNull(DB::table('seo_intel_findings')->where('code', 'content_analysis.new_mention')->where('subject', 'https://cited-us.test/no-link')->whereNull('resolved_at')->first());

        $row = DB::table('seo_intel_snapshots')->where('kind', 'mention')->where('subject', 'https://cited-us.test/no-link')->first();
        $payload = json_decode((string) $row->payload, true);
        $payload['link_to_us'] = false;
        DB::table('seo_intel_snapshots')->where('id', $row->id)->update(['payload' => json_encode($payload)]);

        $this->artisan('seo:intel', ['family' => ['content_analysis'], '--findings' => true])->assertExitCode(0);

        $unlinked = DB::table('seo_intel_findings')->where('code', 'content_analysis.unlinked_mention')->whereNull('resolved_at')->first();
        $this->assertNotNull($unlinked, 'unlinked_mention fires once link_to_us is known false');
        $this->assertSame('https://cited-us.test/no-link', $unlinked->subject);
        $this->assertNotNull(DB::table('seo_intel_findings')->where('code', 'content_analysis.new_mention')->where('subject', 'https://cited-us.test/no-link')->value('resolved_at'), 'the superseded new_mention finding is resolved');
    }

    public function test_report_has_tiles_and_tables_after_two_runs(): void
    {
        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->fake('day1');
        $this->artisan('seo:intel', ['family' => ['content_analysis'], '--budget' => 1])->assertExitCode(0);

        Carbon::setTestNow('2026-09-12 06:00:00');
        $this->fake('day2');
        $this->artisan('seo:intel', ['family' => ['content_analysis']])->assertExitCode(0);

        $source = app(ContentAnalysisSource::class);
        $report = $source->report();

        $tiles = collect($report['tiles'])->keyBy('label');
        $this->assertSame(45, $tiles['Our mentions']['value']);
        $this->assertSame(40, $tiles['Our mentions']['prev']);
        $this->assertSame(30, $tiles['Positive mentions']['value']);
        $this->assertSame(5, $tiles['Negative mentions']['value']);
        $this->assertSame(35, $tiles['Competitor mentions']['value']);

        $tables = collect($report['tables'])->keyBy('title');
        $this->assertSame(['Domain', 'Title', 'Sentiment', 'Linked'], $tables['Recent mentions']['columns']);
        $rows = collect($tables['Recent mentions']['rows']);
        $this->assertTrue($rows->contains(fn ($r) => $r[0] === 'bad-mention.test' && $r[2] === 'negative'));
        $this->assertTrue($rows->contains(fn ($r) => $r[0] === 'good-mention.test' && $r[2] === 'positive'));

        $this->assertSame([['Acme Remodeling', 35]], $tables['Competitor mentions']['rows']);
        $this->assertStringContainsString('2026-09-12', $report['note']);
    }

    public function test_report_does_not_throw_before_any_run(): void
    {
        $source = app(ContentAnalysisSource::class);
        $report = $source->report();
        $this->assertSame(0, $report['tiles'][0]['value']);
        $this->assertSame([], $report['tables'][0]['rows']);
        $this->assertStringContainsString('No Content Analysis', $report['note']);
    }

    public function test_collect_stops_once_the_cost_cap_is_exceeded(): void
    {
        config(['seo-intel.families.content_analysis.max_cost' => 0.03]);
        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->fake('day1');

        $this->artisan('seo:intel', ['family' => ['content_analysis'], '--budget' => 1])->assertExitCode(0);

        // Two summary calls (0.02 each = 0.04) trip the 0.03 cap before the
        // search or phrase_trends calls are made.
        $calls = Http::recorded(fn ($r) => str_contains($r->url(), 'content_analysis/'));
        $this->assertCount(2, $calls);
        $this->assertSame(2, DB::table('seo_intel_snapshots')->where('kind', 'brand')->count());
        $this->assertSame(0, DB::table('seo_intel_snapshots')->where('kind', 'mention')->count());
        $this->assertSame(0, DB::table('seo_intel_snapshots')->where('kind', 'phrase')->count());
    }
}
