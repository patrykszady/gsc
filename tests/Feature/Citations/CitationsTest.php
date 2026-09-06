<?php

namespace Tests\Feature\Citations;

use App\Models\Citation;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Services\Citations\CitationSessionService;
use App\Services\Citations\VerificationInbox;
use App\Support\Citations\LinkCheck;
use App\Support\Citations\ListingPayload;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * The citation builder: one payload for every directory, the registry in
 * the citations table, the admin API around the remote session, the link
 * check that promotes a listing to live, and the verification-email parser.
 */
class CitationsTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
        config(['app.url' => 'https://gs.construction', 'citations.storage_dir' => sys_get_temp_dir() . '/citations-test-' . getmypid()]);
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\File::deleteDirectory((string) config('citations.storage_dir'));
        parent::tearDown();
    }

    public function test_listing_payload_carries_identity_address_hours_descriptions_and_photos(): void
    {
        Storage::fake('public');
        $featured = Project::create(['title' => 'Palatine Kitchen', 'slug' => 'palatine-kitchen', 'project_type' => 'kitchen', 'location' => 'Palatine, IL', 'is_published' => true, 'is_featured' => true, 'completed_at' => now()]);
        $older = Project::create(['title' => 'Wheeling Bath', 'slug' => 'wheeling-bath', 'project_type' => 'bathroom', 'location' => 'Wheeling, IL', 'is_published' => true, 'completed_at' => now()->subYear()]);
        $draft = Project::create(['title' => 'Draft', 'slug' => 'draft', 'project_type' => 'kitchen', 'is_published' => false]);
        foreach ([[$featured, 5], [$older, 2], [$draft, 1]] as [$project, $n]) {
            for ($i = 1; $i <= $n; $i++) {
                Storage::disk('public')->put("projects/{$project->slug}-{$i}.jpg", 'x');
                ProjectImage::create(['project_id' => $project->id, 'filename' => "{$project->slug}-{$i}.jpg", 'original_filename' => "{$i}.jpg", 'path' => "projects/{$project->slug}-{$i}.jpg", 'mime_type' => 'image/jpeg', 'size' => 1000, 'width' => 1600, 'height' => 1200, 'alt_text' => "{$project->title} photo {$i}", 'sort_order' => $i]);
            }
        }
        config(['citations.photos.per_project' => 3, 'citations.photos.max' => 4]);

        $p = ListingPayload::make();
        $this->assertSame('GS Construction & Remodeling', $p['name']);
        $this->assertSame('400 N Wheeling Rd', $p['address']['street']);
        $this->assertSame('60070', $p['address']['zip']);
        $this->assertStringContainsString('Mon 8 AM–6 PM', $p['hours_text']);
        $this->assertStringContainsString('Sun closed', $p['hours_text']);
        $this->assertSame('https://gs.construction/', $p['website']);
        $this->assertSame('Patryk', $p['contact']['first_name']);
        $this->assertContains('Kitchen remodeler', $p['categories']);
        $this->assertStringContainsString('Prospect Heights', $p['description']['medium']);
        $this->assertLessThanOrEqual(160, mb_strlen($p['description']['short']));
        $this->assertCount(4, $p['photos'], 'capped at max');
        $this->assertSame('Palatine Kitchen', $p['photos'][0]['project'], 'featured project first');
        $this->assertSame('Wheeling Bath', $p['photos'][3]['project'], 'three per project, then the next project');
        $this->assertStringContainsString('Palatine, IL', $p['photos'][0]['caption']);
        $this->assertStringStartsWith('https://gs.construction/', $p['photos'][0]['url']);
        $this->assertArrayHasKey('BBB', $p['profiles']);
    }

    public function test_sync_registers_every_directory_once_and_keeps_existing_status(): void
    {
        $this->artisan('citations:sync')->assertExitCode(0);
        $this->assertSame(count(config('citations.directories')), Citation::count());
        $c = Citation::where('slug', 'remodelersup')->first();
        $this->assertSame('planned', $c->status);
        $this->assertSame(2, $c->tier);
        $this->assertSame('https://remodelersup.com/signup', $c->start_url);
        $c->update(['status' => 'live', 'listing_url' => 'https://remodelersup.com/gs']);
        $this->artisan('citations:sync')->assertExitCode(0);
        $this->assertSame(count(config('citations.directories')), Citation::count());
        $this->assertSame('live', $c->fresh()->status);

        // A row left "running" by a session nobody polled goes back on the board.
        Citation::where('slug', 'sisgroup')->update(['status' => 'running']);
        $this->artisan('citations:sync')->assertExitCode(0);
        $this->assertSame('planned', Citation::where('slug', 'sisgroup')->value('status'));
    }

    public function test_api_lists_the_board_starts_a_session_through_a_signed_viewer_and_takes_manual_updates(): void
    {
        $fake = new class extends CitationSessionService
        {
            public array $started = [];

            public function checkRequirements(bool $headless = false): array
            {
                return ['ok' => true, 'missing' => []];
            }

            public function start(Citation $citation, bool $headless = false, bool $auto = false): array
            {
                $this->started[] = $citation->slug;

                return ['ok' => true, 'slug' => $citation->slug, 'url' => 'http://127.0.0.1:6080/vnc.html?password=x', 'started_at' => time(), 'expires_at' => time() + 600];
            }

            public function status(): array
            {
                return ['running' => $this->started !== [], 'slug' => $this->started[0] ?? null, 'viewer' => $this->started ? 'http://127.0.0.1:6080/vnc.html?password=x' : null, 'expires_at' => time() + 600, 'runner' => $this->started ? ['phase' => 'waiting_human', 'needs_human' => true, 'reason' => 'Solve the CAPTCHA and submit.', 'log' => [['at' => '2026-09-05 10:00:00', 'msg' => 'prefilled 7 field(s)']], 'shots' => [['file' => '/tmp/01-landing.png', 'label' => 'landing']], 'photos_uploaded' => 0, 'account' => ['email' => 'crew@gs.construction', 'password' => 'Secret123!']] : null];
            }
        };
        $this->app->instance(CitationSessionService::class, $fake);

        $index = $this->getJson('/api/admin/v1/citations', $this->adminApiHeaders())->assertOk()->json('data');
        $this->assertSame(count(config('citations.directories')), count($index['citations']));
        $this->assertFalse($index['session']['running']);
        $this->assertArrayHasKey('inbox_configured', $index);
        $row = collect($index['citations'])->firstWhere('slug', 'remodelersup');
        $this->assertSame('planned', $row['status']);
        $this->assertSame(['email', 'payment'], $row['needs']);

        $start = $this->postJson('/api/admin/v1/citations/remodelersup/start', [], $this->adminApiHeaders())->assertOk()->json('data');
        $this->assertTrue($start['ok']);
        $this->assertStringContainsString('/platforms/citations/viewer', $start['viewer_url'], 'a signed redirect, never the raw noVNC URL');
        $this->assertStringContainsString('signature=', $start['viewer_url']);
        $this->assertSame('running', $start['citation']['status']);
        $this->assertSame(['remodelersup'], $fake->started);

        $poll = $this->postJson('/api/admin/v1/citations/session/poll', [], $this->adminApiHeaders())->assertOk()->json('data');
        $this->assertTrue($poll['session']['running']);
        $this->assertSame('needs_human', $poll['citation']['status']);
        $this->assertSame('Solve the CAPTCHA and submit.', $poll['citation']['human_reason']);
        $this->assertSame('crew@gs.construction', $poll['citation']['account_email']);
        $this->assertTrue($poll['citation']['has_password']);
        $this->assertSame('01-landing.png', $poll['citation']['screenshots'][0]['file']);
        $this->assertSame('Secret123!', Citation::where('slug', 'remodelersup')->first()->account_password, 'stored encrypted, readable through the cast');

        $this->postJson('/api/admin/v1/citations/remodelersup/resume', [], $this->adminApiHeaders())->assertOk();
        $this->assertSame('running', Citation::where('slug', 'remodelersup')->value('status'));

        $updated = $this->patchJson('/api/admin/v1/citations/remodelersup', ['status' => 'live', 'listing_url' => 'https://remodelersup.com/pros/gs-construction'], $this->adminApiHeaders())->assertOk()->json('data');
        $this->assertSame('live', $updated['citation']['status']);
        $this->assertNotNull(Citation::where('slug', 'remodelersup')->value('live_at'));
        $this->patchJson('/api/admin/v1/citations/remodelersup', ['status' => 'bogus'], $this->adminApiHeaders())->assertStatus(422);
        $this->getJson('/api/admin/v1/citations/remodelersup/screenshots/../../etc/passwd', $this->adminApiHeaders())->assertNotFound();
        $this->getJson('/api/admin/v1/citations/remodelersup/screenshots/nope.png', $this->adminApiHeaders())->assertNotFound();
    }

    public function test_link_check_promotes_a_submitted_listing_to_live_and_flags_a_dead_one(): void
    {
        $this->artisan('citations:sync');
        Citation::where('slug', 'remodelersup')->update(['status' => 'submitted', 'listing_url' => 'https://remodelersup.com/pros/gs']);
        Citation::where('slug', 'handyhubb')->update(['status' => 'live', 'listing_url' => 'https://handyhubb.com/biz/gs']);
        Http::fake([
            'remodelersup.com/*' => Http::response('<h1>GS Construction & Remodeling</h1><a href="https://gs.construction/">Website</a>', 200),
            'handyhubb.com/*' => Http::response('gone', 404),
        ]);
        $this->artisan('citations:control', ['action' => 'check'])->assertExitCode(0);
        $r = Citation::where('slug', 'remodelersup')->first();
        $this->assertSame('live', $r->status);
        $this->assertTrue($r->links_to_us);
        $this->assertFalse($r->nofollow);
        $h = Citation::where('slug', 'handyhubb')->first();
        $this->assertSame('failed', $h->status);
        $this->assertStringContainsString('HTTP 404', $h->note);

        $this->assertSame(['status' => 200, 'links_to_us' => 1, 'nofollow' => 0, 'note' => null], LinkCheck::run('https://remodelersup.com/pros/gs', 'gs.construction', ['GS Construction']));
    }

    public function test_verification_links_are_only_the_senders_own_confirm_links(): void
    {
        $body = "Welcome!\nConfirm here: https://app.remodelersup.com/verify?token=abc123&x=1.\nAlso https://remodelersup.com/unsubscribe?u=9 and https://tracker.example.com/confirm?id=5\n<img src=\"https://remodelersup.com/logo.png\">";
        $this->assertSame(['https://app.remodelersup.com/verify?token=abc123&x=1'], VerificationInbox::extractVerificationLinks($body, 'remodelersup.com'));
        $this->assertSame('remodelersup.com', VerificationInbox::registrable('mail.app.remodelersup.com'));
        $this->assertSame('prosgrade.co.uk', VerificationInbox::registrable('mail.prosgrade.co.uk'));
        $this->assertFalse(app(VerificationInbox::class)->isConfigured());
    }

    public function test_sync_imports_the_profiles_we_already_have_and_the_check_sorts_them(): void
    {
        config(['brand.profiles' => ['BBB' => 'https://www.bbb.org/us/il/gs', 'Houzz' => 'https://www.houzz.com/pro/gs', 'Nextdoor' => 'https://nextdoor.com/pages/gs']]);
        $this->artisan('citations:sync')->assertExitCode(0);
        $this->assertSame('https://www.bbb.org/us/il/gs', Citation::where('slug', 'bbb')->value('listing_url'));
        $this->assertSame('https://nextdoor.com/pages/gs', Citation::where('slug', 'nextdoor')->value('listing_url'));
        $this->assertNull(Citation::where('slug', 'remodelersup')->value('listing_url'));

        Http::fake([
            'www.bbb.org/*' => Http::response('<h1>GS Construction & Remodeling, INC.</h1><a href="https://gs.construction/" rel="nofollow">Website</a>', 200),
            'www.houzz.com/*' => Http::response('Access denied', 403),
            'nextdoor.com/*' => Http::response('<h1>GS Construction & Remodeling</h1><p>No website.</p>', 200),
        ]);
        $this->artisan('citations:control', ['action' => 'check'])->assertExitCode(0);
        $this->assertSame('live', Citation::where('slug', 'bbb')->value('status'), 'an existing profile that links to us is a live citation');
        $this->assertSame('needs_human', Citation::where('slug', 'nextdoor')->value('status'), 'exists but no link: a person adds the website');
        $this->assertStringContainsString('add https://gs.construction', Citation::where('slug', 'nextdoor')->value('human_reason'));
        $this->assertSame('planned', Citation::where('slug', 'houzz')->value('status'), 'blocked by a bot wall: nothing concluded');
    }

    public function test_batch_runs_every_open_directory_automatically_and_parks_what_needs_a_person(): void
    {
        $this->artisan('citations:sync');
        Citation::where('slug', 'handyhubb')->update(['status' => 'live', 'listing_url' => 'https://handyhubb.com/biz/gs']);
        Citation::where('slug', 'prosgrade')->update(['status' => 'declined']);

        $fake = new class extends CitationSessionService
        {
            public array $started = [];

            protected ?string $current = null;

            public function checkRequirements(bool $headless = false): array
            {
                return ['ok' => true, 'missing' => []];
            }

            public function start(Citation $citation, bool $headless = false, bool $auto = false): array
            {
                $this->started[] = [$citation->slug, $headless, $auto];
                $this->current = $citation->slug;
                // The runner's own state file, as automatic mode leaves it.
                $dir = $this->dirFor($citation);
                $state = $citation->slug === 'remodelersup'
                    ? ['phase' => 'done', 'done' => true, 'outcome' => 'done', 'listing_url' => 'https://remodelersup.com/pros/gs', 'note' => 'Submitted — the site said "verify your email".', 'photos_uploaded' => 6, 'log' => [], 'shots' => [], 'account' => ['email' => 'crew@gs.construction', 'password' => 'Pw1!']]
                    : ['phase' => 'needs_human', 'needs_human' => true, 'done' => true, 'outcome' => 'needs_human', 'reason' => 'A CAPTCHA (recaptcha) guards this form.', 'log' => [], 'shots' => [['file' => $dir . '/shots/01-landing.png', 'label' => 'landing']]];
                file_put_contents($dir . '/state.json', json_encode($state));

                return ['ok' => true, 'slug' => $citation->slug, 'url' => null, 'started_at' => time(), 'expires_at' => time() + 240];
            }

            public function status(): array
            {
                return ['running' => false, 'slug' => $this->current, 'runner' => null];
            }
        };
        $this->app->instance(CitationSessionService::class, $fake);

        $this->artisan('citations:batch', ['--tier' => [2]])->expectsOutputToContain('Done:')->assertExitCode(0);

        $ran = array_column($fake->started, 0);
        $this->assertContains('remodelersup', $ran);
        $this->assertContains('excellentcontractor', $ran);
        $this->assertNotContains('handyhubb', $ran, 'live rows are skipped');
        $this->assertNotContains('prosgrade', $ran, 'declined rows are skipped');
        $this->assertNotContains('bing_places', $ran, 'other tiers are skipped');
        $this->assertTrue(collect($fake->started)->every(fn ($s) => $s[1] === true && $s[2] === true), 'batch runs are headless and automatic');

        $r = Citation::where('slug', 'remodelersup')->first();
        $this->assertSame('pending_verification', $r->status, 'a submitted form that needs email verification waits for the inbox');
        $this->assertSame('https://remodelersup.com/pros/gs', $r->listing_url);
        $this->assertSame(6, $r->photos_uploaded);
        $this->assertSame('Pw1!', $r->account_password);

        $e = Citation::where('slug', 'excellentcontractor')->first();
        $this->assertSame('needs_human', $e->status);
        $this->assertStringContainsString('CAPTCHA', $e->human_reason);
        $this->assertSame('01-landing.png', $e->screenshots[0]['file']);

        // The API queues the same list and reports progress on the board.
        $this->app->instance(CitationSessionService::class, $fake);
        \Illuminate\Support\Facades\Queue::fake();
        $queued = $this->postJson('/api/admin/v1/citations/batch', ['tiers' => [3]], $this->adminApiHeaders())->assertOk()->json('data');
        $this->assertTrue($queued['ok']);
        $this->assertContains('zermit', $queued['slugs']);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunCitationsBatch::class, fn ($job) => $job->slugs === $queued['slugs']);
        $this->assertTrue($this->getJson('/api/admin/v1/citations', $this->adminApiHeaders())->json('data.batch.active'));
        // A second "run all" while one is going is refused rather than interleaved.
        $again = $this->postJson('/api/admin/v1/citations/batch', [], $this->adminApiHeaders())->assertOk()->json('data');
        $this->assertFalse($again['ok']);
        $this->assertStringContainsString('already going', $again['error']);

        // Rows that failed only because the slot was busy, and bot walls, are sorted out by sync.
        Citation::where('slug', 'manta')->update(['status' => 'failed', 'note' => 'Another citation session is running (facebook). Stop it first.']);
        Citation::where('slug', 'angi')->update(['status' => 'unreachable', 'note' => 'https://www.angi.com/x returned HTTP 403.']);
        $this->artisan('citations:sync')->assertExitCode(0);
        $this->assertSame('planned', Citation::where('slug', 'manta')->value('status'));
        $this->assertSame('needs_human', Citation::where('slug', 'angi')->value('status'));
        $this->assertStringContainsString('HTTP 403', Citation::where('slug', 'angi')->value('human_reason'));
    }
}
