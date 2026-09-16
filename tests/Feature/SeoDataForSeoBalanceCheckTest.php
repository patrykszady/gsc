<?php

namespace Tests\Feature;

use App\Console\Commands\SeoDataForSeoBalanceCheck;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The account has been funded exactly once ($51 total, no auto-reload); the
 * failure mode that matters is silent depletion, not overspend — every
 * seo:* command's own --budget guard already refuses to overspend. This
 * command is the daily tripwire: log the balance, warn (and cache it) once
 * it drops below config('seo.dataforseo.min_balance').
 */
class SeoDataForSeoBalanceCheckTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.dataforseo.login' => 'u', 'services.dataforseo.password' => 'p']);
        Cache::forget(SeoDataForSeoBalanceCheck::CACHE_KEY);
    }

    public function test_skips_quietly_when_dataforseo_is_not_configured(): void
    {
        config(['services.dataforseo.login' => '', 'services.dataforseo.password' => '']);

        $this->artisan('seo:dataforseo-balance-check')
            ->expectsOutputToContain('not configured')
            ->assertExitCode(0);

        $this->assertNull(Cache::get(SeoDataForSeoBalanceCheck::CACHE_KEY));
    }

    public function test_logs_the_balance_and_caches_it_when_comfortably_above_the_floor(): void
    {
        config(['seo.dataforseo.min_balance' => 10.0]);
        Http::fake(['*/appendix/user_data' => Http::response(['tasks' => [['result' => [['money' => ['balance' => 42.5]]]]]])]);
        Log::spy();

        $this->artisan('seo:dataforseo-balance-check')
            ->expectsOutputToContain('$42.50')
            ->assertExitCode(0);

        Log::shouldHaveReceived('info')->once();
        Log::shouldNotHaveReceived('warning');

        $cached = Cache::get(SeoDataForSeoBalanceCheck::CACHE_KEY);
        $this->assertSame(42.5, $cached['balance']);
        $this->assertFalse($cached['low']);
    }

    public function test_warns_and_flags_the_cache_row_once_the_balance_drops_below_the_floor(): void
    {
        config(['seo.dataforseo.min_balance' => 10.0]);
        Http::fake(['*/appendix/user_data' => Http::response(['tasks' => [['result' => [['money' => ['balance' => 4.25]]]]]])]);
        Log::spy();

        $this->artisan('seo:dataforseo-balance-check')
            ->expectsOutputToContain('below the $10.00 floor')
            ->assertExitCode(0);

        Log::shouldHaveReceived('warning')->once();

        $cached = Cache::get(SeoDataForSeoBalanceCheck::CACHE_KEY);
        $this->assertSame(4.25, $cached['balance']);
        $this->assertTrue($cached['low']);
    }

    public function test_a_failed_balance_check_fails_closed_instead_of_going_quiet(): void
    {
        // Silent depletion is exactly the failure mode this command exists
        // to catch; a check that can't reach the API must say so loudly
        // (non-zero exit, onFailure() fires) rather than exit clean.
        Http::fake(['*/appendix/user_data' => Http::response('', 500)]);
        Log::spy();

        $this->artisan('seo:dataforseo-balance-check')
            ->expectsOutputToContain('balance check failed')
            ->assertExitCode(1);

        Log::shouldHaveReceived('warning')->once();
        $this->assertNull(Cache::get(SeoDataForSeoBalanceCheck::CACHE_KEY));
    }
}
