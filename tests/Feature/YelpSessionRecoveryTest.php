<?php

namespace Tests\Feature;

use App\Jobs\UploadProjectImageToYelpBusinessPhotos;
use App\Models\ImagePlatformUpload;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Services\YelpBusinessService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The Yelp session-death → recovery loop.
 *
 * Production 2026-08-02: the browser session died mid-batch, 11 upload jobs
 * failed, and nothing re-queued them after re-login — the photos would have
 * silently never reached Yelp. markSessionFresh() now re-queues what's
 * missing, and markSessionDead() emails the admin (once per episode).
 */
class YelpSessionRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // Satisfy isConfigured() via the config fallback (no PlatformSetting rows).
        Config::set('services.yelp.business.email', 'owner@example.com');
        Config::set('services.yelp.business.password', 'secret');
        Config::set('mail.from.address', 'noreply@example.com');
        // Alerts are production-only by default so a dev run never pages the
        // owner; these tests are specifically about the send path.
        Config::set('services.yelp.business.alert_emails', true);
        // Default these off so markSessionDead() takes the email path. When
        // unattended login IS possible it tries that first instead of paging
        // the owner — covered explicitly below.
        Config::set('services.yelp.business.proxy', null);
        Config::set('services.twocaptcha.api_key', null);
        Config::set('services.anticaptcha.api_key', null);
    }

    protected function makeProjectWithImages(): array
    {
        // Muted events: ProjectImageObserver dispatches its own upload +
        // IndexNow jobs on creation, which would pollute the Queue::fake
        // assertions. This test is about the recovery path, not the observer.
        return \Illuminate\Database\Eloquent\Model::withoutEvents(function () {
            return $this->buildProjectWithImages();
        });
    }

    protected function buildProjectWithImages(): array
    {
        $project = Project::create([
            'title' => 'Test Kitchen',
            'slug' => 'test-kitchen-' . uniqid(),
            'project_type' => 'kitchen-remodel',
            'is_published' => true,
        ]);

        $uploaded = $project->images()->create([
            'filename' => 'a.jpg', 'original_filename' => 'a.jpg',
            'path' => 'projects/x/a.jpg', 'slug' => 'img-a-' . uniqid(),
        ]);
        $missing = $project->images()->create([
            'filename' => 'b.jpg', 'original_filename' => 'b.jpg',
            'path' => 'projects/x/b.jpg', 'slug' => 'img-b-' . uniqid(),
        ]);

        ImagePlatformUpload::record($uploaded->id, 'yelp_biz', ['remote_id' => 'abc']);

        return [$project, $uploaded, $missing];
    }

    public function test_recovery_from_dead_session_requeues_only_missing_photos(): void
    {
        Queue::fake();
        Mail::fake();
        [, , $missing] = $this->makeProjectWithImages();

        $svc = app(YelpBusinessService::class);
        $svc->markSessionDead('test');

        $requeued = $svc->markSessionFresh();

        $this->assertSame(1, $requeued);
        $this->assertFalse(Cache::has('yelp.session_dead'));
        Queue::assertPushed(UploadProjectImageToYelpBusinessPhotos::class, 1);
        Queue::assertPushed(
            UploadProjectImageToYelpBusinessPhotos::class,
            fn ($job) => $job->imageId === $missing->id
        );
    }

    public function test_fresh_session_without_prior_death_requeues_nothing(): void
    {
        Queue::fake();
        $this->makeProjectWithImages();

        $this->assertSame(0, app(YelpBusinessService::class)->markSessionFresh());
        Queue::assertNothingPushed();
    }

    public function test_requeue_skips_images_needing_manual_verification(): void
    {
        Queue::fake();
        Mail::fake();
        [, , $missing] = $this->makeProjectWithImages();

        // In-flight marker = a prior attempt was killed mid-upload; the photo
        // may already be on Yelp. Blind re-queue would duplicate it.
        Cache::put(YelpBusinessService::inFlightCacheKey($missing->id), 'marker', 600);

        $svc = app(YelpBusinessService::class);
        $svc->markSessionDead('test');

        $this->assertSame(0, $svc->markSessionFresh());
        Queue::assertNothingPushed();
    }

    public function test_session_death_emails_admin_once_per_episode(): void
    {
        Mail::fake();

        $svc = app(YelpBusinessService::class);
        $svc->markSessionDead('first failure');
        $svc->markSessionDead('second failure, same episode');

        Mail::assertSent(\App\Mail\YelpSessionExpired::class, 1);
    }

    public function test_a_dead_session_tries_to_log_itself_back_in_before_paging_anyone(): void
    {
        Queue::fake();
        Mail::fake();
        // Both halves present = unattended login is viable.
        Config::set('services.yelp.business.proxy', 'http://user:pass@proxy.test:8000');
        Config::set('services.twocaptcha.api_key', 'captcha-key');

        app(YelpBusinessService::class)->markSessionDead('cookies expired');

        Queue::assertPushed(\App\Jobs\YelpAutoLogin::class);
        // The owner is only worth interrupting when we cannot fix it ourselves.
        Mail::assertNothingSent();
    }

    public function test_it_pages_the_owner_when_unattended_login_is_not_possible(): void
    {
        Queue::fake();
        Mail::fake();
        // No proxy: DataDome binds a solved captcha to the requesting IP, so
        // an attempt could only fail — email instead of burning a launch.
        Config::set('services.yelp.business.proxy', null);
        Config::set('services.twocaptcha.api_key', 'captcha-key');

        app(YelpBusinessService::class)->markSessionDead('cookies expired');

        Queue::assertNotPushed(\App\Jobs\YelpAutoLogin::class);
        Mail::assertSent(\App\Mail\YelpSessionExpired::class);
    }

    public function test_new_episode_after_recovery_alerts_again(): void
    {
        Queue::fake();
        Mail::fake();

        $svc = app(YelpBusinessService::class);
        $svc->markSessionDead('episode one');
        $svc->markSessionFresh();
        $svc->markSessionDead('episode two');

        Mail::assertSent(\App\Mail\YelpSessionExpired::class, 2);
    }
}
