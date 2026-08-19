<?php

namespace Tests\Feature;

use App\Jobs\SyncLeadFilterToPeer;
use App\Models\ContactSubmission;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * markAsReal()/markAsSpam() dispatch SyncLeadFilterToPeer so the sender is
 * treated the same way on jpeterson. Queue::fake() throughout — never a
 * real HTTP call to the peer from a test.
 */
class LeadFilterSyncPropagationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function makeLead(array $overrides = []): ContactSubmission
    {
        return ContactSubmission::create(array_merge([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '5551212',
            'message' => 'Looking for a quote.',
            'status' => 'pending',
        ], $overrides));
    }

    public function test_marking_spam_dispatches_a_deny_sync_to_the_peer(): void
    {
        Queue::fake();

        $lead = $this->makeLead();
        $lead->markAsSpam();

        Queue::assertPushed(SyncLeadFilterToPeer::class, function (SyncLeadFilterToPeer $job) {
            return $job->action === 'deny' && $job->email === 'jane@example.com';
        });
    }

    public function test_marking_real_dispatches_an_allow_sync_to_the_peer(): void
    {
        Queue::fake();

        $lead = $this->makeLead(['status' => 'spam', 'spam_reason' => 'blocklisted']);
        $lead->markAsReal();

        Queue::assertPushed(SyncLeadFilterToPeer::class, function (SyncLeadFilterToPeer $job) {
            return $job->action === 'allow' && $job->email === 'jane@example.com';
        });
    }
}
