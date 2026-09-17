<?php

namespace Tests\Feature;

use App\Models\SeoAction;
use App\Services\Seo\Appliers\LlmsRegenApplier;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The llms_regen autopilot action rebuilds llms.txt and llms-full.txt and
 * nothing else. It once also ran `geo:feed`, whose vendor generator writes a
 * static public/ai-feed.json — the exact path AiFeedController serves
 * dynamically — so every applied action would have shadowed the real feed
 * with an empty file until the next deploy rotated it away.
 */
class SeoAutopilotLlmsRegenApplierTest extends TestCase
{
    public function test_it_rebuilds_both_llms_files(): void
    {
        Artisan::spy();

        (new LlmsRegenApplier)->apply(new SeoAction);

        Artisan::shouldHaveReceived('call')->with('geo:llms-txt')->once();
        Artisan::shouldHaveReceived('call')->with('geo:llms-txt', ['--full' => true])->once();
    }

    public function test_it_never_runs_the_static_feed_generator(): void
    {
        Artisan::spy();

        (new LlmsRegenApplier)->apply(new SeoAction);

        Artisan::shouldNotHaveReceived('call', ['geo:feed']);
        Artisan::shouldNotHaveReceived('call', ['geo:feed', \Mockery::any()]);
    }

    public function test_the_static_feed_path_is_the_dynamic_route_it_would_shadow(): void
    {
        // Documents WHY geo:feed is excluded: the vendor writes to
        // public_path(config('geo.feed.route')), which is the dynamic route.
        $this->assertSame('/ai-feed.json', config('geo.feed.route'));
        $this->assertFileDoesNotExist(public_path('ai-feed.json'));
    }
}
