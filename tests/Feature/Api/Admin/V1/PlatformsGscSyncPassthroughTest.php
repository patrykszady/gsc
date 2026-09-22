<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Jobs\RunSeoChannelSyncJob;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * POST platforms/gsc/sync — the central admin's "sync now" button: queues
 * seo:gsc-sync rather than waiting for the schedule's next three-hour tick.
 * Never touches Google itself; this only pins that the right command reaches
 * the queue, as this tenant, the same way PlatformsHouzzControllerTest pins
 * its own review-import passthrough.
 */
class PlatformsGscSyncPassthroughTest extends TestCase
{
    private function headers(): array
    {
        config(['services.admin_api.token' => 'test-admin-api-token']);

        return ['Authorization' => 'Bearer test-admin-api-token', 'Accept' => 'application/json'];
    }

    public function test_sync_now_queues_seo_gsc_sync_as_this_tenant(): void
    {
        Bus::fake();

        $this->postJson('/api/admin/v1/platforms/gsc/sync', [], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.queued', true);

        Bus::assertDispatched(
            RunSeoChannelSyncJob::class,
            fn (RunSeoChannelSyncJob $job) => $job->command === 'seo:gsc-sync' && $job->siteId !== null
        );
        Bus::assertDispatchedTimes(RunSeoChannelSyncJob::class, 1);
    }

    public function test_sync_now_requires_a_bearer_token(): void
    {
        $this->postJson('/api/admin/v1/platforms/gsc/sync')->assertUnauthorized();
    }
}
