<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Services\Social\GbpPostTheme;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Google Business Profile posts follow a weekly theme: the season, the
 * service rising in Google Trends (else a rotation) and a rotated core
 * town — and the picker prefers a photo that matches it.
 */
class GbpPostThemeTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_theme_rotates_towns_and_services_and_follows_a_rising_trend(): void
    {
        foreach (['Palatine', 'Arlington Heights', 'Barrington'] as $i => $town) {
            AreaServed::create(['city' => $town, 'slug' => \Illuminate\Support\Str::slug($town)]);
            for ($n = 0; $n <= $i; $n++) {
                Project::create(['title' => "{$town} project {$n}", 'slug' => \Illuminate\Support\Str::slug("{$town} project {$n}"), 'project_type' => 'kitchen', 'location' => "{$town}, IL", 'is_published' => true, 'completed_at' => now()]);
            }
        }
        Carbon::setTestNow('2026-01-12 09:00:00'); // ISO week 3, winter
        $theme = app(GbpPostTheme::class)->forWeek();
        $this->assertSame('winter', $theme['season']);
        $this->assertContains($theme['town'], ['Palatine', 'Arlington Heights', 'Barrington']);
        $this->assertSame(GbpPostTheme::SERVICE_ROTATION[3 % 5], $theme['service_type'], 'rotation when nothing is rising');
        $this->assertStringContainsString('indoor-project season', $theme['note']);

        // Bathroom searches are 40% above their yearly average → bathroom wins the week.
        DB::table('seo_intel_snapshots')->insert([
            ['site_id' => null, 'family' => 'trends', 'kind' => 'phrase', 'subject' => 'bathroom remodel', 'taken_on' => '2026-01-10', 'run_id' => 'r', 'metrics' => json_encode(['interest_now' => 70, 'interest_avg_12m' => 50, 'interest_4w_avg' => 70, 'peak_index' => 90]), 'payload' => '{}', 'created_at' => now(), 'updated_at' => now()],
            ['site_id' => null, 'family' => 'trends', 'kind' => 'phrase', 'subject' => 'kitchen remodel', 'taken_on' => '2026-01-10', 'run_id' => 'r', 'metrics' => json_encode(['interest_now' => 50, 'interest_avg_12m' => 50, 'interest_4w_avg' => 52, 'peak_index' => 80]), 'payload' => '{}', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $theme = app(GbpPostTheme::class)->forWeek();
        $this->assertSame('bathroom', $theme['service_type']);
        $this->assertSame('bathroom remodel', $theme['rising_phrase']);
        $this->assertStringContainsString('"bathroom remodel" are rising', $theme['note']);
    }

    public function test_the_poster_prefers_a_photo_matching_the_weeks_service_and_town(): void
    {
        Carbon::setTestNow('2026-09-14 09:00:00'); // ISO week 38: even, so the first core town (most projects) is up
        Storage::fake('public');
        AreaServed::create(['city' => 'Palatine', 'slug' => 'palatine']);
        $make = function (string $title, string $type, string $town) {
            $p = Project::create(['title' => $title, 'slug' => \Illuminate\Support\Str::slug($title), 'project_type' => $type, 'location' => "{$town}, IL", 'is_published' => true, 'completed_at' => now()]);
            Storage::disk('public')->put("projects/{$p->slug}.jpg", 'x');

            return ProjectImage::create(['project_id' => $p->id, 'filename' => "{$p->slug}.jpg", 'original_filename' => 'a.jpg', 'path' => "projects/{$p->slug}.jpg", 'mime_type' => 'image/jpeg', 'size' => 10, 'width' => 1600, 'height' => 1200, 'alt_text' => "{$title} photo", 'sort_order' => 1]);
        };
        $make('Palatine Kitchen', 'kitchen', 'Palatine');
        $bath = $make('Palatine Bath', 'bathroom', 'Palatine');
        $make('Wheeling Bath', 'bathroom', 'Wheeling');
        DB::table('seo_intel_snapshots')->insert(['site_id' => null, 'family' => 'trends', 'kind' => 'phrase', 'subject' => 'bathroom remodel', 'taken_on' => now()->toDateString(), 'run_id' => 'r', 'metrics' => json_encode(['interest_avg_12m' => 50, 'interest_4w_avg' => 75]), 'payload' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        \Illuminate\Support\Facades\Artisan::call('social:post', ['--platform' => 'google_business', '--themed' => true, '--dry-run' => true, '--yes' => true]);
        $out = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('Theme:', $out);
        $this->assertStringContainsString("Selected image: #{$bath->id}", $out);
    }
}
