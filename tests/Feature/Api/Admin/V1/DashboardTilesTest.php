<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\BingDailyTotal;
use App\Models\ContactSubmission;
use App\Models\GscDailyTotal;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\Testimonial;
use App\Models\TrackedEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * The generic trend-tile row every tenant's dashboard renders (owner's
 * rule: "dashboards show trends, not lone numbers") — the COMMON four
 * (leads, contacts, search_clicks, reviews) plus this site's own
 * (projects, photos). See ss-systems' Dashboard blade for the consumer.
 */
class DashboardTilesTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
        Carbon::setTestNow('2026-09-25 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * created_at is deliberately not mass-assignable on these models, so a
     * backdated fixture needs forceFill() after create() to stick.
     */
    protected function backdate(Model $model, $createdAt): Model
    {
        $model->forceFill(['created_at' => $createdAt])->save();

        return $model;
    }

    public function test_tiles_are_present_in_fixed_order_with_exact_keys_and_labels(): void
    {
        $tiles = $this->getJson('/api/admin/v1/dashboard-stats', $this->adminApiHeaders())
            ->assertOk()->json('data.tiles');

        $this->assertSame([
            'leads', 'contacts', 'search_clicks', 'reviews', 'projects', 'photos',
        ], collect($tiles)->pluck('key')->all());

        $this->assertSame([
            'Leads (7 days)',
            'Calls, emails & forms (7 days)',
            'Search clicks (7 days)',
            'Reviews',
            'Projects',
            'Photos',
        ], collect($tiles)->pluck('label')->all());

        $this->assertSame([
            'leads', 'analytics', 'seo', 'reviews', 'projects', 'projects',
        ], collect($tiles)->pluck('href')->all());

        foreach ($tiles as $tile) {
            $this->assertArrayHasKey('value', $tile);
            $this->assertArrayHasKey('note', $tile);
            $this->assertArrayHasKey('delta_pct', $tile);
        }
    }

    public function test_existing_dashboard_stats_keys_are_unchanged(): void
    {
        $data = $this->getJson('/api/admin/v1/dashboard-stats', $this->adminApiHeaders())
            ->assertOk()->json('data');

        foreach ([
            'projects', 'testimonials', 'images', 'tags', 'leads',
            'recent_projects', 'recent_leads', 'automation', 'tiles',
        ] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
    }

    public function test_leads_tile_counts_last_7_days_excluding_spam_with_delta_and_total_note(): void
    {
        // Current window (last 7 days): 2 real, 1 spam (excluded).
        $this->backdate(ContactSubmission::create(['name' => 'A', 'email' => 'a@example.com', 'phone' => '111', 'message' => 'hi', 'status' => 'pending']), now()->subDays(1));
        $this->backdate(ContactSubmission::create(['name' => 'B', 'email' => 'b@example.com', 'phone' => '111', 'message' => 'hi', 'status' => 'legitimate']), now()->subDays(3));
        $this->backdate(ContactSubmission::create(['name' => 'S', 'email' => 's@example.com', 'phone' => '111', 'message' => 'hi', 'status' => 'spam']), now()->subDays(2));

        // Prior 7 days: 1 real.
        $this->backdate(ContactSubmission::create(['name' => 'C', 'email' => 'c@example.com', 'phone' => '111', 'message' => 'hi', 'status' => 'pending']), now()->subDays(10));

        $tile = collect(
            $this->getJson('/api/admin/v1/dashboard-stats', $this->adminApiHeaders())->assertOk()->json('data.tiles')
        )->firstWhere('key', 'leads');

        $this->assertSame(2, $tile['value']);
        $this->assertSame('3 total', $tile['note']); // 3 non-spam leads all-time
        $this->assertEquals(100.0, $tile['delta_pct']); // 2 vs 1
        $this->assertSame('leads', $tile['href']);
    }

    public function test_leads_tile_delta_is_null_when_prior_window_is_zero(): void
    {
        $this->backdate(ContactSubmission::create(['name' => 'A', 'email' => 'a@example.com', 'phone' => '111', 'message' => 'hi', 'status' => 'pending']), now()->subDays(1));

        $tile = collect(
            $this->getJson('/api/admin/v1/dashboard-stats', $this->adminApiHeaders())->assertOk()->json('data.tiles')
        )->firstWhere('key', 'leads');

        $this->assertSame(1, $tile['value']);
        $this->assertNull($tile['delta_pct']);
    }

    public function test_contacts_tile_reuses_analytics_counts_and_excludes_cta(): void
    {
        // Current window.
        $this->backdate(TrackedEvent::create(['type' => TrackedEvent::TYPE_PHONE_CLICK]), now()->subDays(1));
        $this->backdate(TrackedEvent::create(['type' => TrackedEvent::TYPE_EMAIL_CLICK]), now()->subDays(2));
        $this->backdate(TrackedEvent::create(['type' => TrackedEvent::TYPE_FORM_SUBMIT]), now()->subDays(3));
        $this->backdate(TrackedEvent::create(['type' => TrackedEvent::TYPE_CTA_CLICK]), now()->subDays(3));

        // Prior window: one phone click only.
        $this->backdate(TrackedEvent::create(['type' => TrackedEvent::TYPE_PHONE_CLICK]), now()->subDays(10));

        $tile = collect(
            $this->getJson('/api/admin/v1/dashboard-stats', $this->adminApiHeaders())->assertOk()->json('data.tiles')
        )->firstWhere('key', 'contacts');

        $this->assertSame(3, $tile['value']); // phone + email + form, cta excluded
        $this->assertNull($tile['note']);
        $this->assertEquals(200.0, $tile['delta_pct']); // 3 vs 1
        $this->assertSame('analytics', $tile['href']);
    }

    public function test_search_clicks_tile_uses_newest_7_days_present_not_today(): void
    {
        // Search Console lags: newest data is 3 days old ("today" minus 3).
        $newest = now()->copy()->subDays(3)->startOfDay();

        // Current window: the 7 days ending at $newest.
        for ($i = 0; $i < 7; $i++) {
            GscDailyTotal::create([
                'date' => $newest->copy()->subDays($i)->toDateString(),
                'site_url' => 'https://example.com/',
                'clicks' => 10,
                'impressions' => 100,
            ]);
            BingDailyTotal::create([
                'date' => $newest->copy()->subDays($i)->toDateString(),
                'site_url' => 'https://example.com/',
                'clicks' => 1,
                'impressions' => 5,
            ]);
        }

        // Prior window: the 7 days before that, half the clicks (for a clean delta).
        for ($i = 7; $i < 14; $i++) {
            GscDailyTotal::create([
                'date' => $newest->copy()->subDays($i)->toDateString(),
                'site_url' => 'https://example.com/',
                'clicks' => 5,
                'impressions' => 50,
            ]);
        }

        $tile = collect(
            $this->getJson('/api/admin/v1/dashboard-stats', $this->adminApiHeaders())->assertOk()->json('data.tiles')
        )->firstWhere('key', 'search_clicks');

        // 7 days * (10 gsc + 1 bing) = 77 clicks; 7 * (100 + 5) = 735 impressions.
        $this->assertSame(77, $tile['value']);
        $this->assertSame('735 impressions', $tile['note']);
        // prior: 7 * 5 = 35 gsc clicks, no bing rows in prior window.
        $this->assertEquals(round((77 - 35) / 35 * 100, 1), $tile['delta_pct']);
        $this->assertSame('seo', $tile['href']);
    }

    public function test_search_clicks_tile_is_zero_with_no_data(): void
    {
        $tile = collect(
            $this->getJson('/api/admin/v1/dashboard-stats', $this->adminApiHeaders())->assertOk()->json('data.tiles')
        )->firstWhere('key', 'search_clicks');

        $this->assertSame(0, $tile['value']);
        $this->assertSame('0 impressions', $tile['note']);
        $this->assertNull($tile['delta_pct']);
    }

    public function test_reviews_tile_counts_visible_testimonials_with_30_day_delta(): void
    {
        $this->backdate(Testimonial::create(['reviewer_name' => 'Old', 'review_description' => 'x', 'is_hidden' => false]), now()->subDays(100));
        $this->backdate(Testimonial::create(['reviewer_name' => 'New', 'review_description' => 'x', 'is_hidden' => false]), now()->subDays(5));
        $this->backdate(Testimonial::create(['reviewer_name' => 'Hidden', 'review_description' => 'x', 'is_hidden' => true]), now()->subDays(5));

        $tile = collect(
            $this->getJson('/api/admin/v1/dashboard-stats', $this->adminApiHeaders())->assertOk()->json('data.tiles')
        )->firstWhere('key', 'reviews');

        $this->assertSame(2, $tile['value']); // visible only
        $this->assertSame('+1 in 30 days', $tile['note']);
        $this->assertNull($tile['delta_pct']); // 1 vs 0 prior
        $this->assertSame('reviews', $tile['href']);
    }

    public function test_projects_tile_counts_published_projects_with_30_day_delta(): void
    {
        $this->backdate(Project::create(['title' => 'Old', 'slug' => 'old-p', 'project_type' => 'kitchen', 'is_published' => true]), now()->subDays(100));
        $this->backdate(Project::create(['title' => 'New', 'slug' => 'new-p', 'project_type' => 'kitchen', 'is_published' => true]), now()->subDays(5));
        $this->backdate(Project::create(['title' => 'Draft', 'slug' => 'draft-p', 'project_type' => 'kitchen', 'is_published' => false]), now()->subDays(5));

        $tile = collect(
            $this->getJson('/api/admin/v1/dashboard-stats', $this->adminApiHeaders())->assertOk()->json('data.tiles')
        )->firstWhere('key', 'projects');

        $this->assertSame(2, $tile['value']); // published only
        $this->assertSame('+1 in 30 days', $tile['note']);
        $this->assertNull($tile['delta_pct']);
        $this->assertSame('projects', $tile['href']);
    }

    public function test_photos_tile_counts_project_images_with_no_google_note(): void
    {
        $project = Project::create(['title' => 'P', 'slug' => 'p-photos', 'project_type' => 'kitchen', 'is_published' => true]);
        ProjectImage::create(['project_id' => $project->id, 'filename' => 'a.jpg', 'original_filename' => 'a.jpg', 'path' => 'a.jpg']);
        ProjectImage::create(['project_id' => $project->id, 'filename' => 'b.jpg', 'original_filename' => 'b.jpg', 'path' => 'b.jpg']);

        $tile = collect(
            $this->getJson('/api/admin/v1/dashboard-stats', $this->adminApiHeaders())->assertOk()->json('data.tiles')
        )->firstWhere('key', 'photos');

        $this->assertSame(2, $tile['value']);
        // gsc has no local GBP-upload ledger of its own (ss-systems owns it) — null, not a guess.
        $this->assertNull($tile['note']);
        $this->assertNull($tile['delta_pct']);
        $this->assertSame('projects', $tile['href']);
    }
}
