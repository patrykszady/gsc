<?php

namespace Tests\Feature;

use App\Jobs\RefreshPublicFeedsJob;
use Hszope\LaravelAigeo\Modules\LlmsTxt\LlmsTxtGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Regenerating the feeds must never write the geo package's hour-old
 * cached llms render back to disk: the job busts that cache first, and
 * the deploy script reaches the job through public-feeds:refresh.
 */
class PublicFeedsRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_job_busts_the_cached_llms_render_before_regenerating(): void
    {
        Cache::put('geo:llms-txt', 'stale text', 3600);
        Cache::put('geo:llms-full-txt', 'stale full text', 3600);

        $this->assertInstanceOf(LlmsTxtGenerator::class, app(LlmsTxtGenerator::class));

        Artisan::shouldReceive('call')->times(count(RefreshPublicFeedsJob::COMMANDS))->andReturnUsing(function () {
            // By the time the first command runs, the cache is already gone.
            $this->assertNull(Cache::get('geo:llms-txt'));
            $this->assertNull(Cache::get('geo:llms-full-txt'));

            return 0;
        });

        (new RefreshPublicFeedsJob)->handle();
    }

    public function test_the_refresh_command_runs_the_same_job(): void
    {
        $this->mock(RefreshPublicFeedsJob::class)->shouldReceive('handle')->once();

        $this->artisan('public-feeds:refresh')->assertSuccessful()->expectsOutputToContain('geo:llms-txt');
    }
}
