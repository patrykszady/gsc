<?php

namespace Tests\Feature;

use App\Models\SeoAction;
use App\Models\SeoPathOverride;
use App\Services\AiContentService;
use App\Services\GoogleBusinessProfileService;
use App\Services\Seo\SeoAutopilotService;
use App\Services\Seo\TitleMetaGenerator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The money pages (/services/{slug}) get the same striking-distance title
 * experiments as town and project pages: the title carries the page's own
 * top non-branded query, and the rewrite lands as a path override.
 */
class SeoServicePageTitlesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_service_page_in_striking_distance_gets_a_query_led_title_experiment(): void
    {
        Cache::flush();
        $this->mock(GoogleBusinessProfileService::class, fn ($m) => $m->shouldReceive('isConfigured')->andReturn(false));
        $this->mock(AiContentService::class, fn ($m) => $m->shouldReceive('generateText')->never());
        $page = 'https://gs.construction/services/kitchen-remodeling';
        $row = fn (string $query, int $impressions, int $clicks, float $position, int $i) => ['site_id' => null, 'date' => now()->subDays($i)->toDateString(), 'site_url' => 'sc-domain:gs.construction', 'query' => $query, 'page' => $page, 'country' => 'usa', 'device' => 'MOBILE', 'impressions' => $impressions, 'clicks' => $clicks, 'position' => $position, 'ctr' => $impressions ? $clicks / $impressions : 0, 'dim_hash' => md5($query . $i), 'created_at' => now(), 'updated_at' => now()];
        DB::table('gsc_query_metrics')->insert([
            $row('kitchen remodeling contractors near me', 300, 3, 7.0, 3),
            $row('kitchen remodeling contractors near me', 250, 2, 7.5, 5),
            $row('gs construction kitchen remodeling', 400, 4, 1.2, 4), // branded: never the title
            $row('kitchen remodel cost', 80, 1, 14.0, 6),
        ]);

        app(SeoAutopilotService::class)->synthesize();

        $action = SeoAction::where('category', 'title_meta')->where('target_url', $page)->first();
        $this->assertNotNull($action, 'the money page is a title_meta target');
        $this->assertNull($action->target_type, 'no model behind a service page');
        $this->assertStringContainsString('Kitchen Remodeling Contractors Near Me', $action->payload['new_title']);
        $this->assertStringNotContainsString('Gs Construction', $action->payload['new_title']);
        $this->assertStringContainsString('Chicago Suburbs', $action->payload['new_description']);
        $this->assertSame(SeoAction::RISK_SAFE, $action->risk);

        app(SeoAutopilotService::class)->act();
        $override = SeoPathOverride::where('path', SeoPathOverride::normalizePath($page))->first();
        $this->assertNotNull($override, 'applied as a path override');
        $this->assertSame($action->payload['new_title'], $override->title);
    }

    public function test_generator_falls_back_to_a_regional_service_title_without_a_query(): void
    {
        $g = new TitleMetaGenerator();
        $t = $g->forService('bathroom-remodeling');
        $this->assertStringContainsString('Bathroom Remodeling Contractors', $t['title']);
        $this->assertLessThanOrEqual(60, mb_strlen($t['title']));
        $this->assertStringStartsWith('Free estimate on bathroom remodeling', $t['description']);
        $this->assertLessThanOrEqual(158, mb_strlen($t['description']));
    }
}
