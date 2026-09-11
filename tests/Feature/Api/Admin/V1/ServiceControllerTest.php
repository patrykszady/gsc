<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Jobs\GenerateServiceContentJob;
use App\Models\Project;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * The services list: one admin-managed vocabulary behind the project
 * form's "Project Type" and the landing-page generator. A project stores a
 * service's slug, so the two guards here matter — a slug in use cannot
 * change, and a service in use cannot be deleted.
 */
class ServiceControllerTest extends TestCase
{
    use RefreshDatabase, WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
    }

    public function test_it_lists_services_in_order_with_their_project_counts(): void
    {
        Service::create(['name' => 'Second', 'sort_order' => 2]);
        Service::create(['name' => 'First', 'sort_order' => 1]);
        Project::create(['title' => 'A kitchen', 'project_type' => 'first']);

        $data = $this->getJson('/api/admin/v1/services', $this->adminApiHeaders())->assertOk()->json('data');

        $this->assertSame(['First', 'Second'], array_column($data, 'name'));
        $this->assertSame(1, $data[0]['projects_count']);
        $this->assertSame(0, $data[1]['projects_count']);
    }

    public function test_a_new_service_gets_a_slug_and_goes_last(): void
    {
        Bus::fake();
        Service::create(['name' => 'Existing']);

        $data = $this->postJson('/api/admin/v1/services', ['name' => 'Window Treatments'], $this->adminApiHeaders())
            ->assertCreated()->json('data');

        $this->assertSame('window-treatments', $data['slug']);
        $this->assertSame(2, $data['sort_order']);
        $this->assertTrue($data['is_landing_page']);

        // A clashing name still gets a unique slug.
        $again = $this->postJson('/api/admin/v1/services', ['name' => 'Window Treatments'], $this->adminApiHeaders())
            ->assertCreated()->json('data');
        $this->assertSame('window-treatments-2', $again['slug']);
    }

    /** Every new service gets its copy drafted at once, like a new area. */
    public function test_store_dispatches_the_content_job(): void
    {
        Bus::fake();

        $created = $this->postJson('/api/admin/v1/services', ['name' => 'Window Treatments'], $this->adminApiHeaders())
            ->assertCreated()->json('data');

        $this->assertTrue($created['generating']);
        Bus::assertDispatchedAfterResponse(GenerateServiceContentJob::class, fn (GenerateServiceContentJob $job) => $job->serviceId === $created['id'] && $job->force === false);
    }

    public function test_renaming_is_always_allowed_but_a_slug_in_use_is_not(): void
    {
        $service = Service::create(['name' => 'Kitchen', 'slug' => 'kitchen']);
        Project::create(['title' => 'A kitchen', 'project_type' => 'kitchen']);

        $this->putJson("/api/admin/v1/services/{$service->id}", ['name' => 'Kitchen & Bath Design'], $this->adminApiHeaders())
            ->assertOk()
            ->assertJsonPath('data.name', 'Kitchen & Bath Design')
            ->assertJsonPath('data.slug', 'kitchen');

        $this->putJson("/api/admin/v1/services/{$service->id}", ['slug' => 'kitchens'], $this->adminApiHeaders())
            ->assertStatus(422)
            ->assertJsonPath('errors.slug.0', 'In use by 1 project(s).');

        $this->assertSame('kitchen', $service->fresh()->slug);
    }

    public function test_a_service_in_use_cannot_be_deleted(): void
    {
        $used = Service::create(['name' => 'Kitchen', 'slug' => 'kitchen']);
        $unused = Service::create(['name' => 'Colour', 'slug' => 'colour']);
        Project::create(['title' => 'A kitchen', 'project_type' => 'kitchen']);

        $this->deleteJson("/api/admin/v1/services/{$used->id}", [], $this->adminApiHeaders())->assertStatus(422);
        $this->assertModelExists($used);

        $this->deleteJson("/api/admin/v1/services/{$unused->id}", [], $this->adminApiHeaders())->assertNoContent();
        $this->assertModelMissing($unused);
    }

    public function test_reorder_renumbers_the_whole_list(): void
    {
        $a = Service::create(['name' => 'A', 'sort_order' => 1]);
        $b = Service::create(['name' => 'B', 'sort_order' => 2]);
        $c = Service::create(['name' => 'C', 'sort_order' => 3]);

        $data = $this->postJson('/api/admin/v1/services/reorder', ['order' => [$c->id, $a->id, $b->id]], $this->adminApiHeaders())
            ->assertOk()->json('data');

        $this->assertSame(['C', 'A', 'B'], array_column($data, 'name'));
    }

    /** The project form's vocabulary is this list. */
    public function test_project_types_come_from_the_services_table(): void
    {
        Service::create(['name' => 'Kitchen & Bath Design', 'slug' => 'kitchen', 'sort_order' => 1]);
        Service::create(['name' => 'Window Treatments', 'sort_order' => 2]);

        $data = $this->getJson('/api/admin/v1/projects/types', $this->adminApiHeaders())->assertOk()->json('data');

        $this->assertSame(['kitchen' => 'Kitchen & Bath Design', 'window-treatments' => 'Window Treatments'], $data['project_types']);
    }

    public function test_it_shows_a_single_service_with_its_content_fields(): void
    {
        $service = Service::create(['name' => 'Kitchen', 'slug' => 'kitchen', 'intro' => 'We design kitchens.']);

        $data = $this->getJson("/api/admin/v1/services/{$service->id}", $this->adminApiHeaders())
            ->assertOk()->json('data');

        $this->assertSame('Kitchen', $data['name']);
        $this->assertSame('We design kitchens.', $data['intro']);
        $this->assertSame(array_keys(Service::SECTIONS), array_keys($data['section_labels']));
        $this->assertSame($service->url(), $data['public_url']);
        $this->assertFalse($data['generating']);
        $this->assertNull($data['generation_error']);
    }

    /**
     * Mirrors AreaControllerTest::test_the_extra_copy_fields_faq_and_section_switches_round_trip
     * — same shared backbone (Service::SECTIONS), same normalisation rules.
     */
    public function test_the_content_fields_faq_and_section_switches_round_trip(): void
    {
        $service = Service::create(['name' => 'Kitchen', 'slug' => 'kitchen']);

        $data = $this->putJson('/api/admin/v1/services/'.$service->id, [
            'what_we_do' => 'We design and document your kitchen.',
            'faq' => [['question' => 'Do you build it?', 'answer' => 'No, your contractor does.'], ['question' => '', 'answer' => '']],
            'sections' => ['faq' => false, 'intro' => '1', 'bogus' => true],
        ], $this->adminApiHeaders())->assertOk()->json('data');

        $this->assertSame('We design and document your kitchen.', $data['what_we_do']);
        $this->assertSame([['question' => 'Do you build it?', 'answer' => 'No, your contractor does.']], $data['faq'], 'blank FAQ rows are dropped');
        $this->assertFalse($data['sections']['faq'], 'explicitly switched off');
        $this->assertTrue($data['sections']['intro'], 'explicitly switched on');
        $this->assertFalse($data['sections']['ideal_for'], 'untouched section with no content defaults to hidden');
        $this->assertArrayNotHasKey('bogus', $data['sections']);
        $this->assertSame(array_keys(Service::SECTIONS), array_keys($data['section_labels']));
    }

    public function test_generate_dispatches_the_content_job_after_the_response_and_returns_202(): void
    {
        Bus::fake();

        $service = Service::create(['name' => 'Kitchen', 'slug' => 'kitchen']);

        $this->postJson("/api/admin/v1/services/{$service->id}/generate", [], $this->adminApiHeaders())
            ->assertStatus(202)
            ->assertJsonPath('data.id', $service->id);

        Bus::assertDispatchedAfterResponse(GenerateServiceContentJob::class, function (GenerateServiceContentJob $job) use ($service) {
            return $job->serviceId === $service->id && $job->force === false;
        });
    }

    public function test_generate_with_force_passes_it_through_to_the_job(): void
    {
        Bus::fake();

        $service = Service::create(['name' => 'Kitchen', 'slug' => 'kitchen', 'intro' => 'Existing.']);

        $this->postJson("/api/admin/v1/services/{$service->id}/generate?force=1", [], $this->adminApiHeaders())
            ->assertStatus(202);

        Bus::assertDispatchedAfterResponse(GenerateServiceContentJob::class, fn (GenerateServiceContentJob $job) => $job->force === true);
    }
}
