<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\Site;
use App\Models\SocialAutomationSetting;
use App\Services\GoogleBusinessProfileService;
use App\Services\MetaSocialService;
use App\Services\Social\AutomationSettingsService;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * GET/PUT /api/admin/v1/social-media automation contract — the settings
 * behind the admin's "Automatic posting" cards. The admin API pins every
 * request to the gsc tenant (App\Http\Middleware\PinAdminApiTenant), so the
 * "site with no rows" shape is exercised directly against
 * App\Services\Social\AutomationSettingsService under a different tenant
 * rather than over HTTP.
 */
class SocialAutomationApiTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function fakeAllConfigured(bool $configured = true): void
    {
        $meta = Mockery::mock(MetaSocialService::class);
        $meta->shouldReceive('isInstagramConfigured')->andReturn($configured);
        $meta->shouldReceive('isFacebookConfigured')->andReturn($configured);
        $meta->shouldReceive('isPublishingEnabled')->andReturn($configured);
        $meta->shouldReceive('isInstagramConnected')->andReturn($configured);
        $meta->shouldReceive('isFacebookConnected')->andReturn($configured);
        $this->app->instance(MetaSocialService::class, $meta);

        $gbp = Mockery::mock(GoogleBusinessProfileService::class);
        $gbp->shouldReceive('isConfigured')->andReturn($configured);
        $gbp->shouldReceive('isConnected')->andReturn($configured);
        $this->app->instance(GoogleBusinessProfileService::class, $gbp);
    }

    /**
     * Connected, publishing switched off on the Platforms page: reported as
     * exactly that, not as "not connected" (2026-09-23). Only Meta has such a
     * switch; Google Business, connected, is simply ready.
     */
    public function test_a_connected_platform_with_publishing_switched_off_is_reported_as_such(): void
    {
        $this->fakeAllConfigured(false);
        $meta = Mockery::mock(MetaSocialService::class);
        $meta->shouldReceive('isInstagramConfigured')->andReturn(false);
        $meta->shouldReceive('isFacebookConfigured')->andReturn(false);
        $meta->shouldReceive('isPublishingEnabled')->andReturn(false);
        $meta->shouldReceive('isInstagramConnected')->andReturn(true);
        $meta->shouldReceive('isFacebookConnected')->andReturn(false);
        $this->app->instance(MetaSocialService::class, $meta);
        $gbp = Mockery::mock(GoogleBusinessProfileService::class);
        $gbp->shouldReceive('isConfigured')->andReturn(true);
        $gbp->shouldReceive('isConnected')->andReturn(true);
        $this->app->instance(GoogleBusinessProfileService::class, $gbp);

        $data = $this->getJson('/api/admin/v1/social-media', $this->adminApiHeaders())->assertOk()->json('data');

        $this->assertTrue($data['publishing_off']['instagram']);
        $this->assertFalse($data['publishing_off']['facebook'], 'not connected at all');
        $this->assertFalse($data['publishing_off']['google_business'], 'connected Google Business is ready — no switch');
        $this->assertTrue($data['configured']['google_business']);
        $item = collect($data['automation']['items'])->firstWhere('platform', 'google_business');
        $this->assertTrue($item['configured']);
        $this->assertFalse($item['publishing_off']);
    }

    public function test_get_reports_the_seeded_default_site_in_fixed_order(): void
    {
        $this->fakeAllConfigured(true);

        $data = $this->getJson('/api/admin/v1/social-media', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame('America/Chicago', $data['automation']['timezone']);

        $items = $data['automation']['items'];
        $this->assertSame(['instagram', 'facebook', 'google_business'], array_column($items, 'platform'));

        foreach (SocialAutomationSetting::PLATFORMS as $i => $platform) {
            $item = $items[$i];
            $defaults = SocialAutomationSetting::DEFAULTS[$platform];

            $this->assertTrue($item['enabled'], "{$platform} should be seeded enabled");
            $this->assertTrue($item['configured']);
            $this->assertSame($defaults['cadence'], $item['cadence']);
            $this->assertSame($defaults['options'], $item['options']);
            $this->assertSame(SocialAutomationSetting::LABELS[$platform], $item['label']);
            $this->assertArrayHasKey('plan', $item);
            $this->assertArrayHasKey('slots', $item['plan']);
            $this->assertArrayHasKey('last', $item);
            $this->assertNotNull($item['updated_at']);
        }
    }

    public function test_a_site_with_no_rows_reports_disabled_defaults(): void
    {
        $this->fakeAllConfigured(false);

        $other = Site::where('slug', '!=', config('sites.default', 'gsc'))->firstOrFail();

        $items = Tenancy::for($other, fn () => app(AutomationSettingsService::class)->items());

        foreach (SocialAutomationSetting::PLATFORMS as $i => $platform) {
            $item = $items[$i];
            $this->assertFalse($item['enabled']);
            $this->assertFalse($item['configured']);
            $this->assertSame(SocialAutomationSetting::DEFAULTS[$platform]['cadence'], $item['cadence']);
            $this->assertNull($item['updated_at']);
        }
    }

    public function test_put_persists_and_echoes_the_saved_item(): void
    {
        $this->fakeAllConfigured(true);

        $payload = [
            'enabled' => false,
            'cadence' => [
                'per_week' => 3,
                'days' => [1, 3, 5],
                'window' => ['start' => '11:00', 'end' => '14:00'],
            ],
            'options' => ['location_tag' => false],
        ];

        $response = $this->putJson('/api/admin/v1/social-media/automation/instagram', $payload, $this->adminApiHeaders())
            ->assertOk();

        $response->assertJsonPath('data.enabled', false);
        $response->assertJsonPath('data.cadence.per_week', 3);
        $response->assertJsonPath('data.cadence.days', [1, 3, 5]);
        $response->assertJsonPath('data.cadence.window.start', '11:00');
        $response->assertJsonPath('data.options.location_tag', false);

        $fresh = SocialAutomationSetting::where('platform', 'instagram')->firstOrFail();
        $this->assertFalse($fresh->enabled);
        $this->assertSame(3, $fresh->cadence['per_week']);
        $this->assertSame([1, 3, 5], $fresh->cadence['days']);
        $this->assertFalse($fresh->options['location_tag']);
    }

    public function test_put_google_business_persists_themed_and_catch_up(): void
    {
        $this->fakeAllConfigured(true);

        $payload = [
            'enabled' => true,
            'cadence' => ['per_week' => 1, 'days' => null, 'window' => ['start' => '09:00', 'end' => '10:00']],
            'options' => ['themed' => false, 'catch_up_after_days' => 10],
        ];

        $this->putJson('/api/admin/v1/social-media/automation/google_business', $payload, $this->adminApiHeaders())
            ->assertOk()
            ->assertJsonPath('data.options.themed', false)
            ->assertJsonPath('data.options.catch_up_after_days', 10);

        $fresh = SocialAutomationSetting::where('platform', 'google_business')->firstOrFail();
        $this->assertSame(10, $fresh->options['catch_up_after_days']);
    }

    public function test_put_returns_404_for_an_unknown_platform(): void
    {
        $this->fakeAllConfigured(true);

        $payload = [
            'enabled' => true,
            'cadence' => ['per_week' => 1, 'days' => null, 'window' => ['start' => '09:00', 'end' => '10:00']],
            'options' => [],
        ];

        $this->putJson('/api/admin/v1/social-media/automation/tiktok', $payload, $this->adminApiHeaders())
            ->assertStatus(404);
    }

    public function test_enabling_an_unconfigured_platform_is_allowed_and_still_reports_unconfigured(): void
    {
        $this->fakeAllConfigured(false);

        $payload = [
            'enabled' => true,
            'cadence' => ['per_week' => 1, 'days' => null, 'window' => ['start' => '09:00', 'end' => '10:00']],
            'options' => [],
        ];

        $this->putJson('/api/admin/v1/social-media/automation/facebook', $payload, $this->adminApiHeaders())
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.configured', false);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidPayloads(): array
    {
        $base = ['per_week' => 2, 'days' => null, 'window' => ['start' => '09:00', 'end' => '19:00']];

        return [
            'per_week too high' => [array_merge($base, ['per_week' => 8]), 'cadence.per_week'],
            'per_week too low' => [array_merge($base, ['per_week' => 0]), 'cadence.per_week'],
            'day out of range' => [array_merge($base, ['days' => [0, 3]]), 'cadence.days.0'],
            'duplicate days' => [array_merge($base, ['days' => [2, 2]]), 'cadence.days.0'],
            'window end before start' => [array_merge($base, ['window' => ['start' => '18:00', 'end' => '09:00']]), 'cadence.window.end'],
            'window bad format' => [array_merge($base, ['window' => ['start' => '9am', 'end' => '19:00']]), 'cadence.window.start'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_put_validates_cadence(array $cadence, string $expectedErrorKey): void
    {
        $this->fakeAllConfigured(true);

        $this->putJson('/api/admin/v1/social-media/automation/instagram', [
            'enabled' => true,
            'cadence' => $cadence,
            'options' => [],
        ], $this->adminApiHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => [$expectedErrorKey]]);
    }

    public function test_put_validates_catch_up_after_days_bounds(): void
    {
        $this->fakeAllConfigured(true);

        $this->putJson('/api/admin/v1/social-media/automation/google_business', [
            'enabled' => true,
            'cadence' => ['per_week' => 1, 'days' => null, 'window' => ['start' => '09:00', 'end' => '10:00']],
            'options' => ['themed' => true, 'catch_up_after_days' => 1],
        ], $this->adminApiHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['options.catch_up_after_days']]);

        $this->putJson('/api/admin/v1/social-media/automation/google_business', [
            'enabled' => true,
            'cadence' => ['per_week' => 1, 'days' => null, 'window' => ['start' => '09:00', 'end' => '10:00']],
            'options' => ['themed' => true, 'catch_up_after_days' => 31],
        ], $this->adminApiHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['options.catch_up_after_days']]);
    }

    public function test_put_requires_enabled_and_cadence(): void
    {
        $this->fakeAllConfigured(true);

        $this->putJson('/api/admin/v1/social-media/automation/instagram', [], $this->adminApiHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['enabled', 'cadence']]);
    }
}
