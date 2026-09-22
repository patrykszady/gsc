<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\PlatformSetting;
use App\Support\Seo\DataForSeoSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DataForSEO's per-site login + password. Unlike Bing/Clarity/PageSpeed
 * this one is never typed at THIS site's own /admin (Patryk's call,
 * 2026-09-22): ss.systems provisions it through the same endpoint when the
 * tenant is switched on, so a stored value is reported with source
 * 'platform', never the generic 'admin'.
 */
class PlatformsDataForSeoCredentialsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.dataforseo.login' => null,
            'services.dataforseo.password' => null,
        ]);
    }

    protected function bearer(): array
    {
        config(['services.admin_api.token' => 'test-admin-api-token']);

        return ['Authorization' => 'Bearer test-admin-api-token', 'Accept' => 'application/json'];
    }

    public function test_save_persists_both_fields_encrypted(): void
    {
        $this->postJson('/api/admin/v1/platforms/dataforseo/credentials', [
            'login' => 'dfs-login',
            'password' => 'dfs-secret-password',
        ], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.dataforseo.configured', true)
            ->assertJsonPath('data.dataforseo.source', 'platform');

        $this->assertSame('dfs-login', PlatformSetting::get(DataForSeoSettings::SETTING_LOGIN));
        $this->assertSame('dfs-secret-password', PlatformSetting::get(DataForSeoSettings::SETTING_PASSWORD));

        $raw = DB::table('platform_settings')->where('key', DataForSeoSettings::SETTING_PASSWORD)->value('value');
        $this->assertNotSame('dfs-secret-password', $raw);
    }

    public function test_validation_errors_map_to_fields(): void
    {
        $this->postJson('/api/admin/v1/platforms/dataforseo/credentials', [
            'login' => str_repeat('x', 256),
            'password' => str_repeat('y', 256),
        ], $this->bearer())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['login', 'password']);

        $this->assertNull(PlatformSetting::get(DataForSeoSettings::SETTING_LOGIN));
    }

    public function test_status_never_returns_the_raw_password(): void
    {
        $response = $this->postJson('/api/admin/v1/platforms/dataforseo/credentials', [
            'login' => 'dfs-login',
            'password' => 'do-not-leak-this-password',
        ], $this->bearer())->assertOk();

        $response->assertDontSee('do-not-leak-this-password', false);
        $this->assertArrayNotHasKey('password', $response->json('data.dataforseo'));
    }

    public function test_a_blank_resave_keeps_the_existing_values(): void
    {
        PlatformSetting::put(DataForSeoSettings::SETTING_LOGIN, 'already-saved-login');
        PlatformSetting::put(DataForSeoSettings::SETTING_PASSWORD, 'already-saved-password');

        $this->postJson('/api/admin/v1/platforms/dataforseo/credentials', [
            'login' => '',
            'password' => '',
        ], $this->bearer())->assertOk();

        $this->assertSame('already-saved-login', PlatformSetting::get(DataForSeoSettings::SETTING_LOGIN));
        $this->assertSame('already-saved-password', PlatformSetting::get(DataForSeoSettings::SETTING_PASSWORD));
    }

    public function test_clearing_removes_both_stored_fields_and_reports_not_switched_on(): void
    {
        PlatformSetting::put(DataForSeoSettings::SETTING_LOGIN, 'login');
        PlatformSetting::put(DataForSeoSettings::SETTING_PASSWORD, 'password');

        $this->deleteJson('/api/admin/v1/platforms/dataforseo/credentials', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.dataforseo.configured', false)
            ->assertJsonPath('data.dataforseo.source', null);

        $this->assertNull(PlatformSetting::get(DataForSeoSettings::SETTING_LOGIN));
        $this->assertNull(PlatformSetting::get(DataForSeoSettings::SETTING_PASSWORD));
    }

    public function test_spend_this_month_sums_this_calendar_months_intel_run_costs(): void
    {
        if (! Schema::hasTable('seo_intel_runs')) {
            $this->markTestSkipped('seo_intel_runs table not migrated in this environment.');
        }

        PlatformSetting::put(DataForSeoSettings::SETTING_LOGIN, 'login');
        PlatformSetting::put(DataForSeoSettings::SETTING_PASSWORD, 'password');

        DB::table('seo_intel_runs')->insert([
            'family' => 'serp', 'run_id' => 'this-month', 'taken_on' => now()->startOfMonth()->addDay()->toDateString(),
            'cost' => 1.25, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('seo_intel_runs')->insert([
            'family' => 'labs', 'run_id' => 'last-month', 'taken_on' => now()->subMonthNoOverflow()->toDateString(),
            'cost' => 9.99, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $data = $this->getJson('/api/admin/v1/platforms/status', $this->bearer())->assertOk()->json('data.dataforseo');

        $this->assertSame(1.25, $data['spend_this_month']);
    }

    public function test_spend_this_month_is_null_before_the_intel_table_exists(): void
    {
        Schema::dropIfExists('seo_intel_runs');

        $data = $this->getJson('/api/admin/v1/platforms/status', $this->bearer())->assertOk()->json('data.dataforseo');

        $this->assertNull($data['spend_this_month']);
    }
}
