<?php

namespace App\Console\Commands;

use App\Models\Testimonial;
use Illuminate\Console\Command;

class FixEncodingAndMergeDuplicates extends Command
{
    protected $signature = 'testimonials:fix-encoding-merge
        {--dry-run : Show changes without writing to DB}';

    protected $description = 'Fix mojibake encoding in review text and merge content-duplicate testimonials.';

    /**
     * Common mojibake replacements (UTF-8 smart punctuation misread as Windows-1252).
     * Ordered longest-first to prevent partial matches.
     */
    protected const MOJIBAKE_MAP = [
        'â€™' => "\u{2019}",  // right single quote / apostrophe '
        'â€˜' => "\u{2018}",  // left single quote '
        'â€œ' => "\u{201C}",  // left double quote "
        'â€\u{009D}' => "\u{201D}",  // right double quote "
        'â€"' => "\u{2014}",  // em dash —
        'â€"' => "\u{2013}",  // en dash –
        'â€¦' => "\u{2026}",  // ellipsis …
        'Â ' => ' ',           // non-breaking space artifact
    ];

    public function handle(): int
    {
        $this->info('Fixing encoding issues in testimonials...');

        $fixedEncoding = 0;
        $fixedPlatforms = 0;
        $merged = 0;

        // Pass 0: Fix mismatched platforms (e.g. Yelp URL stored as "google")
        \App\Models\ReviewUrl::all()->each(function (\App\Models\ReviewUrl $url) use (&$fixedPlatforms) {
            $detectedPlatform = $this->detectPlatform($url->url);
            if ($detectedPlatform && $detectedPlatform !== $url->platform) {
                if ($this->option('dry-run')) {
                    $this->line("[DRY RUN] Fix platform: #{$url->id} \"{$url->url}\" — {$url->platform} → {$detectedPlatform}");
                } else {
                    $url->update(['platform' => $detectedPlatform]);
                    $this->line("Fixed platform: #{$url->id} {$url->platform} → {$detectedPlatform}");
                }
                $fixedPlatforms++;
            }
        });

        if ($fixedPlatforms) {
            $this->info("Fixed {$fixedPlatforms} platform assignment(s).");
            $this->newLine();
        }

        // Pass 1: Fix mojibake encoding in all testimonials
        Testimonial::all()->each(function (Testimonial $t) use (&$fixedEncoding) {
            $original = $t->review_description;
            $fixed = $this->fixMojibake($original);

            // Also fix reviewer_name if it has encoding issues
            $originalName = $t->reviewer_name;
            $fixedName = $this->fixMojibake($originalName);

            if ($fixed !== $original || $fixedName !== $originalName) {
                if ($this->option('dry-run')) {
                    $this->line("[DRY RUN] #{$t->id} encoding fix:");
                    if ($fixedName !== $originalName) {
                        $this->line("  Name: {$originalName} → {$fixedName}");
                    }
                    if ($fixed !== $original) {
                        $this->line("  Text: " . mb_substr($original, 0, 80) . "...");
                        $this->line("    → " . mb_substr($fixed, 0, 80) . "...");
                    }
                } else {
                    $updates = [];
                    if ($fixed !== $original) {
                        $updates['review_description'] = $fixed;
                    }
                    if ($fixedName !== $originalName) {
                        $updates['reviewer_name'] = $fixedName;
                    }
                    $t->update($updates);
                    $this->line("Fixed encoding #{$t->id}: {$t->reviewer_name}");
                }
                $fixedEncoding++;
            }
        });

        $this->info("Fixed encoding in {$fixedEncoding} record(s).");
        $this->newLine();

        // Pass 2: Merge content-duplicates (same reviewer name, similar text)
        $this->info('Scanning for content-duplicate testimonials...');
        $testimonials = Testimonial::with('reviewUrls')->get();

        // Group by normalized name
        $groups = $testimonials->groupBy(function (Testimonial $t) {
            return mb_strtolower(trim($t->reviewer_name));
        })->filter(fn ($group) => $group->count() > 1);

        foreach ($groups as $name => $group) {
            $sorted = $group->sortBy('id');
            $items = $sorted->values();

            // Compare each pair for similar content
            for ($i = 0; $i < $items->count(); $i++) {
                for ($j = $i + 1; $j < $items->count(); $j++) {
                    $keep = $items[$i];
                    $dup = $items[$j];

                    // Skip if already deleted in this pass
                    if (! Testimonial::find($dup->id)) {
                        continue;
                    }

                    if (! $this->isSimilarContent($keep->review_description, $dup->review_description)) {
                        continue;
                    }

                    // Prefer the record with richer data (location, type, etc.)
                    if (! $keep->project_location && $dup->project_location) {
                        // Swap: keep the richer record
                        [$keep, $dup] = [$dup, $keep];
                    }

                    // Merge review URLs
                    $existingPlatforms = $keep->reviewUrls->pluck('platform')->toArray();
                    $urlsTransferred = 0;

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
                            $urlsTransferred++;
                        }
                    }

                    // Preserve Google external_id on the kept record when available.
                    if (! $this->option('dry-run')) {
                        $keepGoogleUrl = $keep->reviewUrls()->where('platform', 'google')->first();
                        $dupGoogleUrl = $dup->reviewUrls()->where('platform', 'google')->first();

                        if ($keepGoogleUrl && $dupGoogleUrl && ! $keepGoogleUrl->external_id && $dupGoogleUrl->external_id) {
                            $keepGoogleUrl->update(['external_id' => $dupGoogleUrl->external_id]);
                        }
                    }

                    if ($this->option('dry-run')) {
                        $this->line("[DRY RUN] Would merge #{$dup->id} \"{$dup->reviewer_name}\" (date: {$dup->review_date}) into #{$keep->id} \"{$keep->reviewer_name}\" (date: {$keep->review_date}) — {$urlsTransferred} URL(s)");
                    } else {
                        $dup->reviewUrls()->delete();
                        $dup->delete();
                        $this->line("Merged #{$dup->id} → #{$keep->id} \"{$keep->reviewer_name}\" ({$urlsTransferred} URL(s) transferred)");
                    }
                    $merged++;
                }
            }
        }

        $this->newLine();
        $prefix = $this->option('dry-run') ? '[DRY RUN] Would have' : '';
        $this->info(trim("{$prefix} Fixed {$fixedEncoding} encoding issue(s), merged {$merged} content-duplicate(s)."));

        return self::SUCCESS;
    }

    protected function fixMojibake(string $text): string
    {
        // Detect double-encoded UTF-8 (mojibake markers like â€™ â€œ etc.)
        if (! preg_match('/â€/u', $text)) {
            return $text;
        }

        // The text was originally UTF-8, misread as Windows-1252, then re-encoded to UTF-8.
        // Reverse: convert UTF-8 → Windows-1252 bytes → those bytes ARE the original UTF-8.
        $decoded = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        // Verify the result is valid UTF-8 and the mojibake is gone
        if (mb_check_encoding($decoded, 'UTF-8') && ! preg_match('/â€/u', $decoded)) {
            return $decoded;
        }

        // Fallback: apply manual replacements
        $fixed = str_replace(
            array_keys(self::MOJIBAKE_MAP),
            array_values(self::MOJIBAKE_MAP),
            $text
        );

        return $fixed;
    }

    /**
     * Check if two review texts are substantially the same content.
     * One may have mojibake and the other clean text.
     */
    protected function isSimilarContent(string $a, string $b): bool
    {
        // Normalize both: strip non-alphanumeric, lowercase
        $normA = $this->normalizeForComparison($a);
        $normB = $this->normalizeForComparison($b);

        if ($normA === $normB) {
            return true;
        }

        // Check if first 100 alphanumeric chars match (handles truncation differences)
        $prefixLen = min(100, mb_strlen($normA), mb_strlen($normB));
        if ($prefixLen >= 50) {
            return mb_substr($normA, 0, $prefixLen) === mb_substr($normB, 0, $prefixLen);
        }

        return false;
    }

    protected function normalizeForComparison(string $text): string
    {
        // Remove all non-alphanumeric characters and lowercase
        return mb_strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $text));
    }

    /**
     * Detect the correct platform from a URL.
     */
    protected function detectPlatform(string $url): ?string
    {
        if (str_contains($url, 'yelp.com')) {
            return 'yelp';
        }
        if (str_contains($url, 'google.com') || str_contains($url, 'goo.gl')) {
            return 'google';
        }
        if (str_contains($url, 'facebook.com') || str_contains($url, 'fb.com')) {
            return 'facebook';
        }

        return null;
    }
}
