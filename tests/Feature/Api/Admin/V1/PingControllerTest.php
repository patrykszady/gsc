<?php

namespace Tests\Feature\Api\Admin\V1;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use SsSystems\Platform\Kit;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * Pins GET /api/admin/v1/ping's domain list — previously a hand-maintained
 * array on PingController itself with no test at all (the class this
 * unit ports, SsSystems\Platform\Http\Admin\CapabilityRegistry, replaced
 * that array with a self-registering declare()/domains() call in every
 * routes/api-admin/*.php file — see each file and the inline blocks in
 * routes/api.php for exactly which domain(s) each one declares). This
 * test is the one thing standing between a route file quietly dropping
 * its declare() call and ss-systems silently losing a screen for this
 * site — exactly the live jpeterson-design 'js-errors' drift bug that
 * motivated the port in the first place, just guarded here instead.
 */
class PingControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
    }

    public function test_reports_the_exact_domain_list_every_route_file_declares(): void
    {
        $data = $this->getJson('/api/admin/v1/ping', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame(Kit::VERSION, $data['platform_kit']);
        $this->assertEqualsCanonicalizing([
            'dashboard-stats', 'projects', 'tags', 'testimonials', 'blog', 'areas', 'leads',
            'landing-pages', 'social-media', 'analytics', 'js-errors', 'seo', 'platforms',
            'timelapses', 'before-afters', 'image-tags', 'image-move', 'areas-map',
            'review-platforms', 'testimonial-projects', 'collaborators', 'area-content',
            'citations', 'services', 'service-content', 'pages',
        ], $data['domains']);
    }
}
