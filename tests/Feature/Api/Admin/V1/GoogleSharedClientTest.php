<?php

namespace Tests\Feature\Api\Admin\V1;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SsSystems\Platform\Google\Adapters\PlatformSettingSharedClient;
use Tests\TestCase;

/**
 * ss.systems owns the one Google sign-in client and provisions it here
 * (kit 0.15.0, 2026-09-30): no Google keys in this site's .env.
 */
class GoogleSharedClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        config(['services.google.oauth' => ['client_id' => null, 'client_secret' => null, 'project_id' => null]]);
    }

    protected function bearer(): array
    {
        config(['services.admin_api.token' => 'test-admin-api-token']);

        return ['Authorization' => 'Bearer test-admin-api-token', 'Accept' => 'application/json'];
    }

    public function test_ss_systems_provisions_the_shared_client_and_sign_in_reads_it(): void
    {
        $this->getJson('/api/admin/v1/platforms/status', $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.google.configured', false);

        $this->putJson('/api/admin/v1/platforms/google/shared-client', [
            'client_id' => '31627704418-shared.apps.googleusercontent.com',
            'client_secret' => 'GOCSPX-shared',
            'project_id' => 'gen-lang-client-1',
        ], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.stored', true)
            ->assertJsonMissingPath('data.client_secret');

        $this->getJson('/api/admin/v1/platforms/status', $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.google.configured', true)
            ->assertJsonPath('data.google.source', 'platform');

        $this->assertStringNotContainsString('GOCSPX-shared', (string) DB::table('platform_settings')->where('key', PlatformSettingSharedClient::CLIENT_SECRET)->value('value'));

        $this->deleteJson('/api/admin/v1/platforms/google/shared-client', [], $this->bearer())->assertOk();
        $this->getJson('/api/admin/v1/platforms/status', $this->bearer())->assertJsonPath('data.google.configured', false);
    }

    public function test_the_shared_client_endpoint_needs_the_admin_token(): void
    {
        $this->putJson('/api/admin/v1/platforms/google/shared-client', ['client_id' => 'x.apps.googleusercontent.com', 'client_secret' => 'y'])
            ->assertUnauthorized();
    }
}
