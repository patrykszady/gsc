<?php

namespace Tests\Feature;

use App\Services\DataForSeoService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * DataForSeoService::$lastError used to be set-only — it was set on failure
 * but never cleared back to null on success, so a caller relying on
 * "lastError is null after a call" to mean success would misread a
 * successful call that followed a failed one. call() and
 * googleOrganicPosition() (the other request path that sets it — see
 * recordTask()'s docblock) now reset it to null at the very start of every
 * request, so its value always belongs to the call that just ran.
 */
class SeoDataForSeoLastErrorResetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.dataforseo.login' => 'u', 'services.dataforseo.password' => 'p']);
    }

    public function test_last_error_is_null_after_a_successful_call_that_followed_a_failed_one(): void
    {
        $dfs = app(DataForSeoService::class);

        // call() wraps this endpoint in ->retry(2, ...): a failing response is
        // retried once before giving up, so ONE failed logical call consumes
        // two sequence slots. A Http::sequence scoped to this one endpoint —
        // not a bare closure — also keeps an unrelated request elsewhere in
        // the boot/request cycle from ever consuming a slot meant here.
        Http::fake([
            '*/appendix/user_data' => Http::sequence()
                ->push('', 500)
                ->push('', 500)
                ->push(['tasks' => [['result' => [['money' => ['balance' => 12.5]]]]]]),
        ]);

        $this->assertNull($dfs->balance());
        $this->assertNotNull($dfs->getLastError());

        $this->assertSame(12.5, $dfs->balance());
        $this->assertNull($dfs->getLastError(), 'a successful call must clear a previous failure, not leave it stale');
    }

    public function test_last_error_reflects_only_the_call_that_just_ran_for_google_organic_position(): void
    {
        $dfs = app(DataForSeoService::class);

        $failingTask = ['cost' => 0, 'status_code' => 40501, 'status_message' => 'Invalid Field: keyword.'];
        $okTask = ['cost' => 0.002, 'status_code' => 20000, 'result' => [['items' => []]]];
        Http::fake([
            '*/serp/google/organic/live/advanced' => Http::sequence()
                ->push(['tasks' => [$failingTask]])
                ->push(['tasks' => [$okTask]]),
        ]);

        $this->assertNull($dfs->googleOrganicPosition('kitchen remodeling', 'gs.construction'));
        $this->assertNotNull($dfs->getLastError());

        $this->assertNotNull($dfs->googleOrganicPosition('kitchen remodeling', 'gs.construction'));
        $this->assertNull($dfs->getLastError(), 'a successful check must clear the previous failure');
    }
}
