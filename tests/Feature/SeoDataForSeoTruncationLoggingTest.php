<?php

namespace Tests\Feature;

use App\Services\DataForSeoService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A6: rankedKeywords/keywordIdeas/referringDomains cap `items` at whatever
 * `limit` was requested, even when the API's `total_count` says more exist
 * — there is no paging. One warning line makes that visible; nothing else
 * about the response changes.
 */
class SeoDataForSeoTruncationLoggingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.dataforseo.login' => 'u', 'services.dataforseo.password' => 'p']);
    }

    /** One task_get-style envelope: { tasks: [ { status_code, result: [ { total_count, items } ] } ] }. */
    private function envelope(int $totalCount, array $items): array
    {
        $result = ['total_count' => $totalCount, 'items' => $items];

        return ['tasks' => [['status_code' => 20000, 'result' => [$result]]]];
    }

    public function test_ranked_keywords_logs_once_when_the_response_is_truncated(): void
    {
        Log::spy();
        $item = ['keyword_data' => ['keyword' => 'kitchen remodeling', 'keyword_info' => ['search_volume' => 100]], 'ranked_serp_element' => ['serp_item' => ['rank_absolute' => 3, 'url' => 'https://x.test']]];
        Http::fake(['*/dataforseo_labs/google/ranked_keywords/live' => Http::response($this->envelope(4200, [$item]))]);

        $out = app(DataForSeoService::class)->rankedKeywords('gs.construction', 1);

        $this->assertCount(1, $out);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'rankedKeywords') && str_contains($message, '1 of 4200'));
    }

    public function test_keyword_ideas_logs_once_when_the_response_is_truncated(): void
    {
        Log::spy();
        $item = ['keyword' => 'bathroom remodeling', 'keyword_info' => ['search_volume' => 50]];
        Http::fake(['*/dataforseo_labs/google/keyword_ideas/live' => Http::response($this->envelope(900, [$item]))]);

        $out = app(DataForSeoService::class)->keywordIdeas(['bathroom remodeling'], 1);

        $this->assertCount(1, $out);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'keywordIdeas') && str_contains($message, '1 of 900'));
    }

    public function test_referring_domains_logs_once_when_the_response_is_truncated(): void
    {
        Log::spy();
        $item = ['domain' => 'houzz.com', 'rank' => 300, 'backlinks' => 3];
        Http::fake(['*/backlinks/referring_domains/live' => Http::response($this->envelope(250, [$item]))]);

        $out = app(DataForSeoService::class)->referringDomains('gs.construction', 1);

        $this->assertCount(1, $out);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'referringDomains') && str_contains($message, '1 of 250'));
    }

    public function test_no_warning_when_the_response_is_not_truncated(): void
    {
        Log::spy();
        $item = ['domain' => 'houzz.com', 'rank' => 300, 'backlinks' => 3];
        Http::fake(['*/backlinks/referring_domains/live' => Http::response($this->envelope(1, [$item]))]);

        app(DataForSeoService::class)->referringDomains('gs.construction', 100);

        Log::shouldNotHaveReceived('warning');
    }
}
