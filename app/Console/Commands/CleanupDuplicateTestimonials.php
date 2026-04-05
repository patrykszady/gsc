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
                $manualGoogleUrl = $manual->reviewUrls->firstWhere('platform', 'google');
                $gbpGoogleUrl = $gbp->reviewUrls->firstWhere('platform', 'google');
                $transferGid = (! $manualGoogleUrl?->external_id) && ($gbpGoogleUrl?->external_id);
                $transferRating = ! $manual->star_rating && $gbp->star_rating;

                $gid = $gbpGoogleUrl?->external_id;
                $rating = $gbp->star_rating;
                $gbpName = $gbp->reviewer_name;

                // Transfer GBP review URLs to the manual entry
                // For each platform, prefer the deep-link URL over the generic one
                $manualUrlsByPlatform = $manual->reviewUrls->keyBy('platform');

                foreach ($gbp->reviewUrls as $gbpUrl) {
                    $existing = $manualUrlsByPlatform->get($gbpUrl->platform);

                    if (! $existing) {
                        // No conflict — move the URL over
                        $gbpUrl->update(['testimonial_id' => $manual->id]);
                    } elseif (str_contains($gbpUrl->url, 'google.com/maps/reviews') && str_contains($existing->url, 'search.google.com/local/reviews')) {
                        // GBP has deep-link, manual has generic — replace with deep-link
                        $existing->update([
                            'url' => $gbpUrl->url,
                            'external_id' => $existing->external_id ?: $gbpUrl->external_id,
                        ]);
                        $manualUrlsByPlatform->put($gbpUrl->platform, $existing->fresh());
                        $gbpUrl->delete();
                    } elseif ($gbpUrl->platform === 'google' && ! $existing->external_id && $gbpUrl->external_id) {
                        // Preserve Google external_id if the kept URL is missing it.
                        $existing->update(['external_id' => $gbpUrl->external_id]);
                        $manualUrlsByPlatform->put($gbpUrl->platform, $existing->fresh());
                        $gbpUrl->delete();
                    } else {
                        // Manual already has a good URL for this platform — discard GBP's
                        $gbpUrl->delete();
                    }
                }

                // Delete GBP entry after URL transfer.
                $gbp->delete();

                // Then transfer fields to the manual entry
                // Use the GBP full name if it's longer (more complete) than the manual name
                if (mb_strlen($gbpName) > mb_strlen($manual->reviewer_name)) {
                    $manual->reviewer_name = $gbpName;
                }
                if ($transferGid && $gid) {
                    $manualGoogleUrl = $manual->reviewUrls()->where('platform', 'google')->first();
                    if ($manualGoogleUrl && ! $manualGoogleUrl->external_id) {
                        $manualGoogleUrl->update(['external_id' => $gid]);
                    }
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
        $this->info($prefix . 'Phase 2: Removing generic Google review URLs (without external_id)...');

        $genericUrls = ReviewUrl::where('platform', 'google')
            ->where(function ($q) {
                $q->whereNull('external_id')->orWhere('external_id', '');
            })
            ->get();
        $genericCount = $genericUrls->count();

        if ($genericCount > 0) {
            foreach ($genericUrls as $url) {
                $name = $url->testimonial->reviewer_name ?? 'unknown';
                $this->line("{$prefix}Remove generic URL from T#{$url->testimonial_id} [{$name}]");
            }

            if (! $dryRun) {
                ReviewUrl::where('platform', 'google')
                    ->where(function ($q) {
                        $q->whereNull('external_id')->orWhere('external_id', '');
                    })
                    ->delete();
            }
        }

        $this->info("{$prefix}Removed: {$genericCount} generic URL(s).");

        // ── Phase 3: Fix mojibake-encoded text ──
        $this->newLine();
        $this->info($prefix . 'Phase 3: Fixing mojibake-encoded text...');
        $encodingFixed = 0;

        // Common mojibake sequences from UTF-8 being read as Windows-1252
        $mojibakeMap = [
            'â€œ' => "\u{201C}",  // left double quote "
            'â€\x9D' => "\u{201D}",  // right double quote "
            "â€\u{9D}" => "\u{201D}",  // right double quote (alt)
            'â€™' => "\u{2019}",  // right single quote '
            'â€˜' => "\u{2018}",  // left single quote '
            'â€"' => "\u{2013}",  // en dash –
            'â€"' => "\u{2014}",  // em dash —
            "\xc2\x9d" => '',      // stray U+009D control char (right quote remnant)
        ];

        $fields = ['reviewer_name', 'review_description'];

        foreach ($fields as $field) {
            $badRows = Testimonial::where($field, 'like', '%â€%')
                ->orWhereRaw("CAST({$field} AS BINARY) LIKE ?", ['%' . "\xc2\x9d" . '%'])
                ->get();
            foreach ($badRows as $testimonial) {
                $original = $testimonial->$field;
                $fixed = str_replace(array_keys($mojibakeMap), array_values($mojibakeMap), $original);

                // If str_replace didn't fully fix it, try mb_convert_encoding
                if (str_contains($fixed, 'â€') || str_contains($fixed, 'Ã')) {
                    $fixed = mb_convert_encoding($original, 'UTF-8', 'Windows-1252');
                }

                if ($fixed !== $original) {
                    $label = $field === 'reviewer_name' ? $original : 'review body';
                    $this->line("{$prefix}  T#{$testimonial->id} [{$field}]: {$label}");
                    if (! $dryRun) {
                        $testimonial->$field = $fixed;
                        $testimonial->save();
                    }
                    $encodingFixed++;
                }
            }
        }

        $this->info("{$prefix}Fixed: {$encodingFixed} mojibake field(s).");

        // ── Summary ──
        $this->newLine();
        $this->info("{$prefix}Summary:");
        $this->line("  Duplicates merged: {$merged}");
        $this->line("  Generic URLs removed: {$genericCount}");
        $this->line("  Mojibake names fixed: {$encodingFixed}");

        $remaining = Testimonial::count();
        $withGid = ReviewUrl::where('platform', 'google')->whereNotNull('external_id')->where('external_id', '!=', '')->count();
        $deepLinks = ReviewUrl::where('url', 'like', '%google.com/maps/reviews%')->count();
        $this->line("  Total testimonials: {$remaining}");
        $this->line("  With Google external_id: {$withGid}");
        $this->line("  With deep link URL: {$deepLinks}");

        return self::SUCCESS;
    }
}
