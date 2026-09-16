<?php

namespace Tests\Feature;

use App\Jobs\SendLeadToHive;
use App\Models\ContactSubmission;
use App\Services\HiveProjectsClient;
use App\Services\YelpBusinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * yelp:sync-leads turns what the fetcher reports into contact submissions —
 * the record every lead starts as, so ss.systems lists it and hive gets it
 * the way a web-form lead does. The fetcher itself is mocked: these tests
 * hold the storage rules (pending status, Yelp link in the message, photos
 * copied to the public disk, one hive hand-off ever, updates in place).
 */
class SyncYelpLeadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
        Config::set('services.yelp.business.biz_id', '7qfyGNSNsR515XtaCfcayg');
    }

    /** One fetched lead as the script reports it, with a downloaded photo on disk. */
    private function fetchedLead(array $overrides = []): array
    {
        $dir = sys_get_temp_dir() . '/yelp-lead-' . uniqid();
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/att1', "\xFF\xD8\xFF fake jpeg");

        return array_replace_recursive([
            'encid' => 'TAVDmKCrNEZmO9PzrYerWA',
            'status' => 'NEW',
            'workflowStatus' => 'NEW',
            'createdAt' => '2026-09-14T20:46:44-05:00',
            'lastEventAt' => '2026-09-15T01:46:46Z',
            'location' => ['city' => 'Park Ridge', 'state' => 'IL'],
            'phone' => null,
            'conversationId' => 'p2EGxqUP1Ev5cwfaNg7uoA',
            'customer' => ['name' => 'Kevin R.', 'location' => 'Glenview, IL'],
            'project' => [
                'title' => 'Bathroom remodeling',
                'zip' => '60068',
                'urgency' => 'ASAP',
                'keywords' => ['Wooden window frame', 'Water damage'],
                'answers' => [
                    ['question' => 'When do you require this service?', 'answers' => ['As soon as possible']],
                    ['question' => "Are there any other details you'd like to share?", 'answers' => ['There is a wooden window frame and sill in our shower.']],
                ],
            ],
            'url' => 'https://biz.yelp.com/leads_center/7qfyGNSNsR515XtaCfcayg/leads/TAVDmKCrNEZmO9PzrYerWA',
            'attachments' => [['encid' => 'att1', 'file' => $dir . '/att1', 'mime' => 'image/jpeg', 'size' => 14]],
            'detail' => true,
        ], $overrides);
    }

    private function fakeFetch(array $leads): void
    {
        $this->mock(YelpBusinessService::class, function ($mock) use ($leads) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('fetchLeads')->once()->andReturn([
                'ok' => true,
                'listed' => count($leads),
                'fetched' => count(array_filter($leads, fn ($l) => $l['detail'] ?? false)),
                'leads' => $leads,
                'errors' => [],
            ]);
        });
    }

    public function test_a_fetched_lead_becomes_a_pending_submission_with_its_photo_and_goes_to_hive(): void
    {
        $this->fakeFetch([$this->fetchedLead()]);

        $this->artisan('yelp:sync-leads')->assertSuccessful();

        $s = ContactSubmission::withoutSiteScope()->where('yelp_lead_id', 'TAVDmKCrNEZmO9PzrYerWA')->firstOrFail();

        $this->assertSame('Kevin R.', $s->name);
        $this->assertSame('yelp', $s->source);
        $this->assertSame('pending', $s->status);
        $this->assertSame('Park Ridge', $s->city);
        $this->assertSame('IL', $s->state);
        $this->assertSame('60068', $s->zip);
        $this->assertNull($s->phone);
        $this->assertStringContainsString('Bathroom remodeling', $s->message);
        $this->assertStringContainsString('As soon as possible', $s->message);
        $this->assertStringContainsString('wooden window frame', $s->message);
        $this->assertStringContainsString('Reply on Yelp: https://biz.yelp.com/leads_center/', $s->message);
        $this->assertSame('p2EGxqUP1Ev5cwfaNg7uoA', $s->yelp_conversation_id);
        $this->assertSame('NEW', $s->yelp_status);
        $this->assertSame('2026-09-15T01:46:46Z', $s->yelp_last_event_at->toIso8601ZuluString());
        // Filed when Yelp received it (stored in the app timezone, so compare the instant).
        $this->assertTrue(
            $s->created_at->equalTo(\Illuminate\Support\Carbon::parse('2026-09-14T20:46:44-05:00')),
            'created_at was ' . $s->created_at->toIso8601String() . ' (app tz ' . config('app.timezone') . ')',
        );
        $this->assertCount(1, $s->attachments);
        $this->assertSame('att1', $s->attachments[0]['encid']);
        $this->assertSame("yelp-leads/{$s->id}/att1.jpg", $s->attachments[0]['path']);

        Storage::disk('public')->assertExists("yelp-leads/{$s->id}/att1.jpg");
        Queue::assertPushed(SendLeadToHive::class, 1);

        // The admin API carries the link and the photo URL.
        $api = $s->toApiArray();
        $this->assertSame('https://biz.yelp.com/leads_center/7qfyGNSNsR515XtaCfcayg/leads/TAVDmKCrNEZmO9PzrYerWA', $api['yelp_url']);
        $this->assertStringContainsString("yelp-leads/{$s->id}/att1.jpg", $api['attachments'][0]['url']);
    }

    public function test_a_known_lead_is_updated_in_place_keeps_its_photos_and_is_not_sent_to_hive_twice(): void
    {
        $this->fakeFetch([$this->fetchedLead()]);
        $this->artisan('yelp:sync-leads')->assertSuccessful();
        $first = ContactSubmission::withoutSiteScope()->where('yelp_lead_id', 'TAVDmKCrNEZmO9PzrYerWA')->firstOrFail();

        // Next run: the lead moved to Active with a second photo; the known
        // list handed to the fetcher carries the event time we stored.
        $this->mock(YelpBusinessService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('fetchLeads')->once()
                ->withArgs(fn (array $known) => ($known['TAVDmKCrNEZmO9PzrYerWA'] ?? null) === '2026-09-15T01:46:46Z')
                ->andReturnUsing(function () {
                    $lead = $this->fetchedLead(['workflowStatus' => 'ACTIVE', 'lastEventAt' => '2026-09-15T14:00:00Z']);
                    $dir = dirname($lead['attachments'][0]['file']);
                    file_put_contents($dir . '/att2', 'png');
                    $lead['attachments'][] = ['encid' => 'att2', 'file' => $dir . '/att2', 'mime' => 'image/png', 'size' => 3];

                    return ['ok' => true, 'listed' => 1, 'fetched' => 1, 'leads' => [$lead], 'errors' => []];
                });
        });

        $this->artisan('yelp:sync-leads')->assertSuccessful();

        $this->assertSame(1, ContactSubmission::withoutSiteScope()->where('source', 'yelp')->count());
        $s = $first->fresh();
        $this->assertSame('ACTIVE', $s->yelp_status);
        $this->assertSame('2026-09-15T14:00:00Z', $s->yelp_last_event_at->toIso8601ZuluString());
        $this->assertSame(['att1', 'att2'], collect($s->attachments)->pluck('encid')->all());
        $this->assertStringEndsWith('att2.png', $s->attachments[1]['path']);
        Queue::assertPushed(SendLeadToHive::class, 1);
    }

    public function test_unchanged_leads_are_left_alone_and_a_dry_run_writes_nothing(): void
    {
        $this->fakeFetch([
            $this->fetchedLead(['detail' => false, 'attachments' => []]),
            $this->fetchedLead(['encid' => 'NEWONE']),
        ]);

        $this->artisan('yelp:sync-leads', ['--dry-run' => true])
            ->expectsOutputToContain('1 created, 0 updated, 1 unchanged. (dry run')
            ->assertSuccessful();

        $this->assertSame(0, ContactSubmission::withoutSiteScope()->where('source', 'yelp')->count());
        Queue::assertNothingPushed();
    }

    public function test_a_dead_session_is_quiet_and_other_failures_are_reported(): void
    {
        $this->mock(YelpBusinessService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('fetchLeads')->once()->andReturn(['ok' => false, 'session_dead' => true, 'error' => 'not authenticated']);
        });
        $this->artisan('yelp:sync-leads')->expectsOutputToContain('not authenticated')->assertSuccessful();

        $this->mock(YelpBusinessService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('fetchLeads')->once()->andReturn(['ok' => false, 'error' => 'DataDome challenge on the leads page']);
        });
        $this->artisan('yelp:sync-leads')->expectsOutputToContain('DataDome')->assertFailed();
    }

    public function test_a_yelp_submission_is_forwarded_to_hive_with_yelp_as_its_source(): void
    {
        $s = ContactSubmission::create(['name' => 'Kevin R.', 'email' => '', 'message' => 'Bathroom', 'source' => 'yelp', 'status' => 'pending', 'yelp_lead_id' => 'X1']);
        $captured = null;
        $this->mock(HiveProjectsClient::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('submitLead')->once()->andReturnUsing(function (array $payload) use (&$captured) {
                $captured = $payload;

                return 77;
            });
        });

        (new SendLeadToHive($s->id))->handle(app(HiveProjectsClient::class));

        $this->assertSame('yelp', $captured['source']);
        $this->assertSame((string) $s->id, $captured['external_id']);
        $this->assertSame(77, $s->fresh()->hive_lead_id);
    }
}
