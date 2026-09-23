<?php

namespace Tests\Feature;

use App\Jobs\DeleteGooglePlacesMedia;
use App\Jobs\UploadProjectImageToGooglePlaces;
use App\Jobs\UploadProjectImageToYelpBusinessPhotos;
use App\Models\ImagePlatformUpload;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Services\GoogleBusinessProfileService;
use App\Services\YelpBusinessService;
use App\Support\GbpPhotoOwnership;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * GBP_PHOTOS_OWNED_BY=ss-systems (2026-09-22): the central admin uploads
 * this site's Google Business Profile photos through the
 * platforms/gbp/media pass-through and keeps its own ledger, so every
 * site-side path that used to send them — the image and project
 * observers, the two queued jobs and the sync command — goes inert. The
 * gate sits in the jobs themselves so a stray dispatch from any caller
 * (commands, the AI caption job) still sends nothing. The pass-through
 * is NOT gated: it is how the central admin's uploads arrive.
 */
class GbpPhotosOwnedByCentralAdminTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            // Connected, so nothing but the ownership rule stops an upload here.
            'services.google.business_profile.client_id' => 'client-id',
            'services.google.business_profile.client_secret' => 'client-secret',
            'services.google.business_profile.refresh_token' => 'refresh-token',
            'services.google.business_profile.account_id' => 'accounts/1',
            'services.google.business_profile.location_id' => 'locations/2',
            'services.google.business_profile.photos_owned_by' => 'ss-systems',
            'services.google.business_profile.auto_geocode_on_project_save' => false,
            'services.google.gemini_api_key' => '',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function project(bool $published = true): Project
    {
        return Project::create([
            'title' => 'Kitchen Remodel',
            'slug' => 'kitchen-remodel-'.uniqid(),
            'project_type' => 'kitchen',
            'is_published' => $published,
            'location' => 'Palatine, IL',
        ]);
    }

    private function image(Project $project): ProjectImage
    {
        return ProjectImage::create([
            'project_id' => $project->id,
            'filename' => 'photo-'.uniqid().'.jpg',
            'original_filename' => 'photo.jpg',
            'path' => 'projects/1/photo.jpg',
            'alt_text' => 'A lovely kitchen',
        ]);
    }

    private function fakeYelpConfigured(): void
    {
        $yelp = Mockery::mock(YelpBusinessService::class);
        $yelp->shouldReceive('isConfigured')->andReturn(true);
        $this->app->instance(YelpBusinessService::class, $yelp);
    }

    public function test_the_switch_reads_from_config_and_defaults_to_this_site(): void
    {
        $this->assertFalse(GbpPhotoOwnership::ownedHere());

        config(['services.google.business_profile.photos_owned_by' => 'site']);
        $this->assertTrue(GbpPhotoOwnership::ownedHere());

        config(['services.google.business_profile.photos_owned_by' => null]);
        $this->assertTrue(GbpPhotoOwnership::ownedHere());
    }

    public function test_a_new_photo_on_a_published_project_is_not_queued_for_google_but_still_for_yelp(): void
    {
        $this->fakeYelpConfigured();
        $project = $this->project();

        Queue::fake();
        $image = $this->image($project);

        Queue::assertNotPushed(UploadProjectImageToGooglePlaces::class);
        Queue::assertPushed(UploadProjectImageToYelpBusinessPhotos::class, fn ($job) => $job->imageId === $image->id);
    }

    public function test_publishing_a_project_and_editing_a_caption_queue_nothing_for_google(): void
    {
        $this->fakeYelpConfigured();
        $project = $this->project(published: false);
        $image = $this->image($project);

        Queue::fake();
        $project->update(['is_published' => true]);
        $image->update(['caption' => 'A brighter kitchen with new quartz counters.']);

        Queue::assertNotPushed(UploadProjectImageToGooglePlaces::class);
        Queue::assertPushed(UploadProjectImageToYelpBusinessPhotos::class);
    }

    public function test_deleting_an_uploaded_photo_queues_no_google_removal(): void
    {
        $image = $this->image($this->project());
        ImagePlatformUpload::record($image->id, ImagePlatformUpload::PLATFORM_GOOGLE_PLACES, [
            'remote_id' => 'accounts/900/locations/111/media/abc',
        ]);

        Queue::fake();
        $image->fresh()->delete();

        Queue::assertNotPushed(DeleteGooglePlacesMedia::class);
    }

    public function test_the_jobs_themselves_send_nothing_whoever_dispatched_them(): void
    {
        $image = $this->image($this->project());
        ImagePlatformUpload::record($image->id, ImagePlatformUpload::PLATFORM_GOOGLE_PLACES, [
            'remote_id' => 'accounts/900/locations/111/media/abc',
        ]);

        $service = Mockery::mock(GoogleBusinessProfileService::class);
        $service->shouldReceive('isConfigured')->andReturn(true);
        $service->shouldNotReceive('uploadProjectImage');
        $service->shouldNotReceive('deleteMedia');

        (new UploadProjectImageToGooglePlaces($image->id, forceRefresh: true))->handle($service);
        (new DeleteGooglePlacesMedia('accounts/900/locations/111/media/abc'))->handle($service);

        $this->assertTrue(true);
    }

    public function test_the_site_side_sync_command_refuses_and_says_where_uploads_happen_now(): void
    {
        $service = Mockery::mock(GoogleBusinessProfileService::class);
        $service->shouldReceive('isConfigured')->andReturn(true);
        $service->shouldNotReceive('uploadProjectImage');
        $this->app->instance(GoogleBusinessProfileService::class, $service);

        $this->artisan('google-business-profile:sync')
            ->expectsOutputToContain('uploaded by the central admin')
            ->assertFailed();
    }

    public function test_the_central_admins_pass_through_still_uploads(): void
    {
        config(['services.admin_api.token' => 'test-admin-api-token']);
        $image = $this->image($this->project());

        $service = Mockery::mock(GoogleBusinessProfileService::class);
        $service->shouldReceive('hasRefreshToken')->once()->andReturn(true);
        $service->shouldReceive('getPublicImageUrl')->once()->andReturn('https://gs.construction/storage/projects/1/photo-gbp.jpg');
        $service->shouldReceive('mapCategory')->once()->andReturn('ADDITIONAL');
        $service->shouldReceive('buildDescription')->once()->andReturn('A lovely kitchen');
        $service->shouldReceive('uploadMediaFor')
            ->once()
            ->andReturn(['name' => 'accounts/900/locations/111/media/new', 'url' => 'https://lh3.googleusercontent.com/new=s0']);
        $this->app->instance(GoogleBusinessProfileService::class, $service);

        $this->postJson('/api/admin/v1/platforms/gbp/media', [
            'account_id' => '900',
            'location_id' => 'locations/111',
            'image_id' => $image->id,
        ], ['Authorization' => 'Bearer test-admin-api-token', 'Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.media_name', 'accounts/900/locations/111/media/new');

        $this->assertSame('accounts/900/locations/111/media/new', $image->fresh()->google_places_media_name);
    }
}
