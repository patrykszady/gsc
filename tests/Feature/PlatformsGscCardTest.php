<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * The Search Console connection is manageable from the admin Platforms panel
 * (web OAuth), not only via the ssh-only search-console:auth command.
 */
class PlatformsGscCardTest extends TestCase
{
    public function test_platforms_page_renders_the_search_console_card(): void
    {
        $this->actingAs(User::factory()->create(['site_id' => null]))
            ->get('http://gs.construction/admin-legacy/gs.construction/platforms')
            ->assertOk()
            ->assertSee('Google Search Console')
            ->assertSee('sitemap');
    }

    public function test_gsc_callback_without_code_flashes_an_error(): void
    {
        $this->actingAs(User::factory()->create(['site_id' => null]))
            ->get('http://gs.construction/admin-legacy/gs.construction/platforms/gsc/callback')
            ->assertRedirect();
    }
}
