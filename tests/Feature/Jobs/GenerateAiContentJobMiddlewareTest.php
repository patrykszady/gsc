<?php

namespace Tests\Feature\Jobs;

use App\Jobs\GenerateAiContentJob;
use App\Models\Project;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\RateLimitedWithRedis;
use Tests\TestCase;

/**
 * The Gemini rate limiter must not need Redis unless the queue itself runs
 * on Redis. With the sync driver the job's middleware runs inline, so an
 * unconditional Redis limiter made every test that creates a project photo
 * depend on a live local Redis — the suite was green only while one
 * happened to be running.
 */
class GenerateAiContentJobMiddlewareTest extends TestCase
{
    public function test_the_limiter_is_cache_backed_unless_the_queue_runs_on_redis(): void
    {
        $job = new GenerateAiContentJob(new Project);

        config(['queue.default' => 'sync']);
        $this->assertInstanceOf(RateLimited::class, $job->middleware()[0]);
        $this->assertNotInstanceOf(RateLimitedWithRedis::class, $job->middleware()[0]);

        config(['queue.default' => 'redis']);
        $this->assertInstanceOf(RateLimitedWithRedis::class, $job->middleware()[0]);
    }
}
