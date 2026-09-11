<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Jobs\RunSeoChannelSyncJob;
use App\Models\AreaServed;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * Pixel-parity restorations on the management-API area surface:
 * latitude/longitude round-trip (legacy AreaForm's map fields / AreaList's
 * Coordinates column), and the gbp:geocode-areas queue dispatch legacy's
 * AreaForm::save() triggered whenever a saved row is missing coordinates.
 *
 * Queue::fake() throughout — see task safety note: this dispatch must never
 * run live in tests.
 */
class AreaControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
    }

    public function test_store_and_show_round_trip_coordinates(): void
    {
        Queue::fake();

        $data = $this->postJson('/api/admin/v1/areas', [
            'name' => 'Testville',
            'latitude' => 42.05,
            'longitude' => -87.9,
        ], $this->adminApiHeaders())
            ->assertCreated()
            ->json('data');

        $this->assertSame(42.05, $data['latitude']);
        $this->assertSame(-87.9, $data['longitude']);

        Queue::assertNotPushed(RunSeoChannelSyncJob::class);
    }

    public function test_saving_area_without_coordinates_queues_geocoder(): void
    {
        Queue::fake();

        $this->postJson('/api/admin/v1/areas', [
            'name' => 'No Coords Town',
        ], $this->adminApiHeaders())->assertCreated();

        Queue::assertPushed(RunSeoChannelSyncJob::class, function ($job) {
            return $job->command === 'gbp:geocode-areas';
        });
    }

    public function test_update_with_coordinates_does_not_queue_geocoder(): void
    {
        Queue::fake();

        $area = AreaServed::create(['city' => 'Coordless', 'slug' => 'coordless']);

        $this->putJson("/api/admin/v1/areas/{$area->id}", [
            'name' => 'Coordless',
            'latitude' => 41.9,
            'longitude' => -87.6,
        ], $this->adminApiHeaders())->assertOk();

        Queue::assertNotPushed(RunSeoChannelSyncJob::class);
    }

    /**
     * Mirrors jpeterson-design's AreaControllerTest::test_the_extra_copy_fields_faq_and_section_switches_round_trip
     * — same shared backbone (AreaServed::SECTIONS), adapted for this app's
     * "name" → "city" mapping and areas_served table.
     */
    public function test_the_extra_copy_fields_faq_and_section_switches_round_trip(): void
    {
        Queue::fake();

        $area = AreaServed::create(['city' => 'Decatur', 'slug' => 'decatur']);

        $data = $this->putJson('/api/admin/v1/areas/'.$area->id, [
            'name' => 'Decatur',
            'latitude' => 41.9,
            'longitude' => -87.6,
            'neighborhoods' => 'Oakhurst, Winnona Park',
            'popular_projects' => 'Kitchens.',
            'how_we_work' => 'From Arlington Heights.',
            'faq' => [['question' => 'Do you work here?', 'answer' => 'Yes.'], ['question' => '', 'answer' => '']],
            'sections' => ['faq' => false, 'intro' => '1', 'bogus' => true],
        ], $this->adminApiHeaders())->assertOk()->json('data');

        $this->assertSame('Oakhurst, Winnona Park', $data['neighborhoods']);
        $this->assertSame([['question' => 'Do you work here?', 'answer' => 'Yes.']], $data['faq'], 'blank FAQ rows are dropped');
        $this->assertFalse($data['sections']['faq'], 'explicitly switched off');
        $this->assertTrue($data['sections']['intro'], 'explicitly switched on');
        $this->assertFalse($data['sections']['landmarks'], 'untouched section with no content defaults to hidden');
        $this->assertArrayNotHasKey('bogus', $data['sections']);
        $this->assertSame(array_keys(AreaServed::SECTIONS), array_keys($data['section_labels']));
        $this->assertSame($area->fresh()->url, $data['public_url']);
    }

    public function test_a_section_with_content_and_no_explicit_switch_shows_by_default(): void
    {
        $area = AreaServed::create(['city' => 'Wheeling', 'slug' => 'wheeling', 'landmarks' => 'Heritage Park']);

        $this->assertTrue($area->showsSection('landmarks'), 'has content, no switch stored: defaults on');
        $this->assertFalse($area->showsSection('intro'), 'no content: defaults off regardless of switch');
    }
}
