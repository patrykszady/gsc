<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\LandingPageController;
use App\Models\LandingPage;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\Site;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Two-Site isolation guard for kit 0.13.0's LandingPagesController port
 * (RULES.md's tenancy guard: every unit that touches a gsc-scoped model
 * adds this test BEFORE the port ships) — admin-api-verify.md's own
 * verdict flags this as the missing regression net: "no BelongsToSite/
 * SiteScope test exists for landing_pages" today, and the most severe
 * failure mode it names is "a raw/bypassing query in the shared trait
 * drops gsc's site scope and leaks one tenant's landing pages into
 * another's admin list."
 *
 * PinAdminApiTenant always pins the real /api/admin/v1 HTTP route to the
 * 'gsc' tenant (by design — this API is called server-to-server for
 * exactly one site's data), so an HTTP-level test can never observe a
 * cross-tenant leak. This test instead drives the controller directly
 * under Tenancy::for(), exactly like CitationsInboxLinksIsolationTest,
 * SocialAutomationSeedIsolationTest and friends already do for their own
 * families.
 *
 * Written BEFORE porting LandingPageController onto the kit's
 * ServesLandingPages trait — passes against the pre-port controller
 * (BelongsToSite's ambient Site::current() scoping already protects it
 * today) and must keep passing unchanged once landingPageModel() is the
 * only tenancy-relevant hook in the shared trait.
 */
class LandingPageControllerTenancyIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makePage(Site $site, string $slug): LandingPage
    {
        return Tenancy::for($site, fn () => LandingPage::create([
            'slug' => $slug,
            'title' => "Page for {$site->slug}",
            'h1' => "Page for {$site->slug}",
            'status' => LandingPage::STATUS_DRAFT,
            'source' => 'manual',
            'proof_project_ids' => [],
        ]));
    }

    public function test_index_never_lists_the_other_tenants_pages(): void
    {
        $gsc = Site::where('slug', 'gsc')->firstOrFail();
        $jp = Site::where('slug', 'jpeterson')->firstOrFail();

        $this->makePage($gsc, 'gsc-only-page');
        $this->makePage($jp, 'jpeterson-only-page');

        $controller = app(LandingPageController::class);

        $response = Tenancy::for($gsc, fn () => $controller->index(Request::create('/')));
        $slugs = array_column($response->getData(true)['data'], 'slug');

        $this->assertSame(['gsc-only-page'], $slugs, 'gsc only ever sees its own landing pages, never jpeterson\'s');
    }

    public function test_publish_cannot_touch_the_other_tenants_page_by_id(): void
    {
        $gsc = Site::where('slug', 'gsc')->firstOrFail();
        $jp = Site::where('slug', 'jpeterson')->firstOrFail();

        $theirs = $this->makePage($jp, 'jpeterson-page-to-protect');

        $controller = app(LandingPageController::class);

        $this->expectException(ModelNotFoundException::class);

        Tenancy::for($gsc, fn () => $controller->publish($theirs->id));
    }

    public function test_destroy_cannot_touch_the_other_tenants_page_by_id(): void
    {
        $gsc = Site::where('slug', 'gsc')->firstOrFail();
        $jp = Site::where('slug', 'jpeterson')->firstOrFail();

        $theirs = $this->makePage($jp, 'jpeterson-page-to-protect-2');

        $controller = app(LandingPageController::class);

        try {
            Tenancy::for($gsc, fn () => $controller->destroy($theirs->id));
            $this->fail('gsc must not be able to delete jpeterson\'s landing page by id.');
        } catch (ModelNotFoundException) {
            // Expected.
        }

        $this->assertNotNull($theirs->fresh(), 'jpeterson\'s row survives gsc\'s attempt to delete it by id');
    }

    public function test_store_scopes_a_new_page_to_the_current_tenant_even_when_another_tenant_already_used_the_same_slug(): void
    {
        $gsc = Site::where('slug', 'gsc')->firstOrFail();
        $jp = Site::where('slug', 'jpeterson')->firstOrFail();

        // The composite (site_id, slug) unique index (2026_07_31 migration)
        // exists precisely because two tenants both serving a Chicago-area
        // suburb collided on a plain unique('slug') — this is the direct
        // regression guard for that.
        $this->makePage($jp, 'kitchen-remodeling-testville');

        $project = Project::create([
            'title' => 'Test Kitchen',
            'slug' => 'test-kitchen-'.uniqid(),
            'project_type' => 'kitchen',
            'is_published' => true,
            'location' => 'Elsewhere, IL',
        ]);
        ProjectImage::create([
            'project_id' => $project->id,
            'filename' => 'photo.jpg',
            'original_filename' => 'photo.jpg',
            'path' => 'projects/1/photo.jpg',
            'alt_text' => 'A lovely kitchen',
        ]);

        $controller = app(LandingPageController::class);
        $request = Request::create('/', 'POST', ['service' => 'kitchen-remodeling', 'city' => 'Testville']);

        $response = Tenancy::for($gsc, fn () => $controller->store($request));

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $ourPage = LandingPage::withoutSiteScope()->where('slug', 'kitchen-remodeling-testville')->where('site_id', $gsc->id)->first();
        $this->assertNotNull($ourPage, 'gsc could create the same deterministic slug jpeterson already used, scoped to its own tenant');
    }
}
