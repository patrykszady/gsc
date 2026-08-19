<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\ContactSubmission;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
}
