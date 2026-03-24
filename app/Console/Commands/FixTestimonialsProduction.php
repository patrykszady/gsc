<?php

namespace App\Console\Commands;

use App\Models\ReviewUrl;
use App\Models\Testimonial;
use Illuminate\Console\Command;

class FixTestimonialsProduction extends Command
{
    protected $signature = 'testimonials:fix-production
        {--dry-run : Show changes without writing to DB}';

    protected $description = 'Production fix: encoding, platform labels, Google review URLs, name normalization, and duplicate merging.';

    /**
     * Common mojibake replacements (UTF-8 smart punctuation misread as Windows-1252).
     */
    protected const MOJIBAKE_MAP = [
        'â€™' => "\u{2019}",
        'â€˜' => "\u{2018}",
        'â€œ' => "\u{201C}",
        'â€\u{009D}' => "\u{201D}",
        'â€"' => "\u{2014}",
        'â€"' => "\u{2013}",
        'â€¦' => "\u{2026}",
        'Â ' => ' ',
    ];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $stats = ['platforms' => 0, 'encoding' => 0, 'names' => 0, 'duplicates' => 0, 'urlsTransferred' => 0];

        // ── Step 1: Fix mismatched platforms ──
        $this->info('Step 1: Fixing platform labels...');
        ReviewUrl::all()->each(function (ReviewUrl $url) use ($dryRun, &$stats) {
            $detected = $this->detectPlatform($url->url);
            if ($detected && $detected !== $url->platform) {
                $this->line("  Platform: #{$url->id} {$url->platform} → {$detected}");
                if (! $dryRun) {
                    $url->update(['platform' => $detected]);
                }
                $stats['platforms']++;
            }
        });

        // ── Step 2: Fix mojibake encoding ──
        $this->info('Step 2: Fixing encoding...');
        Testimonial::all()->each(function (Testimonial $t) use ($dryRun, &$stats) {
            $fixedDesc = $this->fixMojibake($t->review_description);
            $fixedName = $this->fixMojibake($t->reviewer_name);
            $updates = [];

            if ($fixedDesc !== $t->review_description) {
                $updates['review_description'] = $fixedDesc;
            }
            if ($fixedName !== $t->reviewer_name) {
                $updates['reviewer_name'] = $fixedName;
            }
            if ($updates) {
                $this->line("  Encoding: #{$t->id} {$t->reviewer_name}");
                if (! $dryRun) {
                    $t->update($updates);
                }
                $stats['encoding']++;
            }
        });

        // ── Step 3: Normalize reviewer names to "First L." ──
        $this->info('Step 3: Normalizing reviewer names...');
        Testimonial::all()->each(function (Testimonial $t) use ($dryRun, &$stats) {
            $normalized = $this->formatName($t->reviewer_name);
            if ($normalized !== $t->reviewer_name) {
                $this->line("  Name: #{$t->id} {$t->reviewer_name} → {$normalized}");
                if (! $dryRun) {
                    $t->update(['reviewer_name' => $normalized]);
                }
                $stats['names']++;
            }
        });

        // ── Step 4: Merge name+date duplicates ──
        $this->info('Step 4: Merging name+date duplicates...');
        $this->mergeByNameDate($dryRun, $stats);

        // ── Step 5: Merge content duplicates (same text, different dates/sources) ──
        $this->info('Step 5: Merging content duplicates...');
        $this->mergeByContent($dryRun, $stats);

        // ── Summary ──
        $this->newLine();
        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}Done:");
        $this->line("  Platforms fixed: {$stats['platforms']}");
        $this->line("  Encoding fixed: {$stats['encoding']}");
        $this->line("  Names normalized: {$stats['names']}");
        $this->line("  Duplicates removed: {$stats['duplicates']}");
        $this->line("  Review URLs transferred: {$stats['urlsTransferred']}");

        return self::SUCCESS;
    }

    protected function mergeByNameDate(bool $dryRun, array &$stats): void
    {
        $testimonials = Testimonial::with('reviewUrls')->get();

        $groups = $testimonials->groupBy(function (Testimonial $t) {
            return $this->normalizeName($t->reviewer_name) . '|' . ($t->review_date?->toDateString() ?? 'no-date');
        })->filter(fn ($group) => $group->count() > 1);

        foreach ($groups as $group) {
            $sorted = $group->sortBy('id');
            $keep = $sorted->first();

            foreach ($sorted->slice(1) as $dup) {
                $transferred = $this->mergeAndDelete($keep, $dup, $dryRun);
                $stats['urlsTransferred'] += $transferred;
                $stats['duplicates']++;
            }
        }
    }

    protected function mergeByContent(bool $dryRun, array &$stats): void
    {
        $testimonials = Testimonial::with('reviewUrls')->get();

        $groups = $testimonials->groupBy(fn (Testimonial $t) => mb_strtolower(trim($t->reviewer_name)))
            ->filter(fn ($group) => $group->count() > 1);

        foreach ($groups as $group) {
            $items = $group->sortBy('id')->values();

            for ($i = 0; $i < $items->count(); $i++) {
                for ($j = $i + 1; $j < $items->count(); $j++) {
                    $keep = $items[$i];
                    $dup = $items[$j];

                    if (! Testimonial::find($dup->id)) {
                        continue;
                    }

                    if (! $this->isSimilarContent($keep->review_description, $dup->review_description)) {
                        continue;
                    }

                    // Prefer the record with richer metadata
                    if (! $keep->project_location && $dup->project_location) {
                        [$keep, $dup] = [$dup, $keep];
                    }

                    $transferred = $this->mergeAndDelete($keep, $dup, $dryRun);
                    $stats['urlsTransferred'] += $transferred;
                    $stats['duplicates']++;
                }
            }
        }
    }

    protected function mergeAndDelete(Testimonial $keep, Testimonial $dup, bool $dryRun): int
    {
        $existingPlatforms = $keep->reviewUrls->pluck('platform')->toArray();
        $transferred = 0;

        foreach ($dup->reviewUrls as $url) {
            if (! in_array($url->platform, $existingPlatforms, true)) {
                if (! $dryRun) {
                    $keep->reviewUrls()->create(['platform' => $url->platform, 'url' => $url->url]);
                }
                $existingPlatforms[] = $url->platform;
                $transferred++;
            }
        }

        // Copy google_review_id if the kept record doesn't have one
        if (! $keep->google_review_id && $dup->google_review_id && ! $dryRun) {
            try {
                $keep->update(['google_review_id' => $dup->google_review_id]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                // Already taken
            }
        }

        $label = $dryRun ? '[DRY RUN] Would merge' : 'Merged';
        $this->line("  {$label} #{$dup->id} \"{$dup->reviewer_name}\" → #{$keep->id} \"{$keep->reviewer_name}\" ({$transferred} URL(s))");

        if (! $dryRun) {
            $dup->reviewUrls()->delete();
            $dup->delete();
        }

        return $transferred;
    }

    // ── Helpers ──

    protected function fixMojibake(string $text): string
    {
        if (! preg_match('/â€/u', $text)) {
            return $text;
        }

        $decoded = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        if (mb_check_encoding($decoded, 'UTF-8') && ! preg_match('/â€/u', $decoded)) {
            return $decoded;
        }

        return str_replace(array_keys(self::MOJIBAKE_MAP), array_values(self::MOJIBAKE_MAP), $text);
    }

    protected function formatName(string $name): string
    {
        if (preg_match('/\b(of|Design|LLC|Inc|Corp|Co\b|Company|Services|Group)\b/i', $name)) {
            return $name;
        }

        if (str_contains($name, '&')) {
            $sides = explode('&', $name, 2);
            $firstName1 = trim($sides[0]);
            $rightParts = preg_split('/\s+/', trim($sides[1]));

            if (count($rightParts) >= 2) {
                $firstName2 = $rightParts[0];
                $lastPart = end($rightParts);
                if (mb_strlen($lastPart) <= 2 && str_ends_with($lastPart, '.')) {
                    return "{$firstName1} & {$firstName2} {$lastPart}";
                }
                $lastInitial = mb_strtoupper(mb_substr($lastPart, 0, 1));
                return "{$firstName1} & {$firstName2} {$lastInitial}.";
            }

            return $name;
        }

        $parts = preg_split('/\s+/', trim($name));
        if (count($parts) < 2) {
            return $name;
        }

        $lastPart = end($parts);
        if (mb_strlen($lastPart) <= 2 && str_ends_with($lastPart, '.')) {
            return $name;
        }

        $firstName = $parts[0];
        $lastInitial = mb_strtoupper(mb_substr($lastPart, 0, 1));
        return "{$firstName} {$lastInitial}.";
    }

    protected function normalizeName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        if (count($parts) < 2) {
            return mb_strtolower(trim($name));
        }
        return mb_strtolower($parts[0]) . ' ' . mb_strtolower(mb_substr(end($parts), 0, 1)) . '.';
    }

    protected function isSimilarContent(string $a, string $b): bool
    {
        $normA = mb_strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $a));
        $normB = mb_strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $b));

        if ($normA === $normB) {
            return true;
        }

        $prefixLen = min(100, mb_strlen($normA), mb_strlen($normB));
        return $prefixLen >= 50 && mb_substr($normA, 0, $prefixLen) === mb_substr($normB, 0, $prefixLen);
    }

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
