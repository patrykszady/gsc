<?php

namespace Tests\Feature;

use App\Services\GoogleBusinessProfileService;
use Mockery;
use Tests\TestCase;

class GbpUnrespondedReviewsCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_returns_success_when_unresponded_reviews_exist(): void
    {
        $service = Mockery::mock(GoogleBusinessProfileService::class);
        $service->shouldReceive('isConfigured')->once()->andReturn(true);
        $service->shouldReceive('fetchAllReviews')->once()->andReturn([
            [
                'name' => 'accounts/1/locations/1/reviews/abc',
                'createTime' => now()->subHours(48)->toIso8601String(),
                'updateTime' => now()->subHours(48)->toIso8601String(),
                'reviewer' => ['displayName' => 'Jane Doe'],
                'starRating' => 'FIVE',
                'comment' => 'Great work',
            ],
        ]);

        $this->app->instance(GoogleBusinessProfileService::class, $service);

        $this->artisan('gbp:unresponded-reviews --max-age=24')
            ->assertExitCode(0);
    }

    public function test_it_returns_success_when_fetch_warning_occurs(): void
    {
        $service = Mockery::mock(GoogleBusinessProfileService::class);
        $service->shouldReceive('isConfigured')->once()->andReturn(true);
        $service->shouldReceive('fetchAllReviews')->once()->andReturn([]);
        $service->shouldReceive('getLastError')->once()->andReturn([
            'message' => 'Token refresh blocked: re-authorization required',
            'reauthorization_required' => true,
        ]);

        $this->app->instance(GoogleBusinessProfileService::class, $service);

        $this->artisan('gbp:unresponded-reviews --max-age=24')
            ->expectsOutputToContain('Fetch warning: Token refresh blocked: re-authorization required')
            ->assertExitCode(0);
    }
}
