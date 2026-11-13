<?php

namespace Tests\Feature;

use App\Services\GoogleBusinessProfileService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
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

    private function serviceWithOneNewUnansweredReview(): void
    {
        $service = Mockery::mock(GoogleBusinessProfileService::class);
        $service->shouldReceive('isConfigured')->once()->andReturn(true);
        $service->shouldReceive('fetchAllReviews')->once()->andReturn([
            [
                'name' => 'accounts/1/locations/1/reviews/new',
                'createTime' => now()->subHours(2)->toIso8601String(),
                'updateTime' => now()->subHours(2)->toIso8601String(),
                'reviewer' => ['displayName' => 'Jane Doe'],
                'starRating' => 'FIVE',
                'comment' => 'Great work',
            ],
        ]);

        $this->app->instance(GoogleBusinessProfileService::class, $service);
    }

    public function test_the_alert_goes_to_the_configured_owner_address(): void
    {
        Mail::fake();
        Cache::flush();
        Config::set('mail.review_alert_to', 'owner@example.com');
        Config::set('mail.from.address', 'noreply@example.com');
        $this->serviceWithOneNewUnansweredReview();

        $this->artisan('gbp:unresponded-reviews --max-age=24 --notify --notify-recent-hours=24')
            ->expectsOutputToContain('Alert email sent to owner@example.com.')
            ->assertExitCode(0);
    }

    public function test_the_alert_address_is_never_read_from_env_at_run_time(): void
    {
        // The deploy caches config, so env() answers null in production: an
        // address present only in the environment must change nothing here.
        // config/mail.php is what carries REVIEW_ALERT_TO.
        Mail::fake();
        Cache::flush();
        Config::set('mail.review_alert_to', null);
        Config::set('mail.from.address', 'noreply@example.com');
        $this->serviceWithOneNewUnansweredReview();

        $_ENV['REVIEW_ALERT_TO'] = $_SERVER['REVIEW_ALERT_TO'] = 'env-only@example.com';
        putenv('REVIEW_ALERT_TO=env-only@example.com');

        try {
            $this->artisan('gbp:unresponded-reviews --max-age=24 --notify --notify-recent-hours=24')
                ->expectsOutputToContain('Alert email sent to noreply@example.com.')
                ->assertExitCode(0);
        } finally {
            unset($_ENV['REVIEW_ALERT_TO'], $_SERVER['REVIEW_ALERT_TO']);
            putenv('REVIEW_ALERT_TO');
        }
    }
}
