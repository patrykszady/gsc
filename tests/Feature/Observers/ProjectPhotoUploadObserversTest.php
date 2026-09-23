<?php

namespace Tests\Feature\Observers;

use App\Jobs\UploadProjectImageToGooglePlaces;
use App\Jobs\UploadProjectImageToYelpBusinessPhotos;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Services\YelpBusinessService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Google Business Photos and Yelp Business Photos are event-driven, not
 * schedule-driven: a photo uploads to both the moment it lands on a
 * published project (ProjectImageObserver::created), or the moment its
 * project flips to published (ProjectObserver::updated) — see
 * App\Jobs\UploadProjectImageToGooglePlaces and
 * App\Jobs\UploadProjectImageToYelpBusinessPhotos. Neither platform has
 * automation settings; this is the gap-closing coverage for that.
 */
class ProjectPhotoUploadObserversTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Google Business connected (a grant and a listing) or not. There is no
     * separate publishing switch any more (2026-09-23): the uploads follow
     * the connection itself.
     */
    protected function connectGbp(bool $connected): void
    {
        config($connected ? [
            'services.google.business_profile.client_id' => 'client-id',
            'services.google.business_profile.client_secret' => 'client-secret',
            'services.google.business_profile.refresh_token' => 'refresh-token',
            'services.google.business_profile.account_id' => 'accounts/1',
            'services.google.business_profile.location_id' => 'locations/2',
        ] : ['services.google.business_profile.location_id' => null, 'services.google.business_profile.refresh_token' => null]);
    }

    protected function fakeYelpConfigured(bool $configured): void
    {
        $service = Mockery::mock(YelpBusinessService::class);
        $service->shouldReceive('isConfigured')->andReturn($configured);
        $this->app->instance(YelpBusinessService::class, $service);
    }

    protected function project(bool $published): Project
    {
        return Project::create([
            'title' => 'Test Project',
            'slug' => 'test-project-'.uniqid(),
            'project_type' => 'kitchen',
            'is_published' => $published,
            'location' => 'Elsewhere, IL',
        ]);
    }

    protected function image(Project $project): ProjectImage
    {
        return ProjectImage::create([
            'project_id' => $project->id,
            'filename' => 'photo-'.uniqid().'.jpg',
            'original_filename' => 'photo.jpg',
            'path' => 'projects/1/photo.jpg',
            'alt_text' => 'A lovely kitchen',
        ]);
    }

    public function test_image_created_on_a_published_project_dispatches_both_platforms(): void
    {
        $this->connectGbp(true);
        $this->fakeYelpConfigured(true);

        $project = $this->project(published: true);

        Queue::fake();

        $image = $this->image($project);

        Queue::assertPushed(UploadProjectImageToGooglePlaces::class, fn ($job) => $job->imageId === $image->id);
        Queue::assertPushed(UploadProjectImageToYelpBusinessPhotos::class, fn ($job) => $job->imageId === $image->id);
    }

    public function test_image_created_on_an_unpublished_project_dispatches_neither(): void
    {
        $this->connectGbp(true);
        $this->fakeYelpConfigured(true);

        $project = $this->project(published: false);

        Queue::fake();

        $this->image($project);

        Queue::assertNotPushed(UploadProjectImageToGooglePlaces::class);
        Queue::assertNotPushed(UploadProjectImageToYelpBusinessPhotos::class);
    }

    public function test_project_publish_dispatches_pending_images_on_both_platforms(): void
    {
        $this->connectGbp(true);
        $this->fakeYelpConfigured(true);

        $project = $this->project(published: false);
        $imageA = $this->image($project);
        $imageB = $this->image($project);

        Queue::fake();

        $project->update(['is_published' => true]);

        Queue::assertPushed(UploadProjectImageToGooglePlaces::class, 2);
        Queue::assertPushed(UploadProjectImageToYelpBusinessPhotos::class, 2);
        foreach ([$imageA, $imageB] as $image) {
            Queue::assertPushed(UploadProjectImageToGooglePlaces::class, fn ($job) => $job->imageId === $image->id);
            Queue::assertPushed(UploadProjectImageToYelpBusinessPhotos::class, fn ($job) => $job->imageId === $image->id);
        }
    }

    public function test_unconfigured_platform_never_receives_a_dispatch(): void
    {
        $this->connectGbp(false);
        $this->fakeYelpConfigured(false);

        $project = $this->project(published: false);
        $this->image($project);

        Queue::fake();

        $project->update(['is_published' => true]);

        Queue::assertNotPushed(UploadProjectImageToGooglePlaces::class);
        Queue::assertNotPushed(UploadProjectImageToYelpBusinessPhotos::class);
    }
}
