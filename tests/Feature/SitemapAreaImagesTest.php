<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use App\Models\Project;
use App\Models\ProjectImage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Area pages must declare the project photos they render.
 *
 * The sitemap used to attach <image:image> only to project and photo pages:
 * 268 of the site's 701 URLs carried images and 433 carried none, including
 * every city page — despite an area page rendering a strip of ~19 real project
 * photos. Google Images had no route to the local pages at all, which is where
 * "kitchen remodeling <town>" traffic is won.
 */
class SitemapAreaImagesTest extends TestCase
{
    /** @return array<string, int> loc => number of <image:loc> children */
    private function generate(): array
    {
        $this->artisan('sitemap:generate', ['--url' => 'https://gs.construction'])
            ->assertSuccessful();

        $xml = (string) file_get_contents(public_path('sitemap.xml'));

        $counts = [];
        preg_match_all('#<url>(.*?)</url>#s', $xml, $blocks);
        foreach ($blocks[1] as $block) {
            preg_match('#<loc>(.*?)</loc>#s', $block, $loc);
            $counts[$loc[1] ?? ''] = substr_count($block, '<image:loc>');
        }

        return $counts;
    }

    /**
     * Built, not borrowed — a test that quietly passes on an empty database
     * would prove nothing about the thing that regressed.
     */
    private function seedAreaWithLocalProject(): AreaServed
    {
        // ProjectImage::$url resolves to null unless the file is really on the
        // public disk, and a null URL is skipped — so the fixture needs a file.
        Storage::fake('public');

        $area = AreaServed::firstOrCreate(
            ['slug' => 'sitemap-test-city'],
            [
                'city' => 'Sitemap Test City',
                // AreaSeoPolicy only indexes an area landing page that carries
                // its own copy — without this the URL is absent from the sitemap
                // altogether and the assertion below would test nothing.
                'local_intro' => 'Remodeling work completed across Sitemap Test City, IL.',
            ],
        );

        $project = Project::firstOrCreate(
            ['slug' => 'sitemap-test-project'],
            [
                'title' => 'Sitemap Test Kitchen',
                'location' => $area->city . ', IL',
                'project_type' => 'kitchen',
                'is_published' => true,
            ],
        );

        ProjectImage::firstOrCreate(
            ['project_id' => $project->id, 'filename' => 'sitemap-test.jpg'],
            [
                'original_filename' => 'sitemap-test.jpg',
                'path' => 'projects/sitemap-test/sitemap-test.jpg',
                'alt_text' => 'Sitemap test kitchen remodel',
                // The per-photo page URL is built from this slug.
                'slug' => 'sitemap-test-kitchen-photo',
                'is_cover' => true,
                'sort_order' => 0,
            ],
        );

        Storage::disk('public')->put('projects/sitemap-test/sitemap-test.jpg', 'fixture');

        // localProjects()/nearbyProjects() are cached 6h; the rows above were
        // written after that cache could have been warmed.
        Cache::flush();

        return $area;
    }

    public function test_an_area_page_declares_the_project_photos_it_renders(): void
    {
        $area = $this->seedAreaWithLocalProject();

        $counts = $this->generate();
        $loc = "https://gs.construction/areas-served/{$area->slug}";

        $this->assertArrayHasKey($loc, $counts, 'the area page is missing from the sitemap entirely');
        $this->assertGreaterThan(
            0,
            $counts[$loc],
            'the area page renders project photos but declares none to Google Images',
        );
    }

    public function test_photo_pages_still_declare_their_image(): void
    {
        $this->seedAreaWithLocalProject();

        $withImages = array_filter($this->generate(), fn (int $n) => $n > 0);

        $photoPages = array_filter(
            array_keys($withImages),
            fn (string $loc) => str_contains($loc, '/photos/'),
        );

        $this->assertNotEmpty($photoPages, 'photo pages lost their image tags');
    }
}
