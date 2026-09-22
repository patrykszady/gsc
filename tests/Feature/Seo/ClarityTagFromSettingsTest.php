<?php

namespace Tests\Feature\Seo;

use App\Models\PlatformSetting;
use App\Models\Site;
use App\Support\Seo\ClaritySettings;
use App\Support\Tenancy;
use Tests\TestCase;

/**
 * The public layout's Clarity tag (resources/views/components/layouts/app.blade.php)
 * used to read config('services.microsoft.clarity_id') directly. It now
 * goes through ClaritySettings::projectId() — the same admin-stored-first,
 * env-fallback resolver the export sync (SyncMicrosoftClarity) reads — so a
 * project id saved from /admin actually reaches the public page, and a
 * retired env key can't silently blank the tag for a tenant that already
 * moved to the stored value.
 */
class ClarityTagFromSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Every other Feature test's env leaks through config() unless
        // cleared — ClarityPageSyncTest, for one, sets a project id.
        config([
            'services.microsoft.clarity.project_id' => null,
            'services.microsoft.clarity.api_token' => null,
        ]);
    }

    private function bearer(): array
    {
        config(['services.admin_api.token' => 'test-admin-api-token']);

        return ['Authorization' => 'Bearer test-admin-api-token', 'Accept' => 'application/json'];
    }

    public function test_a_stored_project_id_renders_the_tag_with_that_id_not_the_env_one(): void
    {
        config(['services.microsoft.clarity.project_id' => 'env-project-id']);
        PlatformSetting::put(ClaritySettings::SETTING_PROJECT_ID, 'stored-project-id');

        $this->get('/faq')
            ->assertOk()
            ->assertSee('clarity.ms/tag/"+i', false) // the snippet itself always renders
            ->assertSee('"stored-project-id"', false)
            ->assertDontSee('"env-project-id"', false);
    }

    public function test_no_stored_value_falls_back_to_the_env_id(): void
    {
        config(['services.microsoft.clarity.project_id' => 'env-only-project-id']);

        $this->get('/faq')
            ->assertOk()
            ->assertSee('"env-only-project-id"', false);
    }

    public function test_neither_stored_nor_env_renders_no_tag_at_all(): void
    {
        $this->get('/faq')
            ->assertOk()
            ->assertDontSee('clarity.ms/tag', false)
            ->assertDontSee('Microsoft Clarity', false);
    }

    public function test_saving_credentials_through_the_admin_endpoint_makes_the_next_render_use_the_new_id(): void
    {
        // Warm the cache on "nothing configured" first — the sentinel path
        // that lets a negative result be cached too (see ClaritySettings).
        $this->get('/faq')->assertOk()->assertDontSee('clarity.ms/tag', false);

        $this->postJson('/api/admin/v1/platforms/clarity/credentials', [
            'project_id' => 'freshly-saved-id',
            'api_token' => 'freshly-saved-token',
        ], $this->bearer())->assertOk();

        $this->get('/faq')
            ->assertOk()
            ->assertSee('"freshly-saved-id"', false);
    }

    public function test_clearing_credentials_through_the_admin_endpoint_makes_the_next_render_drop_the_tag(): void
    {
        PlatformSetting::put(ClaritySettings::SETTING_PROJECT_ID, 'about-to-be-cleared');
        PlatformSetting::put(ClaritySettings::SETTING_API_TOKEN, 'about-to-be-cleared-token');

        // Warm the cache with the stored id before clearing it.
        $this->get('/faq')->assertOk()->assertSee('"about-to-be-cleared"', false);

        $this->deleteJson('/api/admin/v1/platforms/clarity/credentials', [], $this->bearer())->assertOk();

        $this->get('/faq')->assertOk()->assertDontSee('clarity.ms/tag', false);
    }

    /**
     * `php artisan tenants:run "seo:clarity-sync"` runs once per tenant in a
     * single PHP process via Tenancy::for() (see TenantsRun) — this pins
     * that a tenant switch is never served the previous tenant's memoized
     * id. gsc is the default site (bare cache key); jpeterson is not, so it
     * exercises the slug-prefixed key too.
     */
    public function test_the_project_id_is_scoped_per_tenant_even_within_one_process(): void
    {
        $jpd = Site::query()->firstOrCreate(['slug' => 'jpeterson'], [
            'name' => 'J. Peterson Design', 'theme' => 'jpeterson',
            'hosts' => ['jpeterson-design.com'], 'primary_host' => 'jpeterson-design.com',
        ]);
        $jpd->forceFill(['is_active' => true])->save();
        Site::forgetActive();

        PlatformSetting::put(ClaritySettings::SETTING_PROJECT_ID, 'gsc-only-project-id');

        $this->assertSame('gsc-only-project-id', app(ClaritySettings::class)->projectId());

        Tenancy::for($jpd->fresh(), function () {
            $this->assertNull(
                app(ClaritySettings::class)->projectId(),
                'a different tenant must never see the previous tenant\'s memoized/cached id'
            );
        });

        // And back on gsc, still itself — the switch away and back didn't clobber it.
        $this->assertSame('gsc-only-project-id', app(ClaritySettings::class)->projectId());
    }
}
