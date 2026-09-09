<?php

namespace Tests\Feature;

use App\Models\SeoAction;
use App\Services\AiContentService;
use App\Services\GoogleBusinessProfileService;
use App\Services\Seo\Appliers\GbpDescriptionApplier;
use App\Services\Seo\SeoAutopilotService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The Google Business Profile description: proposed every ~90 days as a
 * review-risk action with three variants, applied (and reverted) through
 * the Business Information API once a person approves it.
 */
class SeoGbpDescriptionTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['seo.autopilot.gbp_description_enabled' => true]);
    }

    public function test_autopilot_proposes_three_variants_as_a_review_action_once_per_period(): void
    {
        Cache::flush();
        $this->mock(GoogleBusinessProfileService::class, function ($m) {
            $m->shouldReceive('isConfigured')->andReturn(true);
            $m->shouldReceive('getDescription')->andReturn('We remodel kitchens.');
        });
        $variants = ['keyword' => str_repeat('Kitchen, bathroom and basement remodeling across Palatine and Arlington Heights. ', 3), 'conversion' => str_repeat('Free in-home estimates with itemized pricing, and the owners on every job. ', 3), 'trust' => str_repeat('A father-and-son team since 2015 with five-star reviews and full licensing. ', 3)];
        $this->mock(AiContentService::class, function ($m) use ($variants) {
            $m->shouldReceive('generateText')->once()->andReturn("```json\n" . json_encode(['keyword' => $variants['keyword'] . ' Call (224) 735-4200 or visit https://gs.construction/', 'conversion' => $variants['conversion'], 'trust' => $variants['trust']]) . "\n```");
        });

        app(SeoAutopilotService::class)->synthesize();
        $action = SeoAction::where('category', 'gbp_description')->first();
        $this->assertNotNull($action);
        $this->assertSame(SeoAction::RISK_REVIEW, $action->risk, 'a person approves it in the panel');
        $this->assertSame('We remodel kitchens.', $action->payload['current']);
        $this->assertSame(['keyword', 'conversion', 'trust'], array_keys($action->payload['variants']));
        $this->assertStringNotContainsString('224', $action->payload['new_description'], 'phone numbers are stripped');
        $this->assertStringNotContainsString('http', $action->payload['new_description'], 'URLs are stripped');
        $this->assertStringContainsString('Palatine', $action->payload['new_description']);

        // Not proposed again inside the period.
        app(SeoAutopilotService::class)->synthesize();
        $this->assertSame(1, SeoAction::where('category', 'gbp_description')->count());
    }

    public function test_applier_writes_the_chosen_text_and_revert_restores_the_previous_one(): void
    {
        $gbp = $this->mock(GoogleBusinessProfileService::class, function ($m) {
            $m->shouldReceive('isConfigured')->andReturn(true);
            $m->shouldReceive('getDescription')->once()->andReturn('Old description.');
            $m->shouldReceive('updateDescription')->once()->with('New keyword-led description.')->andReturn(['name' => 'locations/1']);
            $m->shouldReceive('updateDescription')->once()->with('Old description.')->andReturn(['name' => 'locations/1']);
        });
        $action = SeoAction::create(['fingerprint' => 'g1', 'source' => 'gbp', 'category' => 'gbp_description', 'risk' => 'review', 'status' => 'proposed', 'target_url' => 'https://gs.construction/', 'title' => 't', 'hypothesis' => 'h', 'metric' => 'impressions', 'payload' => ['new_description' => 'New keyword-led description.']]);

        $applier = new GbpDescriptionApplier();
        $applier->apply($action);
        $this->assertSame('Old description.', $action->payload['prev_description']);
        $this->assertSame('New keyword-led description.', $action->payload['applied_description']);
        $applier->revert($action);
    }

    public function test_nothing_is_proposed_when_the_profile_is_not_connected(): void
    {
        Cache::flush();
        $this->mock(GoogleBusinessProfileService::class, fn ($m) => $m->shouldReceive('isConfigured')->andReturn(false));
        $this->mock(AiContentService::class, fn ($m) => $m->shouldReceive('generateText')->never());
        app(SeoAutopilotService::class)->synthesize();
        $this->assertSame(0, SeoAction::where('category', 'gbp_description')->count());
    }
}
