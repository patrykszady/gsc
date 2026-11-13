<?php

namespace Tests\Feature\Console;

use App\Services\InstagramRemoteLoginService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * instagram:check-session, when the check cannot answer.
 *
 * Production, every night from at least 2026-11-08: "Instagram daily session
 * check could not run (script error)" and nothing else — while the check's
 * Chrome reopened ~100 saved tabs (110 processes, 5.8 GB within a minute) and
 * chrome:reap-orphans killed it. The launcher threw the script's stderr and
 * its exit status away and handed back a bare null, so the log could not say
 * which failure it was. It now says.
 *
 * HARD SAFETY: no test here starts node or Chrome. The "node" the launcher
 * runs is a three-line shell script that prints a canned answer.
 */
class InstagramCheckSessionTest extends TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->dir = sys_get_temp_dir().'/ig-check-session-test-'.getmypid().'-'.uniqid();
        mkdir($this->dir.'/profile', 0775, true);

        Config::set('services.instagram.remote_login.enabled', true);
        Config::set('services.instagram.user_data_dir', $this->dir.'/profile');
        // Until a test says otherwise the launcher runs the harmless `true`.
        Config::set('services.instagram.node_binary', 'true');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        @unlink($this->dir.'/fake-node');
        @unlink($this->dir.'/said.txt');
        @rmdir($this->dir.'/profile');
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** Stand in for `node scripts/instagram-check-session.mjs …`: print this, exit with that. */
    protected function scriptSays(string $stdout, int $exitCode): void
    {
        file_put_contents($this->dir.'/said.txt', $stdout);
        file_put_contents($this->dir.'/fake-node', "#!/bin/sh\ncat ".escapeshellarg($this->dir.'/said.txt')."\nexit {$exitCode}\n");
        chmod($this->dir.'/fake-node', 0755);

        Config::set('services.instagram.node_binary', $this->dir.'/fake-node');
    }

    public function test_a_browser_that_reopened_a_crowd_of_tabs_is_named_as_the_reason(): void
    {
        $this->scriptSays('{"ok":false,"reason":"session_restored","tabs":104,"error":"Chrome came up with 104 tabs from the profile\'s saved session; stopped before loading Instagram in them"}'."\n", 1);

        $service = app(InstagramRemoteLoginService::class);

        $this->assertNull($service->checkSession());
        $this->assertSame(
            "session_restored: Chrome came up with 104 tabs from the profile's saved session; stopped before loading Instagram in them",
            $service->lastCheckFailure(),
        );
    }

    public function test_a_script_stopped_at_the_time_limit_without_a_word_is_named_as_that(): void
    {
        $this->scriptSays('', 124);

        $service = app(InstagramRemoteLoginService::class);

        $this->assertNull($service->checkSession());
        $this->assertSame('no answer within 50 s — the script was stopped at the time limit', $service->lastCheckFailure());
    }

    public function test_a_script_that_died_without_a_word_reports_its_exit_code(): void
    {
        $this->scriptSays("Chrome noise that is not JSON\n", 3);

        $service = app(InstagramRemoteLoginService::class);

        $this->assertNull($service->checkSession());
        $this->assertSame('the script printed nothing and exited with code 3', $service->lastCheckFailure());
    }

    public function test_a_node_that_is_not_there_is_named(): void
    {
        Config::set('services.instagram.node_binary', $this->dir.'/no-such-node');

        $service = app(InstagramRemoteLoginService::class);

        $this->assertNull($service->checkSession());
        $this->assertSame('node could not be started (exit 127) — check INSTAGRAM_NODE_BINARY', $service->lastCheckFailure());
    }

    public function test_a_verdict_stands_even_when_the_script_then_ended_badly(): void
    {
        // The answer was printed, then closing the browser hung and the
        // script's own deadline ended it: still an answer.
        $this->scriptSays('{"ok":true,"authenticated":true,"username":"gs.construction.co","url":"https://www.instagram.com/"}'."\n", 124);

        $service = app(InstagramRemoteLoginService::class);

        $this->assertTrue($service->checkSession());
        $this->assertNull($service->lastCheckFailure());
    }

    public function test_logged_out_is_an_answer_and_an_earlier_failure_is_forgotten(): void
    {
        $service = app(InstagramRemoteLoginService::class);

        $this->scriptSays('', 124);
        $this->assertNull($service->checkSession());
        $this->assertNotNull($service->lastCheckFailure());

        $this->scriptSays('{"ok":true,"authenticated":false,"reason":"redirected_to_login","url":"https://www.instagram.com/accounts/login/"}'."\n", 0);
        $this->assertFalse($service->checkSession());
        $this->assertNull($service->lastCheckFailure());
    }

    public function test_the_command_fails_with_the_reason_as_its_last_line_and_in_the_log(): void
    {
        Log::spy();

        $remote = Mockery::mock(InstagramRemoteLoginService::class);
        $remote->shouldReceive('isEnabled')->andReturn(true);
        $remote->shouldReceive('checkSession')->once()->andReturn(null);
        $remote->shouldReceive('lastCheckFailure')->andReturn('session_restored: Chrome came up with 104 tabs');
        $this->app->instance(InstagramRemoteLoginService::class, $remote);

        $this->artisan('instagram:check-session')
            ->expectsOutputToContain('Instagram session check could not run: session_restored: Chrome came up with 104 tabs')
            ->assertExitCode(1)
            ->run();

        Log::shouldHaveReceived('warning')
            ->with('Instagram daily session check could not run: session_restored: Chrome came up with 104 tabs')
            ->once();

        $last = Cache::get('instagram.session.last_check');
        $this->assertNull($last['authenticated']);
        $this->assertSame('session_restored: Chrome came up with 104 tabs', $last['could_not_run']);
    }

    public function test_the_command_passes_quietly_when_the_session_is_alive(): void
    {
        $remote = Mockery::mock(InstagramRemoteLoginService::class);
        $remote->shouldReceive('isEnabled')->andReturn(true);
        $remote->shouldReceive('checkSession')->once()->andReturn(true);
        $remote->shouldNotReceive('lastCheckFailure');
        $this->app->instance(InstagramRemoteLoginService::class, $remote);

        $this->artisan('instagram:check-session')
            ->expectsOutputToContain('Instagram session: authenticated')
            ->assertExitCode(0)
            ->run();

        $last = Cache::get('instagram.session.last_check');
        $this->assertTrue($last['authenticated']);
        $this->assertNull($last['could_not_run']);
    }
}
