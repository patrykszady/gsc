<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\AreaServed;
use App\Models\ImagePlatformUpload;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Services\GoogleBusinessProfileService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * POST/DELETE platforms/gbp/media — the central admin's project-photo
 * pass-through (2026-09-21): it uploads one of THIS site's project photos to
 * a Business Profile listing that need not be this site's own, using this
 * site's Google grant. Google itself is never called for most of these —
 * the service is mocked, as in PlatformsGbpReviewsTest. The
 * GooglePhotoCopy-backed `-gbp-` derivative tests below use the REAL
 * service (only the outbound Google HTTP calls are faked) so the stored
 * file and its EXIF stamp can be inspected directly — see
 * SsSystems\Platform\Media\GooglePhotoCopy/JpegExifTagger.
 */
class PlatformsGbpMediaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function headers(): array
    {
        config(['services.admin_api.token' => 'test-admin-api-token']);

        return ['Authorization' => 'Bearer test-admin-api-token', 'Accept' => 'application/json'];
    }

    /** A tiny real JPEG (Intervention/GD) — GooglePhotoCopy re-encodes real bytes, not a stub. */
    private function realJpeg(): string
    {
        return (string) (new ImageManager(new Driver()))
            ->create(40, 30)
            ->fill('#3366ff')
            ->toJpeg()
            ->toString();
    }

    /** EXIF of JPEG bytes, sections nested (`$data['EXIF']['DateTimeOriginal']`, `$data['GPS']['GPSLatitude']`). */
    private function readExif(string $jpegBytes): array
    {
        return (array) (exif_read_data('data://image/jpeg;base64,'.base64_encode($jpegBytes), null, true, false) ?: []);
    }

    /** A published project + image with a real JPEG on the fake public disk. */
    private function projectImageWithRealFile(array $projectAttrs = [], array $imageAttrs = []): ProjectImage
    {
        // ProjectObserver auto-creates (and geocodes, over real HTTP) an
        // AreaServed row for a project's location on save — off here so
        // these tests control their own AreaServed rows and never touch
        // the network. gemini_api_key is real in this env (no .env.testing,
        // so .env's own key loads) — blanked here too, or
        // ProjectImageObserver::created() would fire a real
        // GenerateAiContentJob (QUEUE_CONNECTION=sync runs it inline) on
        // every fixture image.
        config([
            'services.google.business_profile.auto_geocode_on_project_save' => false,
            'services.google.gemini_api_key' => '',
        ]);

        $project = Project::create(array_merge([
            'title' => 'Kitchen Remodel',
            'slug' => 'kitchen-remodel-'.uniqid(),
            'project_type' => 'kitchen',
            'location' => 'Palatine, IL',
            'completed_at' => '2020-06-15',
            'is_published' => true,
        ], $projectAttrs));

        $path = 'projects/kitchen-'.uniqid().'.jpg';
        Storage::disk('public')->put($path, $this->realJpeg());

        return ProjectImage::create(array_merge([
            'project_id' => $project->id,
            'filename' => basename($path),
            'original_filename' => basename($path),
            'path' => $path,
            'alt_text' => 'Renovated kitchen with white cabinets',
            'caption' => 'A finished kitchen remodel.',
        ], $imageAttrs));
    }

    /** Every `-gbp-*.jpg` currently on the fake public disk under projects/. */
    private function gbpCopies(): \Illuminate\Support\Collection
    {
        return collect(Storage::disk('public')->files('projects'))
            ->filter(fn (string $f) => str_contains($f, '-gbp-'))
            ->values();
    }

    private function publishedImage(): ProjectImage
    {
        $project = Project::create([
            'title' => 'Kitchen Remodel',
            'slug' => 'kitchen-remodel-'.uniqid(),
            'project_type' => 'kitchen',
            'is_published' => true,
        ]);

        return ProjectImage::create([
            'project_id' => $project->id,
            'filename' => 'kitchen.jpg',
            'original_filename' => 'kitchen.jpg',
            'path' => 'projects/kitchen.jpg',
            'alt_text' => 'Renovated kitchen with white cabinets',
            'caption' => 'A finished kitchen remodel.',
        ]);
    }

    private function unpublishedImage(): ProjectImage
    {
        $project = Project::create([
            'title' => 'Bath Remodel',
            'slug' => 'bath-remodel-'.uniqid(),
            'project_type' => 'bathroom',
            'is_published' => false,
        ]);

        return ProjectImage::create([
            'project_id' => $project->id,
            'filename' => 'bath.jpg',
            'original_filename' => 'bath.jpg',
            'path' => 'projects/bath.jpg',
            'alt_text' => 'Bathroom in progress',
        ]);
    }

    public function test_uploading_a_published_images_photo_calls_the_service_and_records_the_upload(): void
    {
        $image = $this->publishedImage();

        $mock = Mockery::mock(GoogleBusinessProfileService::class);
        $mock->shouldReceive('hasRefreshToken')->once()->andReturn(true);
        $mock->shouldReceive('getPublicImageUrl')
            ->once()
            ->with(Mockery::on(fn (ProjectImage $img) => $img->is($image)), null, null, null)
            ->andReturn('https://gs.construction/storage/projects/kitchen_gbp.jpg');
        $mock->shouldReceive('mapCategory')
            ->once()
            ->with(Mockery::on(fn (ProjectImage $img) => $img->is($image)))
            ->andReturn('ADDITIONAL');
        $mock->shouldReceive('buildDescription')
            ->once()
            ->with(Mockery::on(fn (ProjectImage $img) => $img->is($image)))
            ->andReturn('A finished kitchen remodel.');
        $mock->shouldReceive('uploadMediaFor')
            ->once()
            ->with('900', '111', 'https://gs.construction/storage/projects/kitchen_gbp.jpg', 'ADDITIONAL', 'A finished kitchen remodel.')
            ->andReturn(['name' => 'accounts/900/locations/111/media/abc123', 'url' => 'https://lh3.googleusercontent.com/abc123=s0']);
        $this->app->instance(GoogleBusinessProfileService::class, $mock);

        $data = $this->postJson('/api/admin/v1/platforms/gbp/media', [
            'account_id' => '900',
            'location_id' => 'locations/111',
            'image_id' => $image->id,
        ], $this->headers())
            ->assertOk()
            ->json('data');

        $this->assertSame([
            'ok' => true,
            'image_id' => $image->id,
            'media_name' => 'accounts/900/locations/111/media/abc123',
            'media_url' => 'https://lh3.googleusercontent.com/abc123=s0',
        ], $data);

        $upload = ImagePlatformUpload::where('project_image_id', $image->id)
            ->where('platform', ImagePlatformUpload::PLATFORM_GOOGLE_PLACES)
            ->firstOrFail();

        $this->assertSame('accounts/900/locations/111/media/abc123', $upload->remote_id);
        $this->assertSame('https://lh3.googleusercontent.com/abc123=s0', $upload->remote_url);
        $this->assertSame(['account_id' => '900', 'location_id' => '111'], $upload->metadata);
        $this->assertNotNull($upload->uploaded_at);

        // These are accessors over the ImagePlatformUpload row (not real
        // columns any more — see ProjectImage::getGooglePlacesMediaNameAttribute()),
        // so the Social Media counters and DeleteGooglePlacesMedia see the
        // pass-through upload exactly as a self-upload.
        $image->refresh();
        $this->assertSame('accounts/900/locations/111/media/abc123', $image->google_places_media_name);
        $this->assertNotNull($image->google_places_uploaded_at);
    }

    public function test_an_explicit_category_and_description_override_the_defaults(): void
    {
        $image = $this->publishedImage();

        $mock = Mockery::mock(GoogleBusinessProfileService::class);
        $mock->shouldReceive('hasRefreshToken')->once()->andReturn(true);
        $mock->shouldReceive('getPublicImageUrl')->once()->andReturn('https://gs.construction/storage/projects/kitchen_gbp.jpg');
        $mock->shouldNotReceive('mapCategory');
        $mock->shouldNotReceive('buildDescription');
        $mock->shouldReceive('uploadMediaFor')
            ->once()
            ->with('900', '111', 'https://gs.construction/storage/projects/kitchen_gbp.jpg', 'EXTERIOR', 'Custom caption.')
            ->andReturn(['name' => 'accounts/900/locations/111/media/xyz', 'url' => null]);
        $this->app->instance(GoogleBusinessProfileService::class, $mock);

        $this->postJson('/api/admin/v1/platforms/gbp/media', [
            'account_id' => '900',
            'location_id' => '111',
            'image_id' => $image->id,
            'category' => 'EXTERIOR',
            'description' => 'Custom caption.',
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.media_url', null);
    }

    public function test_an_unpublished_projects_image_is_rejected_before_google_is_ever_called(): void
    {
        $image = $this->unpublishedImage();

        $mock = Mockery::mock(GoogleBusinessProfileService::class);
        $mock->shouldNotReceive('hasRefreshToken');
        $mock->shouldNotReceive('uploadMediaFor');
        $this->app->instance(GoogleBusinessProfileService::class, $mock);

        $this->postJson('/api/admin/v1/platforms/gbp/media', [
            'account_id' => '900',
            'location_id' => '111',
            'image_id' => $image->id,
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('image_id');

        $this->assertSame(0, ImagePlatformUpload::where('project_image_id', $image->id)->count());
    }

    public function test_a_missing_image_id_is_also_rejected_with_errors_image_id(): void
    {
        $mock = Mockery::mock(GoogleBusinessProfileService::class);
        $mock->shouldNotReceive('hasRefreshToken');
        $mock->shouldNotReceive('uploadMediaFor');
        $this->app->instance(GoogleBusinessProfileService::class, $mock);

        $this->postJson('/api/admin/v1/platforms/gbp/media', [
            'account_id' => '900',
            'location_id' => '111',
            'image_id' => 999999,
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('image_id');
    }

    public function test_a_google_refusal_is_reported_and_nothing_is_recorded(): void
    {
        $image = $this->publishedImage();

        $mock = Mockery::mock(GoogleBusinessProfileService::class);
        $mock->shouldReceive('hasRefreshToken')->once()->andReturn(true);
        $mock->shouldReceive('getPublicImageUrl')->once()->andReturn('https://gs.construction/storage/projects/kitchen_gbp.jpg');
        $mock->shouldReceive('mapCategory')->once()->andReturn('ADDITIONAL');
        $mock->shouldReceive('buildDescription')->once()->andReturn('A finished kitchen remodel.');
        $mock->shouldReceive('uploadMediaFor')->once()->andReturn(null);
        $mock->shouldReceive('getLastError')->andReturn(['message' => 'INVALID_ARGUMENT: bad sourceUrl']);
        $this->app->instance(GoogleBusinessProfileService::class, $mock);

        $this->postJson('/api/admin/v1/platforms/gbp/media', [
            'account_id' => '900',
            'location_id' => '111',
            'image_id' => $image->id,
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Google refused the photo: INVALID_ARGUMENT: bad sourceUrl')
            ->assertJsonPath('errors.google.0', 'Google refused the photo: INVALID_ARGUMENT: bad sourceUrl');

        $this->assertSame(0, ImagePlatformUpload::where('project_image_id', $image->id)->count());
    }

    public function test_upload_is_refused_when_google_business_profile_is_not_connected(): void
    {
        $image = $this->publishedImage();

        $mock = Mockery::mock(GoogleBusinessProfileService::class);
        $mock->shouldReceive('hasRefreshToken')->once()->andReturn(false);
        $mock->shouldNotReceive('uploadMediaFor');
        $this->app->instance(GoogleBusinessProfileService::class, $mock);

        $this->postJson('/api/admin/v1/platforms/gbp/media', [
            'account_id' => '900',
            'location_id' => '111',
            'image_id' => $image->id,
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Connect Google Business Profile first.');
    }

    public function test_delete_removes_the_platform_upload_row_and_returns_no_content(): void
    {
        $image = $this->publishedImage();

        ImagePlatformUpload::record($image->id, ImagePlatformUpload::PLATFORM_GOOGLE_PLACES, [
            'remote_id' => 'accounts/900/locations/111/media/abc123',
            'remote_url' => 'https://lh3.googleusercontent.com/abc123=s0',
            'metadata' => ['account_id' => '900', 'location_id' => '111'],
        ]);

        $mock = Mockery::mock(GoogleBusinessProfileService::class);
        $mock->shouldReceive('hasRefreshToken')->once()->andReturn(true);
        $mock->shouldReceive('deleteMedia')->once()->with('accounts/900/locations/111/media/abc123')->andReturn(true);
        $this->app->instance(GoogleBusinessProfileService::class, $mock);

        $this->deleteJson('/api/admin/v1/platforms/gbp/media', [
            'media_name' => 'accounts/900/locations/111/media/abc123',
            'image_id' => $image->id,
        ], $this->headers())->assertNoContent();

        $this->assertSame(0, ImagePlatformUpload::where('project_image_id', $image->id)->count());
        $this->assertNull($image->refresh()->google_places_media_name);
    }

    public function test_delete_treats_a_404_from_google_as_success(): void
    {
        $image = $this->publishedImage();

        ImagePlatformUpload::record($image->id, ImagePlatformUpload::PLATFORM_GOOGLE_PLACES, [
            'remote_id' => 'accounts/900/locations/111/media/gone',
            'remote_url' => null,
        ]);

        $mock = Mockery::mock(GoogleBusinessProfileService::class);
        $mock->shouldReceive('hasRefreshToken')->once()->andReturn(true);
        $mock->shouldReceive('deleteMedia')->once()->andReturn(false);
        $mock->shouldReceive('getLastError')->andReturn(['message' => 'Delete failed', 'status' => 404]);
        $this->app->instance(GoogleBusinessProfileService::class, $mock);

        $this->deleteJson('/api/admin/v1/platforms/gbp/media', [
            'media_name' => 'accounts/900/locations/111/media/gone',
        ], $this->headers())->assertNoContent();

        $this->assertSame(0, ImagePlatformUpload::where('project_image_id', $image->id)->count());
    }

    public function test_delete_is_refused_when_google_business_profile_is_not_connected(): void
    {
        $mock = Mockery::mock(GoogleBusinessProfileService::class);
        $mock->shouldReceive('hasRefreshToken')->once()->andReturn(false);
        $mock->shouldNotReceive('deleteMedia');
        $this->app->instance(GoogleBusinessProfileService::class, $mock);

        $this->deleteJson('/api/admin/v1/platforms/gbp/media', [
            'media_name' => 'accounts/900/locations/111/media/abc123',
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Connect Google Business Profile first.');
    }

    /*
    |--------------------------------------------------------------------------
    |  GooglePhotoCopy-backed `-gbp-` derivative (0.3.1 kit, 2026-09-22)
    |--------------------------------------------------------------------------
    */

    public function test_captured_at_and_coordinates_override_stamp_the_copys_exif(): void
    {
        $image = $this->projectImageWithRealFile(['completed_at' => '2020-06-15']);
        // A different market than the override, so the assertion below can
        // only pass if the request's lat/lng won and not this default.
        AreaServed::create(['city' => 'Palatine', 'slug' => 'palatine', 'latitude' => 42.11, 'longitude' => -88.03]);

        config(['services.google.business_profile.refresh_token' => 'test-refresh-token']);
        Cache::flush();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-access-token', 'expires_in' => 3600], 200),
            'mybusiness.googleapis.com/*' => Http::response(['name' => 'accounts/900/locations/111/media/abc', 'googleUrl' => 'https://lh3.googleusercontent.com/abc'], 200),
        ]);

        $this->postJson('/api/admin/v1/platforms/gbp/media', [
            'account_id' => '900',
            'location_id' => '111',
            'image_id' => $image->id,
            'captured_at' => '2021-01-01',
            'latitude' => 40.0,
            'longitude' => -90.0,
        ], $this->headers())->assertOk();

        $copies = $this->gbpCopies();
        $this->assertCount(1, $copies);

        $exif = $this->readExif(Storage::disk('public')->get($copies->first()));
        $this->assertSame('2021:01:01 00:00:00', $exif['EXIF']['DateTimeOriginal'] ?? null);
        $this->assertSame('N', $exif['GPS']['GPSLatitudeRef'] ?? null);
        $this->assertSame('40/1', $exif['GPS']['GPSLatitude'][0] ?? null);
    }

    public function test_defaults_come_from_the_projects_completion_date_and_area_served(): void
    {
        $image = $this->projectImageWithRealFile(['completed_at' => '2019-03-10']);
        AreaServed::create(['city' => 'Palatine', 'slug' => 'palatine', 'latitude' => 42.11, 'longitude' => -88.03]);

        $url = app(GoogleBusinessProfileService::class)->getPublicImageUrl($image);

        $this->assertNotNull($url);
        $this->assertStringContainsString('-gbp-', $url);

        $copies = $this->gbpCopies();
        $this->assertCount(1, $copies);

        $exif = $this->readExif(Storage::disk('public')->get($copies->first()));
        $this->assertSame('2019:03:10 00:00:00', $exif['EXIF']['DateTimeOriginal'] ?? null);
        $this->assertSame('N', $exif['GPS']['GPSLatitudeRef'] ?? null);
    }

    public function test_the_copy_is_reused_for_the_same_params_and_replaced_when_the_date_changes(): void
    {
        $image = $this->projectImageWithRealFile();
        $service = app(GoogleBusinessProfileService::class);

        $first = $service->getPublicImageUrl($image, 41.0, -87.5, Carbon::parse('2020-06-15'));
        $again = $service->getPublicImageUrl($image, 41.0, -87.5, Carbon::parse('2020-06-15'));

        $this->assertSame($first, $again);
        $this->assertCount(1, $this->gbpCopies());

        $changed = $service->getPublicImageUrl($image, 41.0, -87.5, Carbon::parse('2022-09-01'));

        $this->assertNotSame($first, $changed);
        $copiesAfterChange = $this->gbpCopies();
        $this->assertCount(1, $copiesAfterChange, 'the superseded -gbp- copy is removed when the date changes');
    }

    public function test_upload_project_image_the_site_side_path_produces_the_same_stamped_copy(): void
    {
        $image = $this->projectImageWithRealFile(['completed_at' => '2018-11-20']);
        AreaServed::create(['city' => 'Palatine', 'slug' => 'palatine', 'latitude' => 42.11, 'longitude' => -88.03]);

        config(['services.google.business_profile' => [
            'enabled' => true,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'refresh_token' => 'test-refresh-token',
            'account_id' => '900',
            'location_id' => '111',
            'geotag_photos' => true,
        ]]);
        Cache::flush();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-access-token', 'expires_in' => 3600], 200),
            'mybusiness.googleapis.com/*' => Http::response(['name' => 'accounts/900/locations/111/media/xyz', 'googleUrl' => 'https://lh3.googleusercontent.com/xyz'], 200),
        ]);

        $result = app(GoogleBusinessProfileService::class)->uploadProjectImage($image);

        $this->assertNotNull($result);

        $copies = $this->gbpCopies();
        $this->assertCount(1, $copies);

        $exif = $this->readExif(Storage::disk('public')->get($copies->first()));
        $this->assertSame('2018:11:20 00:00:00', $exif['EXIF']['DateTimeOriginal'] ?? null);
        $this->assertSame('N', $exif['GPS']['GPSLatitudeRef'] ?? null);
    }
}
