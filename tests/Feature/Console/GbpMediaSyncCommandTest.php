<?php

namespace Tests\Feature\Console;

use App\Jobs\UploadProjectImageToGooglePlaces;
use App\Models\ImagePlatformUpload;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Services\GoogleBusinessProfileService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/**
 * google-business-profile:sync — when it fails, and what it says.
 *
 * Production, nightly at 02:30 UTC until 2026-11-12: "Scheduled GBP sync
 * failed". The scheduled form (`--upload --queue`) never calls Google at
 * all; it exited 1 because the central admin uploads this site's photos
 * (GBP_PHOTOS_OWNED_BY=ss-systems) and the command answered that hand-off
 * as a failure. Having nothing to send is a skip. The forms that DO call
 * Google ask again after a passing failure (a 5xx, rate limiting), and the
 * run fails only when it did nothing at all or Google refused something a
 * retry will not fix — with the reason as its last line.
 *
 * Google is never reached: every host is faked, and no wait is real.
 */
class GbpMediaSyncCommandTest extends TestCase
{
    private const MEDIA = 'mybusiness.googleapis.com/v4/accounts/111/locations/222/media';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Sleep::fake();

        config([
            'services.google.business_profile.client_id' => 'shared-client.apps.googleusercontent.com',
            'services.google.business_profile.client_secret' => 'shared-secret',
            'services.google.business_profile.refresh_token' => 'env-refresh-token',
            'services.google.business_profile.account_id' => '111',
            'services.google.business_profile.location_id' => '222',
            'services.google.business_profile.photos_owned_by' => 'site',
            'services.google.business_profile.production_url' => 'https://gs.construction',
        ]);
    }

    /** @param  array<string, mixed>  $routes  URL pattern => response, sequence or closure */
    private function fakeGoogle(array $routes = []): void
    {
        Http::fake($routes + [
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'at-1',
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/business.manage openid email',
            ]),
        ]);
        Http::preventStrayRequests();
    }

    private function unavailable(int $status = 503): PromiseInterface
    {
        return Http::response(['error' => ['code' => $status, 'message' => 'The service is currently unavailable.', 'status' => 'UNAVAILABLE']], $status);
    }

    private function uploaded(string $id): PromiseInterface
    {
        return Http::response(['name' => "accounts/111/locations/222/media/{$id}", 'googleUrl' => "https://lh3.googleusercontent.com/p/{$id}"]);
    }

    /** A published project photo with a real JPEG on the fake disk, created without observers (no AI, no uploads). */
    private function projectImage(int $sortOrder = 0): ProjectImage
    {
        $project = Project::withoutEvents(fn () => Project::create([
            'title' => 'Kitchen Remodel',
            'slug' => 'kitchen-remodel-'.uniqid(),
            'project_type' => 'kitchen',
            'location' => 'Palatine, IL',
            'completed_at' => '2020-06-15',
            'is_published' => true,
        ]));

        $path = 'projects/kitchen-'.uniqid().'.jpg';
        Storage::disk('public')->put($path, (string) (new ImageManager(new Driver))->create(40, 30)->fill('#3366ff')->toJpeg()->toString());

        return ProjectImage::withoutEvents(fn () => ProjectImage::create([
            'project_id' => $project->id,
            'filename' => basename($path),
            'original_filename' => basename($path),
            'path' => $path,
            'alt_text' => 'Renovated kitchen with white cabinets',
            'caption' => 'A finished kitchen remodel.',
            'sort_order' => $sortOrder,
        ]));
    }

    /**
     * Run the command as the scheduler does and read what it printed.
     *
     * @param  array<string, mixed>  $options
     * @return array{exit: int, output: string, last: string}
     */
    private function sync(array $options): array
    {
        $exit = Artisan::call('google-business-profile:sync', $options);
        $output = Artisan::output();
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $output) ?: []), fn (string $line) => $line !== ''));

        return ['exit' => $exit, 'output' => $output, 'last' => (string) end($lines)];
    }

    private function mediaRequests(string $method): int
    {
        return Http::recorded(fn ($request) => $request->method() === $method && str_contains($request->url(), self::MEDIA))->count();
    }

    private function googleUpload(ProjectImage $image): ?ImagePlatformUpload
    {
        return ImagePlatformUpload::where('project_image_id', $image->id)
            ->where('platform', ImagePlatformUpload::PLATFORM_GOOGLE_PLACES)
            ->first();
    }

    /* ------------------------------------------------------------------ */
    /*  The scheduled form: --upload --queue */
    /* ------------------------------------------------------------------ */

    public function test_the_nightly_form_is_a_skip_not_a_failure_when_the_central_admin_uploads_the_photos(): void
    {
        config(['services.google.business_profile.photos_owned_by' => 'ss-systems']);
        $this->projectImage();
        $this->fakeGoogle();
        Queue::fake();

        $run = $this->sync(['--upload' => true, '--queue' => true]);

        $this->assertSame(0, $run['exit'], $run['output']);
        $this->assertStringStartsWith('Skipped:', $run['last']);
        $this->assertStringContainsString('uploaded by the central admin', $run['last']);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_reading_or_clearing_googles_media_is_still_refused_when_the_central_admin_owns_the_photos(): void
    {
        config(['services.google.business_profile.photos_owned_by' => 'ss-systems']);
        $this->fakeGoogle();

        foreach ([['--list' => true], ['--delete-orphans' => true], []] as $options) {
            $run = $this->sync($options);

            $this->assertSame(1, $run['exit'], $run['output']);
            $this->assertStringContainsString('uploaded by the central admin', $run['last']);
        }

        Http::assertNothingSent();
    }

    public function test_the_nightly_form_queues_what_is_pending_without_calling_google(): void
    {
        $first = $this->projectImage();
        $second = $this->projectImage();
        $this->fakeGoogle();
        Queue::fake();

        $run = $this->sync(['--upload' => true, '--queue' => true]);

        $this->assertSame(0, $run['exit'], $run['output']);
        $this->assertStringContainsString("Queued 2 upload jobs on the 'media-sync' queue", $run['last']);
        Queue::assertPushedOn('media-sync', UploadProjectImageToGooglePlaces::class);
        Queue::assertPushed(UploadProjectImageToGooglePlaces::class, 2);
        Queue::assertPushed(UploadProjectImageToGooglePlaces::class, fn ($job) => $job->imageId === $first->id);
        Queue::assertPushed(UploadProjectImageToGooglePlaces::class, fn ($job) => $job->imageId === $second->id);
        Http::assertNothingSent();
    }

    public function test_a_site_that_is_not_connected_fails_with_the_reason_last(): void
    {
        config(['services.google.business_profile.refresh_token' => null]);
        $this->fakeGoogle();

        $run = $this->sync(['--upload' => true, '--queue' => true]);

        $this->assertSame(1, $run['exit']);
        $this->assertStringStartsWith('Failed:', $run['last']);
        $this->assertStringContainsString('not fully configured', $run['last']);
        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------ */
    /*  Uploading inline: --upload */
    /* ------------------------------------------------------------------ */

    public function test_a_503_is_asked_again_after_a_wait_and_the_photo_goes_up(): void
    {
        $image = $this->projectImage();
        $this->fakeGoogle([self::MEDIA => Http::sequence()->pushResponse($this->unavailable())->pushResponse($this->uploaded('m1'))]);

        $run = $this->sync(['--upload' => true]);

        $this->assertSame(0, $run['exit'], $run['output']);
        $this->assertSame('Upload complete: 1 succeeded, 0 failed.', $run['last']);
        $this->assertSame(2, $this->mediaRequests('POST'));
        $this->assertSame('accounts/111/locations/222/media/m1', $this->googleUpload($image)?->remote_id);
        Sleep::assertSlept(fn (\DateInterval $wait): bool => $wait->s === 2, 1);
    }

    public function test_a_run_that_uploads_nothing_because_google_is_down_fails_and_says_so(): void
    {
        $image = $this->projectImage();
        $this->fakeGoogle([self::MEDIA => $this->unavailable()]);

        $run = $this->sync(['--upload' => true]);

        $this->assertSame(1, $run['exit'], $run['output']);
        $this->assertStringStartsWith('Failed: nothing was uploaded', $run['last']);
        $this->assertStringContainsString('HTTP 503', $run['last']);
        $this->assertStringContainsString('Google had a problem answering', $run['last']);
        $this->assertSame(GoogleBusinessProfileService::TRANSIENT_ATTEMPTS, $this->mediaRequests('POST'));
        $this->assertNull($this->googleUpload($image));
        Sleep::assertSlept(fn (\DateInterval $wait): bool => $wait->s === 2, 1);
        Sleep::assertSlept(fn (\DateInterval $wait): bool => $wait->s === 6, 1);
    }

    public function test_one_photo_google_could_not_take_tonight_does_not_fail_a_run_that_uploaded_the_rest(): void
    {
        $unlucky = $this->projectImage();
        $lucky = $this->projectImage();
        $this->fakeGoogle([self::MEDIA => Http::sequence()
            ->pushResponse($this->unavailable())
            ->pushResponse($this->unavailable())
            ->pushResponse($this->unavailable())
            ->pushResponse($this->uploaded('m2'))]);

        $run = $this->sync(['--upload' => true]);

        $this->assertSame(0, $run['exit'], $run['output']);
        $this->assertStringStartsWith('Upload complete: 1 succeeded, 1 failed.', $run['last']);
        $this->assertStringContainsString('stay pending for the next run', $run['last']);
        $this->assertNull($this->googleUpload($unlucky));
        $this->assertSame('accounts/111/locations/222/media/m2', $this->googleUpload($lucky)?->remote_id);
    }

    public function test_an_upload_google_may_already_have_taken_is_never_sent_twice_in_one_run(): void
    {
        $this->projectImage();
        $this->fakeGoogle([self::MEDIA => $this->unavailable(504)]);

        $run = $this->sync(['--upload' => true]);

        $this->assertSame(1, $run['exit'], $run['output']);
        $this->assertStringStartsWith('Failed: nothing was uploaded', $run['last']);
        $this->assertSame(1, $this->mediaRequests('POST'));
    }

    public function test_a_refusal_no_retry_can_fix_stops_the_run_and_fails_with_googles_reason(): void
    {
        $this->projectImage();
        $this->projectImage();
        $this->fakeGoogle([self::MEDIA => Http::response(['error' => ['code' => 403, 'message' => 'The caller does not have permission', 'status' => 'PERMISSION_DENIED']], 403)]);

        $run = $this->sync(['--upload' => true]);

        $this->assertSame(1, $run['exit'], $run['output']);
        $this->assertStringStartsWith('Failed: stopped after 0 of 2', $run['last']);
        $this->assertStringContainsString('HTTP 403', $run['last']);
        $this->assertStringContainsString('cannot manage that listing', $run['last']);
        $this->assertSame(1, $this->mediaRequests('POST'));
        Sleep::assertNeverSlept();
    }

    public function test_one_photo_google_refuses_outright_fails_the_run_while_the_rest_still_go_up(): void
    {
        $refused = $this->projectImage();
        $taken = $this->projectImage();
        $this->fakeGoogle([self::MEDIA => Http::sequence()
            ->push(['error' => ['code' => 400, 'message' => 'Photo is too small.', 'status' => 'INVALID_ARGUMENT']], 400)
            ->pushResponse($this->uploaded('m2'))]);

        $run = $this->sync(['--upload' => true]);

        $this->assertSame(1, $run['exit'], $run['output']);
        $this->assertStringStartsWith('Failed: 1 succeeded, 1 failed.', $run['last']);
        $this->assertStringContainsString("image #{$refused->id}", $run['last']);
        $this->assertStringContainsString('Photo is too small', $run['last']);
        $this->assertSame(2, $this->mediaRequests('POST'));
        $this->assertSame('accounts/111/locations/222/media/m2', $this->googleUpload($taken)?->remote_id);
    }

    /* ------------------------------------------------------------------ */
    /*  Reading Google's media: --list, --delete-orphans */
    /* ------------------------------------------------------------------ */

    public function test_listing_asks_a_page_again_after_a_503(): void
    {
        $this->fakeGoogle([self::MEDIA.'*' => Http::sequence()
            ->push(['mediaItems' => [['name' => 'accounts/111/locations/222/media/one', 'mediaFormat' => 'PHOTO']], 'nextPageToken' => 'page-2'])
            ->pushResponse($this->unavailable())
            ->push(['mediaItems' => [['name' => 'accounts/111/locations/222/media/two', 'mediaFormat' => 'PHOTO']]])]);

        $run = $this->sync(['--list' => true]);

        $this->assertSame(0, $run['exit'], $run['output']);
        $this->assertSame('Total: 2 media items on GBP.', $run['last']);
        $this->assertSame(3, $this->mediaRequests('GET'));
    }

    public function test_part_of_the_list_is_never_passed_off_as_all_of_it(): void
    {
        $this->fakeGoogle([self::MEDIA.'*' => Http::sequence()
            ->push(['mediaItems' => [['name' => 'accounts/111/locations/222/media/one', 'mediaFormat' => 'PHOTO']], 'nextPageToken' => 'page-2'])
            ->pushResponse($this->unavailable())
            ->pushResponse($this->unavailable())
            ->pushResponse($this->unavailable())]);

        $run = $this->sync(['--list' => true]);

        $this->assertSame(1, $run['exit'], $run['output']);
        $this->assertStringStartsWith('Failed: Google did not give the whole media list', $run['last']);
        $this->assertStringContainsString('HTTP 503', $run['last']);
        $this->assertStringNotContainsString('Total:', $run['output']);
    }

    public function test_orphans_are_never_judged_from_a_list_google_did_not_finish(): void
    {
        $this->fakeGoogle([self::MEDIA.'*' => $this->unavailable()]);

        $run = $this->sync(['--delete-orphans' => true]);

        $this->assertSame(1, $run['exit'], $run['output']);
        $this->assertStringStartsWith('Failed: Google did not give the whole media list', $run['last']);
        $this->assertSame(GoogleBusinessProfileService::TRANSIENT_ATTEMPTS, $this->mediaRequests('GET'));
        $this->assertSame(0, $this->mediaRequests('DELETE'));
    }

    /* ------------------------------------------------------------------ */
    /*  The retry itself */
    /* ------------------------------------------------------------------ */

    public function test_only_a_passing_failure_is_asked_again(): void
    {
        $this->fakeGoogle([self::MEDIA.'*' => Http::response(['error' => ['code' => 403, 'message' => 'The caller does not have permission', 'status' => 'PERMISSION_DENIED']], 403)]);
        $gbp = app(GoogleBusinessProfileService::class);

        $this->assertNull($gbp->withTransientRetry(fn () => $gbp->listMedia(null, 1)));

        $this->assertFalse($gbp->lastErrorIsTransient());
        $this->assertSame(1, $this->mediaRequests('GET'));
        Sleep::assertNeverSlept();
    }

    public function test_a_read_is_asked_again_when_google_does_not_answer_at_all(): void
    {
        $answers = [
            fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
            fn () => Http::response(['mediaItems' => [], 'totalMediaItemCount' => 0]),
        ];
        $this->fakeGoogle([self::MEDIA.'*' => function () use (&$answers) {
            return array_shift($answers)();
        }]);
        $gbp = app(GoogleBusinessProfileService::class);

        $page = $gbp->withTransientRetry(fn () => $gbp->listMedia(null, 1));

        $this->assertSame(['mediaItems' => [], 'totalMediaItemCount' => 0], $page);
        $this->assertNull($gbp->getLastError());
        Sleep::assertSlept(fn (\DateInterval $wait): bool => $wait->s === 2, 1);
    }

    public function test_a_plain_list_call_is_still_one_request_for_the_screens_that_wait_on_it(): void
    {
        $this->fakeGoogle([self::MEDIA.'*' => $this->unavailable()]);
        $gbp = app(GoogleBusinessProfileService::class);

        $this->assertNull($gbp->listMedia(null, 1));

        $this->assertTrue($gbp->lastErrorIsTransient());
        $this->assertSame(1, $this->mediaRequests('GET'));
        Sleep::assertNeverSlept();
    }

    /* ------------------------------------------------------------------ */
    /*  The daily health check makes the same "List media" call */
    /* ------------------------------------------------------------------ */

    public function test_one_503_does_not_fail_the_daily_health_check(): void
    {
        $this->fakeGoogle([self::MEDIA.'*' => Http::sequence()
            ->pushResponse($this->unavailable())
            ->push(['mediaItems' => [], 'totalMediaItemCount' => 0])]);

        $this->artisan('google-business-profile:health')
            ->expectsOutputToContain('GBP health check OK.')
            ->assertExitCode(0);

        $this->assertSame(2, $this->mediaRequests('GET'));
    }

    public function test_the_health_check_still_fails_when_google_stays_down_and_says_what_google_answered(): void
    {
        $this->fakeGoogle([self::MEDIA.'*' => $this->unavailable()]);

        $this->artisan('google-business-profile:health')
            ->expectsOutputToContain('GBP health check failed: unable to list media (HTTP 503)')
            ->assertExitCode(1);

        $this->assertSame(GoogleBusinessProfileService::TRANSIENT_ATTEMPTS, $this->mediaRequests('GET'));
    }
}
