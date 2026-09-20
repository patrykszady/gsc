<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Jobs\SendLeadToHive;
use App\Models\ContactSubmission;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * Pixel-parity restorations on the management-API lead surface: the
 * availability/Hive fields on the lead shape, the date_range filter, and
 * the stats() aggregate (5-card grid + Top Cities + Traffic Sources) the
 * legacy ContactSubmissions Livewire computed with GROUP BY.
 */
class LeadControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
    }

    protected function makeLead(array $overrides = []): ContactSubmission
    {
        // created_at is not mass-assignable (see ContactSubmission's
        // $fillable) — set it with forceFill so date_range tests can
        // backdate a row.
        $createdAt = $overrides['created_at'] ?? null;
        unset($overrides['created_at']);

        $lead = ContactSubmission::create(array_merge([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '555-1212',
            'message' => 'Looking for a quote.',
            'status' => 'pending',
            'city' => 'Chicago',
        ], $overrides));

        if ($createdAt !== null) {
            $lead->forceFill(['created_at' => $createdAt])->save();
        }

        return $lead;
    }

    public function test_lead_shape_carries_availability_and_hive_fields(): void
    {
        $lead = $this->makeLead([
            'availability' => [['date' => '2026-08-20', 'time' => '1-3 PM']],
            'hive_sent_at' => now(),
            'hive_lead_id' => 'hive-42',
        ]);

        $data = $this->getJson("/api/admin/v1/leads/{$lead->id}", $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame([['date' => '2026-08-20', 'time' => '1-3 PM']], $data['availability']);
        $this->assertTrue($data['was_sent_to_hive']);
        $this->assertNotNull($data['hive_sent_at']);
    }

    public function test_lead_without_hive_forwarding_reports_false(): void
    {
        $lead = $this->makeLead();

        $data = $this->getJson("/api/admin/v1/leads/{$lead->id}", $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertFalse($data['was_sent_to_hive']);
        $this->assertNull($data['hive_sent_at']);
    }

    public function test_lead_shape_carries_the_completed_address_parts(): void
    {
        $lead = $this->makeLead([
            'address' => '511 Sherwood Dr',
            'city' => 'Addison',
            'state' => 'IL',
            'zip' => '60101',
        ]);

        $data = $this->getJson("/api/admin/v1/leads/{$lead->id}", $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame('IL', $data['state']);
        $this->assertSame('60101', $data['zip']);
        $this->assertSame('511 Sherwood Dr, Addison, IL 60101', $data['formatted_address']);
        $this->assertNull($data['address_candidates']);
    }

    public function test_lead_shape_carries_a_street_only_field(): void
    {
        $lead = $this->makeLead([
            'address' => '2258 South 8th Avenue, North Riverside, IL 60546',
            'city' => 'Riverside',
        ]);

        $data = $this->getJson("/api/admin/v1/leads/{$lead->id}", $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame('2258 South 8th Avenue', $data['street']);
        // The stated city wins — it stays exactly what's stored, even though
        // the street's own tail says "North Riverside".
        $this->assertSame('Riverside', $data['city']);
    }

    public function test_lead_shape_carries_ambiguous_address_candidates(): void
    {
        $candidates = [
            ['address' => '511 Sherwood Dr', 'city' => 'Addison', 'state' => 'IL', 'zip_code' => '60101', 'miles' => 12.3],
            ['address' => '511 Sherwood Dr', 'city' => 'Streamwood', 'state' => 'IL', 'zip_code' => '60107', 'miles' => 13.4],
        ];
        $lead = $this->makeLead([
            'address' => '511 Sherwood Dr',
            'city' => null,
            'address_candidates' => $candidates,
        ]);

        $data = $this->getJson("/api/admin/v1/leads/{$lead->id}", $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame($candidates, $data['address_candidates']);
    }

    public function test_date_range_today_excludes_older_leads(): void
    {
        $this->makeLead(['created_at' => now()]);
        $this->makeLead(['created_at' => now()->subDays(10)]);

        $data = $this->getJson('/api/admin/v1/leads?date_range=today', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
    }

    public function test_date_range_all_returns_everything(): void
    {
        $this->makeLead(['created_at' => now()]);
        $this->makeLead(['created_at' => now()->subMonths(2)]);

        $data = $this->getJson('/api/admin/v1/leads?date_range=all', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data);
    }

    public function test_hive_can_push_a_lead_it_captured_and_a_second_push_updates_it(): void
    {
        Queue::fake();

        $payload = [
            'hive_lead_id' => 170,
            'source' => 'crew-email',
            'name' => 'Josh Simmons',
            'email' => 'jsims692@example.test',
            'address' => '6 Drake Terrace',
            'city' => 'Prospect Heights',
            'state' => 'IL',
            'zip' => '60070',
            'message' => 'I have a basement I need remodeling done.',
            'received_at' => '2026-09-15T20:06:27Z',
        ];

        $created = $this->postJson('/api/admin/v1/leads', $payload, $this->adminApiHeaders())
            ->assertCreated()
            ->json('data');

        $this->assertSame('Josh Simmons', $created['name']);
        $this->assertSame('crew-email', $created['source']);
        $this->assertSame('pending', $created['status']);
        $this->assertTrue($created['was_sent_to_hive']);

        $row = ContactSubmission::withoutSiteScope()->findOrFail($created['id']);
        $this->assertSame(170, $row->hive_lead_id);
        $this->assertTrue($row->created_at->equalTo(Carbon::parse('2026-09-15T20:06:27Z')));
        // Born forwarded: nothing sends it back to hive.
        Queue::assertNotPushed(SendLeadToHive::class);

        $this->postJson('/api/admin/v1/leads', ['phone' => '8475550100'] + $payload, $this->adminApiHeaders())->assertOk();
        $this->assertSame(1, ContactSubmission::withoutSiteScope()->where('hive_lead_id', 170)->count());
        $this->assertSame('8475550100', $row->fresh()->phone);

        // No token, no lead.
        $this->postJson('/api/admin/v1/leads', $payload)->assertUnauthorized();
    }

    public function test_hive_answering_an_email_this_site_read_first_updates_that_row(): void
    {
        Queue::fake();

        // Read out of crew@ here; hive has not answered yet, so no hive id.
        $mine = ContactSubmission::create([
            'name' => 'William Johnson', 'email' => 'willjohn1089@gmail.com', 'message' => 'Bathroom remodel',
            'source' => 'crew-email', 'status' => 'pending', 'email_message_id' => str_repeat('b', 40),
        ]);

        // Hive's mirror of the lead it made from that same email.
        $this->postJson('/api/admin/v1/leads', [
            'hive_lead_id' => 171,
            'source' => 'crew-email',
            'external_id' => str_repeat('b', 40),
            'subject' => 'Bathroom remodel',
            'name' => 'William Johnson',
            'email' => 'willjohn1089@gmail.com',
            'message' => 'Bathroom remodel',
        ], $this->adminApiHeaders())->assertOk();

        $this->assertSame(1, ContactSubmission::withoutSiteScope()->count());
        $this->assertSame(171, $mine->fresh()->hive_lead_id);
        $this->assertSame('Bathroom remodel', $mine->fresh()->subject);

        // A different email under the same source is its own row, carrying its identity.
        $created = $this->postJson('/api/admin/v1/leads', [
            'hive_lead_id' => 172, 'source' => 'crew-email', 'external_id' => str_repeat('c', 40),
            'name' => 'Toby Daisy', 'email' => 'toby@example.test', 'message' => 'Deck',
        ], $this->adminApiHeaders())->assertCreated()->json('data');
        $this->assertSame(str_repeat('c', 40), ContactSubmission::withoutSiteScope()->findOrFail($created['id'])->email_message_id);
    }

    public function test_source_filter_narrows_to_one_channel(): void
    {
        $this->makeLead(['source' => 'web']);
        $this->makeLead(['source' => 'yelp', 'email' => '']);
        $this->makeLead(['source' => 'yelp', 'email' => '']);

        $data = $this->getJson('/api/admin/v1/leads?source=yelp', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data);
        $this->assertSame(['yelp', 'yelp'], array_column($data, 'source'));

        // No source = every channel.
        $this->assertCount(3, $this->getJson('/api/admin/v1/leads', $this->adminApiHeaders())->json('data'));
    }

    public function test_stats_lists_every_source_with_its_count(): void
    {
        $this->makeLead(['source' => 'web']);
        $this->makeLead(['source' => 'web']);
        $this->makeLead(['source' => 'yelp', 'email' => '']);

        $data = $this->getJson('/api/admin/v1/leads/stats', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame([
            ['source' => 'web', 'count' => 2],
            ['source' => 'yelp', 'count' => 1],
        ], $data['sources']);
    }

    public function test_stats_returns_five_card_grid_and_aggregates(): void
    {
        $this->makeLead(['status' => 'spam', 'city' => 'Barrington', 'utm_source' => 'google']);
        $this->makeLead(['status' => 'spam', 'city' => 'Barrington', 'utm_source' => 'google']);
        $this->makeLead(['status' => 'legitimate', 'city' => 'Chicago', 'utm_source' => 'facebook']);

        $data = $this->getJson('/api/admin/v1/leads/stats', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame(3, $data['total']);
        $this->assertSame(2, $data['spam']);
        $this->assertSame(['label' => 'Barrington', 'count' => 2], $data['top_cities'][0]);
        $this->assertSame(['label' => 'google', 'count' => 2], $data['traffic_sources'][0]);
        $this->assertArrayHasKey('today', $data);
        $this->assertArrayHasKey('week', $data);
        $this->assertArrayHasKey('month', $data);
    }

    public function test_stats_counts_new_leads_since_a_moment_for_the_sidebar_badge(): void
    {
        $this->makeLead(['created_at' => now()->subDays(3)]);                                        // before: already seen
        $this->makeLead(['created_at' => now()->subHours(2)]);                                       // new
        $this->makeLead(['status' => 'spam', 'created_at' => now()->subHour()]);                     // new, but spam
        $this->makeLead(['status' => 'legitimate', 'created_at' => now()->subMinutes(5)]);           // new

        $since = urlencode(now()->subDay()->toIso8601String());
        $data = $this->getJson("/api/admin/v1/leads/stats?since={$since}", $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $data['new']);
        $this->assertSame(4, $data['total'], 'the rest of the grid is untouched by since');

        // Nothing asked, nothing answered — the Leads screen's own stats call.
        $this->assertNull($this->getJson('/api/admin/v1/leads/stats', $this->adminApiHeaders())->assertOk()->json('data.new'));

        // A moment the site cannot read is the same as none, never a 500.
        $this->assertNull($this->getJson('/api/admin/v1/leads/stats?since=not-a-moment', $this->adminApiHeaders())->assertOk()->json('data.new'));
    }
}
