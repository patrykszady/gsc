<?php

namespace App\Console\Commands;

use App\Models\Testimonial;
use Illuminate\Console\Command;

class RemoveDuplicateTestimonials extends Command
{
    protected $signature = 'testimonials:remove-duplicates
        {--dry-run : Show duplicates without deleting}';

    protected $description = 'Find and merge/remove duplicate testimonials. Combines review URLs from different sources before removing duplicates.';

    public function handle(): int
    {
        $this->info('Scanning for duplicate testimonials...');

        $totalMerged = 0;
        $totalRemoved = 0;

        // Load all testimonials with their review URLs
        $testimonials = Testimonial::with('reviewUrls')->get();

        // Group by normalized name + review_date to catch cross-format duplicates
        // e.g. "Maribeth Seisser" and "Maribeth S." on the same date = same person
        $groups = $testimonials->groupBy(function (Testimonial $t) {
            return $this->normalizeName($t->reviewer_name) . '|' . ($t->review_date?->toDateString() ?? 'no-date');
        })->filter(fn ($group) => $group->count() > 1);

        foreach ($groups as $key => $group) {
            // Keep the oldest record (lowest ID)
            $sorted = $group->sortBy('id');
            $keep = $sorted->first();
            $duplicates = $sorted->slice(1);

            foreach ($duplicates as $dup) {
                $urlsMerged = $this->mergeReviewUrls($keep, $dup);
                $totalMerged += $urlsMerged;

                if ($this->option('dry-run')) {
                    $this->line("[DRY RUN] Would merge #{$dup->id} \"{$dup->reviewer_name}\" into #{$keep->id} \"{$keep->reviewer_name}\" ({$urlsMerged} URL(s) transferred)");
                } else {
                    $dup->reviewUrls()->delete();
                    $dup->delete();
                    $this->line("Merged #{$dup->id} \"{$dup->reviewer_name}\" → #{$keep->id} \"{$keep->reviewer_name}\" ({$urlsMerged} URL(s) transferred)");
                }
                $totalRemoved++;
            }


        }

        // Also check for duplicate Google external_id (regardless of name)
        $googleGroups = $testimonials->filter(function (Testimonial $t) {
            $googleExternalId = $t->reviewUrls->firstWhere('platform', 'google')?->external_id;

            return filled($googleExternalId);
        })
            ->groupBy(fn (Testimonial $t) => $t->reviewUrls->firstWhere('platform', 'google')?->external_id)
            ->filter(fn ($group) => $group->count() > 1);

        foreach ($googleGroups as $group) {
            $sorted = $group->sortBy('id');
            $keep = $sorted->first();
            $duplicates = $sorted->slice(1);

            foreach ($duplicates as $dup) {
                // Skip if already deleted in the name+date pass
                if (! Testimonial::find($dup->id)) {
                    continue;
                }

                $urlsMerged = $this->mergeReviewUrls($keep, $dup);
                $totalMerged += $urlsMerged;
                $googleExternalId = $dup->reviewUrls->firstWhere('platform', 'google')?->external_id;

                if ($this->option('dry-run')) {
                    $this->line("[DRY RUN] Would merge #{$dup->id} (dup Google external_id: {$googleExternalId}, {$urlsMerged} URL(s))");
                } else {
                    $dup->reviewUrls()->delete();
                    $dup->delete();
                    $this->line("Merged #{$dup->id} (dup Google external_id: {$googleExternalId}, {$urlsMerged} URL(s) transferred)");
                }
                $totalRemoved++;
            }
        }

        $this->newLine();
        $prefix = $this->option('dry-run') ? '[DRY RUN] Would' : '';
        $this->info(trim("{$prefix} Removed {$totalRemoved} duplicate(s), transferred {$totalMerged} review URL(s)."));

        return self::SUCCESS;
    }

    /**
     * Transfer review URLs from the duplicate to the kept testimonial,
     * skipping any where the platform already exists on the kept record.
     */
    protected function mergeReviewUrls(Testimonial $keep, Testimonial $dup): int
    {
        $existingPlatforms = $keep->reviewUrls->pluck('platform')->toArray();
        $transferred = 0;

        foreach ($dup->reviewUrls as $url) {
            if (! in_array($url->platform, $existingPlatforms, true)) {
                if (! $this->option('dry-run')) {
                    $keep->reviewUrls()->create([
                        'platform' => $url->platform,
                        'url' => $url->url,
                        'external_id' => $url->external_id,
                    ]);
                }
                $existingPlatforms[] = $url->platform;
                $transferred++;
            }
        }

        return $transferred;
    }

    /**
     * Normalize a name to "First L." for duplicate comparison.
     * Handles both "John Smith" → "john s." and "John S." → "john s."
     */
    protected function normalizeName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));

        if (count($parts) < 2) {
            return mb_strtolower(trim($name));
        }

        $firstName = mb_strtolower($parts[0]);
        $lastPart = end($parts);
        $lastInitial = mb_strtolower(mb_substr($lastPart, 0, 1));

        return "{$firstName} {$lastInitial}.";
    }

}
