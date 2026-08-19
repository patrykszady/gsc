<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * The autopilot panel lives inside the SEO Reports page.
 *
 * It was a standalone /admin-legacy/{site}/autopilot page, which put the dashboard's
 * "approve open proposals" recommendations and the machine that acts on them
 * two URLs apart. The panel is now a nested Livewire island on seo-reports,
 * and the old URL 301s there so bookmarks and the dashboard tiles keep
 * working.
 */
class SeoReportsAutopilotMergeTest extends TestCase
{
    private function operator(): User
    {
        return User::factory()->create(['site_id' => null]);
    }

    public function test_seo_reports_renders_the_autopilot_panel(): void
    {
        $this->actingAs($this->operator())
            ->get('http://gs.construction/admin-legacy/gs.construction/seo-reports')
            ->assertOk()
            ->assertSee('SEO Autopilot')
            ->assertSee('id="autopilot"', false);
    }

    public function test_seo_reports_renders_clarity_and_geo_cards(): void
    {
        $this->actingAs($this->operator())
            ->get('http://gs.construction/admin-legacy/gs.construction/seo-reports')
            ->assertOk()
            ->assertSee('Microsoft Clarity')
            ->assertSee('AI crawler feeds')
            ->assertSee('llms.txt');
    }

    public function test_the_old_autopilot_url_redirects_permanently(): void
    {
        $response = $this->actingAs($this->operator())
            ->get('http://gs.construction/admin-legacy/gs.construction/autopilot');

        $response->assertStatus(301);
        $this->assertStringContainsString(
            '/admin-legacy/gs.construction/seo-reports',
            (string) $response->headers->get('Location'),
        );
    }
}
