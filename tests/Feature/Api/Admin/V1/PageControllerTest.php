<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\SeoPathOverride;
use App\Models\Site;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * gs.construction's built-in pages screen. Unlike dawnsellshomes.com's
 * DB-row pages, these are routes/views — title/description round-trip
 * through SeoPathOverride (the path-keyed table SEOBuilder::build()
 * actually reads at render time), never the polymorphic `seo` table
 * SeoOverrideController writes, which nothing in gsc's render path ever
 * consults for these pages.
 */
class PageControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
    }

    private function homeId(array $rows): int
    {
        foreach ($rows as $row) {
            if ($row['path'] === '/') {
                return $row['id'];
            }
        }

        $this->fail('home page row not found in the list');
    }

    public function test_it_lists_gscs_built_in_pages_with_their_current_effective_titles(): void
    {
        $data = $this->getJson('/api/admin/v1/pages?per_page=50', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $paths = array_column($data, 'path');
        $this->assertContains('/', $paths);
        $this->assertContains('about', $paths);
        $this->assertContains('contact', $paths);
        $this->assertContains('services', $paths);
        $this->assertContains('services/kitchen-remodeling', $paths);
        $this->assertContains('areas-served', $paths);

        $home = collect($data)->firstWhere('path', '/');
        $this->assertNotNull($home['title']);
        $this->assertStringContainsString('GS Construction', $home['title']);
        $this->assertNotNull($home['meta_description']);
        $this->assertSame('home', $home['type']);
        $this->assertNull($home['in_sitemap']); // nothing to switch: the admin hides the sitemap toggle
        $this->assertStringContainsString('/', $home['url']);
    }

    public function test_pages_types_returns_counts_by_type(): void
    {
        $rows = $this->getJson('/api/admin/v1/pages/types', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $byType = collect($rows)->pluck('count', 'type');

        $this->assertSame(1, $byType['home']);
        $this->assertSame(6, $byType['service']);
        $this->assertGreaterThanOrEqual(1, $byType['page']);
    }

    public function test_show_returns_the_detail_shape_with_no_override_yet(): void
    {
        $list = $this->getJson('/api/admin/v1/pages?per_page=50', $this->adminApiHeaders())->json('data');
        $id = $this->homeId($list);

        $data = $this->getJson("/api/admin/v1/pages/{$id}", $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame($id, $data['id']);
        $this->assertNull($data['updated_at']);
        $this->assertFalse($data['editable_body']);
        $this->assertArrayHasKey('canonical', $data);
        $this->assertArrayHasKey('meta_keywords', $data);
    }

    public function test_show_404s_for_an_unknown_id(): void
    {
        $this->getJson('/api/admin/v1/pages/999999999', $this->adminApiHeaders())->assertNotFound();
    }

    public function test_update_writes_the_override_and_it_is_read_back(): void
    {
        $list = $this->getJson('/api/admin/v1/pages?per_page=50', $this->adminApiHeaders())->json('data');
        $id = $this->homeId($list);

        $data = $this->putJson("/api/admin/v1/pages/{$id}", [
            'title' => 'Custom Home Title',
            'meta_description' => 'Custom home description.',
        ], $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame('Custom Home Title', $data['title']);
        $this->assertSame('Custom home description.', $data['meta_description']);
        $this->assertNotNull($data['updated_at']);

        // Round trip: a fresh GET (list and detail) sees exactly this.
        $reread = $this->getJson("/api/admin/v1/pages/{$id}", $this->adminApiHeaders())->json('data');
        $this->assertSame('Custom Home Title', $reread['title']);

        $listAfter = $this->getJson('/api/admin/v1/pages?per_page=50', $this->adminApiHeaders())->json('data');
        $this->assertSame('Custom Home Title', collect($listAfter)->firstWhere('id', $id)['title']);

        // And it actually reaches the store SEOBuilder::build() reads —
        // the whole point of using SeoPathOverride instead of the
        // polymorphic `seo` table.
        $this->assertSame(
            'Custom Home Title',
            SeoPathOverride::forPath('/')['title'] ?? null,
        );
    }

    public function test_update_accepts_a_partial_put_with_no_title(): void
    {
        // PageList's sitemap toggle sends only in_sitemap — must never 422.
        $list = $this->getJson('/api/admin/v1/pages?per_page=50', $this->adminApiHeaders())->json('data');
        $id = $this->homeId($list);

        $this->putJson("/api/admin/v1/pages/{$id}", ['in_sitemap' => false], $this->adminApiHeaders())
            ->assertOk();
    }

    public function test_update_blank_title_clears_the_override_back_to_the_default(): void
    {
        $list = $this->getJson('/api/admin/v1/pages?per_page=50', $this->adminApiHeaders())->json('data');
        $id = $this->homeId($list);
        $defaultTitle = collect($list)->firstWhere('id', $id)['title'];

        $this->putJson("/api/admin/v1/pages/{$id}", ['title' => 'Temporary'], $this->adminApiHeaders())->assertOk();
        $this->putJson("/api/admin/v1/pages/{$id}", ['title' => '  '], $this->adminApiHeaders())->assertOk();

        $reread = $this->getJson("/api/admin/v1/pages/{$id}", $this->adminApiHeaders())->json('data');
        $this->assertSame($defaultTitle, $reread['title']);
    }

    public function test_store_refuses_with_a_plain_message(): void
    {
        $this->postJson('/api/admin/v1/pages', ['title' => 'New Page'], $this->adminApiHeaders())
            ->assertStatus(422)
            ->assertJson(['message' => "This site's pages are built into it; ask us to add one."]);
    }

    public function test_destroy_refuses_with_a_plain_message(): void
    {
        $list = $this->getJson('/api/admin/v1/pages?per_page=50', $this->adminApiHeaders())->json('data');
        $id = $this->homeId($list);

        $this->deleteJson("/api/admin/v1/pages/{$id}", [], $this->adminApiHeaders())
            ->assertStatus(422)
            ->assertJson(['message' => "This site's pages are built into it; ask us to add one."]);
    }

    public function test_destroy_404s_for_an_unknown_id(): void
    {
        $this->deleteJson('/api/admin/v1/pages/999999999', [], $this->adminApiHeaders())->assertNotFound();
    }

    public function test_tenant_a_never_sees_tenant_bs_page_overrides(): void
    {
        $jpeterson = Site::where('slug', 'jpeterson')->firstOrFail();

        // jpeterson overrides its own home title directly in the store —
        // this API is permanently pinned to gsc (PinAdminApiTenant), so
        // there is no HTTP path to write it as jpeterson through here.
        Tenancy::for($jpeterson, function () {
            SeoPathOverride::create([
                'path' => '/',
                'title' => 'J Peterson Home Override',
                'description' => 'jpeterson only',
                'source' => 'admin',
            ]);
        });

        $list = $this->getJson('/api/admin/v1/pages?per_page=50', $this->adminApiHeaders())->json('data');
        $home = collect($list)->firstWhere('path', '/');

        $this->assertNotSame('J Peterson Home Override', $home['title']);

        // gsc's own override still applies normally alongside it.
        $this->putJson("/api/admin/v1/pages/{$home['id']}", ['title' => 'GSC Home Override'], $this->adminApiHeaders())
            ->assertOk();

        $gscTitle = Tenancy::for(Site::where('slug', 'gsc')->firstOrFail(), fn () => SeoPathOverride::forPath('/')['title'] ?? null);
        $jpetersonTitle = Tenancy::for($jpeterson, fn () => SeoPathOverride::forPath('/')['title'] ?? null);

        $this->assertSame('GSC Home Override', $gscTitle);
        $this->assertSame('J Peterson Home Override', $jpetersonTitle);
    }
}
