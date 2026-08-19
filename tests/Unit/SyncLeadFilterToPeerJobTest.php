<?php

namespace Tests\Unit;

use App\Jobs\SyncLeadFilterToPeer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SyncLeadFilterToPeer's own HTTP behaviour, in isolation. Always
 * Http::fake() — this must never reach a real peer from a test.
 */
class SyncLeadFilterToPeerJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.lead_filter_sync.token' => 'shared-secret',
            'services.lead_filter_sync.peer_url' => 'http://jpeterson.test',
        ]);
    }

    public function test_it_posts_the_signals_to_the_peers_sync_endpoint(): void
    {
        Http::fake(['jpeterson.test/api/lead-filters/sync' => Http::response(['data' => ['applied' => true]], 200)]);

        (new SyncLeadFilterToPeer('deny', 'spammer@example.com', '5551212', '1.2.3.4', 'blocked from gsc lead #9'))
            ->handle();

        Http::assertSent(function ($request) {
            return $request->url() === 'http://jpeterson.test/api/lead-filters/sync'
                && $request->hasHeader('Authorization', 'Bearer shared-secret')
                && $request['action'] === 'deny'
                && $request['email'] === 'spammer@example.com'
                && $request['phone'] === '5551212'
                && $request['ip'] === '1.2.3.4';
        });
    }

    public function test_a_non_2xx_response_is_logged_not_thrown(): void
    {
        Http::fake(['jpeterson.test/*' => Http::response(['message' => 'nope'], 500)]);

        // Must not throw — a failed sync can never surface as a failure of
        // the mark action that triggered it.
        (new SyncLeadFilterToPeer('allow', 'a@b.com', null, null, null))->handle();

        $this->addToAssertionCount(1);
    }

    public function test_it_no_ops_when_the_peer_is_not_configured(): void
    {
        config(['services.lead_filter_sync.peer_url' => '', 'services.lead_filter_sync.token' => '']);
        Http::fake();

        (new SyncLeadFilterToPeer('deny', 'a@b.com', null, null, null))->handle();

        Http::assertNothingSent();
    }

    public function test_a_connection_failure_is_swallowed(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        (new SyncLeadFilterToPeer('deny', 'a@b.com', null, null, null))->handle();

        $this->addToAssertionCount(1);
    }
}
