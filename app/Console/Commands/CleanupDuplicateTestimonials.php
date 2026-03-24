<?php

namespace App\Console\Commands;

use App\Models\ReviewUrl;
use App\Models\Testimonial;
use Illuminate\Console\Command;

class CleanupDuplicateTestimonials extends Command
{
    protected $signature = 'testimonials:cleanup-duplicates
        {--dry-run : Show what would be changed without writing to DB}';

    protected $description = 'Merge duplicate GBP-synced testimonials into their manually-added counterparts and remove generic Google URLs.';

    /**
     * Known duplicate pairs: [gbp_id => manual_id]
     * Verified by matching review text content.
     */
    protected const DUPLICATE_PAIRS = [
        89 => 86,   // Maribeth Seisser → Maribeth S
        95 => 46,   // Mike McHugh → Kathy & Mike M
        96 => 50,   // Barb Drummond → Barb D
        97 => 44,   // Carolyne Krupa → Carrie & John K
        98 => 42,   // Trip Finnegan → Trip F
        99 => 34,   // Teresa McMillin → Teresa M
        100 => 23,  // Doug Karmazin → Doug K
        101 => 12,  // Erin Abbey → Erin A
        102 => 84,  // Janine Sanderson → Janine S
        104 => 87,  // Evan H → Evan H
    ];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $prefix = $dryRun ? '[DRY RUN] ' : '';

        // ── Phase 1: Merge duplicates ──
        $this->info($prefix . 'Phase 1: Merging duplicate testimonials...');
        $merged = 0;

        foreach (self::DUPLICATE_PAIRS as $gbpId => $manualId) {
            $gbp = Testimonial::find($gbpId);
            $manual = Testimonial::find($manualId);

            if (! $gbp) {
                $this->line("  GBP T#{$gbpId} already deleted, skipping.");
                continue;
            }

            if (! $manual) {
                $this->warn("  Manual T#{$manualId} not found — skipping merge for T#{$gbpId} [{$gbp->reviewer_name}].");
                continue;
            }

            $this->line("{$prefix}Merge: T#{$gbpId} [{$gbp->reviewer_name}] → T#{$manualId} [{$manual->reviewer_name}]");

            if (! $dryRun) {
                $transferGid = ! $manual->google_review_id && $gbp->google_review_id;
                $transferRating = ! $manual->star_rating && $gbp->star_rating;

                $gid = $gbp->google_review_id;
                $rating = $gbp->star_rating;

                // Delete GBP entry first (to avoid unique constraint on google_review_id)
                $gbp->reviewUrls()->delete();
                $gbp->delete();

                // Then transfer fields to the manual entry
                if ($transferGid) {
                    $manual->google_review_id = $gid;
                }
                if ($transferRating) {
                    $manual->star_rating = $rating;
                }
                $manual->save();
            }

            $merged++;
        }

        $this->info("{$prefix}Merged: {$merged} duplicate(s).");

        // ── Phase 2: Remove generic Google URLs ──
        $this->newLine();
        $this->info($prefix . 'Phase 2: Removing generic Google review URLs...');

        $genericUrls = ReviewUrl::where('url', 'like', '%search.google.com/local/reviews%')->get();
        $genericCount = $genericUrls->count();

        if ($genericCount > 0) {
            foreach ($genericUrls as $url) {
                $name = $url->testimonial->reviewer_name ?? 'unknown';
                $this->line("{$prefix}Remove generic URL from T#{$url->testimonial_id} [{$name}]");
            }

            if (! $dryRun) {
                ReviewUrl::where('url', 'like', '%search.google.com/local/reviews%')->delete();
            }
        }

        $this->info("{$prefix}Removed: {$genericCount} generic URL(s).");

        // ── Summary ──
        $this->newLine();
        $this->info("{$prefix}Summary:");
        $this->line("  Duplicates merged: {$merged}");
        $this->line("  Generic URLs removed: {$genericCount}");

        $remaining = Testimonial::count();
        $withGid = Testimonial::whereNotNull('google_review_id')->count();
        $deepLinks = ReviewUrl::where('url', 'like', '%google.com/maps/reviews%')->count();
        $this->line("  Total testimonials: {$remaining}");
        $this->line("  With google_review_id: {$withGid}");
        $this->line("  With deep link URL: {$deepLinks}");

        return self::SUCCESS;
    }
}
