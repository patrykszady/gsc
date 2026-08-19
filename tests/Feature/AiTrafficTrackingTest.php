<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The TrackAiTraffic middleware turns AI-assistant referrals and AI-crawler
 * fetches into daily counters, surfaced on the SEO Reports GEO card — the
 * feedback signal for all GEO work.
 */
class AiTrafficTrackingTest extends TestCase
{
    public function test_ai_crawler_fetch_is_counted(): void
    {
        $this->get('http://gs.construction/faq', [
            'User-Agent' => 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)',
        ])->assertOk();

        $this->assertDatabaseHas('ai_traffic_daily', [
            'kind' => 'crawler',
            'source' => 'GPTBot',
        ]);
    }

    public function test_assistant_referral_is_counted(): void
    {
        $this->get('http://gs.construction/faq', [
            'Referer' => 'https://chatgpt.com/c/abc123',
        ])->assertOk();

        $this->assertDatabaseHas('ai_traffic_daily', [
            'kind' => 'referral',
            'source' => 'chatgpt',
        ]);
    }

    public function test_ordinary_traffic_is_not_counted(): void
    {
        $this->get('http://gs.construction/faq', [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0',
            'Referer' => 'https://www.google.com/',
        ])->assertOk();

        $this->assertSame(0, (int) DB::table('ai_traffic_daily')->count());
    }

    public function test_seo_reports_renders_the_ai_traffic_section(): void
    {
        $this->actingAs(User::factory()->create(['site_id' => null]))
            ->get('http://gs.construction/admin-legacy/gs.construction/seo-reports')
            ->assertOk()
            ->assertSee('AI traffic (28 days)');
    }
}
