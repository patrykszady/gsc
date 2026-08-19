<?php

namespace Tests\Feature\Api;

use App\Models\LeadFilterRule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * POST /api/lead-filters/sync — the receiving end of cross-site spam
 * learning (gsc <-> jpeterson). Applying an incoming rule must never
 * dispatch SyncLeadFilterToPeer itself, or a rule would bounce back and
 * forth between the two sites forever.
 */
class LeadFilterSyncControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.lead_filter_sync.token' => 'test-sync-token']);
    }

    protected function headers(): array
    {
        return ['Authorization' => 'Bearer test-sync-token'];
    }

    public function test_it_rejects_a_missing_token(): void
    {
        $this->postJson('/api/lead-filters/sync', ['action' => 'deny', 'email' => 'a@b.com'])
            ->assertStatus(401);
    }

    public function test_it_rejects_the_wrong_token(): void
    {
        $this->postJson('/api/lead-filters/sync', ['action' => 'deny', 'email' => 'a@b.com'], [
            'Authorization' => 'Bearer wrong-token',
        ])->assertStatus(401);
    }

    public function test_it_applies_a_deny_rule(): void
    {
        $this->postJson('/api/lead-filters/sync', [
            'action' => 'deny',
            'email' => 'spammer@example.com',
            'note' => 'blocked from jpeterson lead #9',
        ], $this->headers())->assertOk();

        $this->assertTrue(LeadFilterRule::matchDeny('spammer@example.com', null, null) !== null);
    }

    public function test_it_applies_an_allow_rule_and_clears_an_opposing_deny(): void
    {
        LeadFilterRule::query()->create(['action' => 'deny', 'match_type' => 'email', 'value' => 'friend@example.com']);

        $this->postJson('/api/lead-filters/sync', [
            'action' => 'allow',
            'email' => 'friend@example.com',
        ], $this->headers())->assertOk();

        $this->assertTrue(LeadFilterRule::isAllowed('friend@example.com', null, null));
        $this->assertNull(LeadFilterRule::matchDeny('friend@example.com', null, null));
    }

    public function test_applying_a_synced_rule_never_re_dispatches_the_propagation_job(): void
    {
        Queue::fake();

        $this->postJson('/api/lead-filters/sync', [
            'action' => 'deny',
            'email' => 'spammer@example.com',
        ], $this->headers())->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_it_rejects_an_invalid_action(): void
    {
        $this->postJson('/api/lead-filters/sync', [
            'action' => 'block', // not allow|deny
            'email' => 'a@b.com',
        ], $this->headers())->assertStatus(422);
    }
}
