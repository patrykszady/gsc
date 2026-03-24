<?php

namespace App\Console\Commands;

use App\Models\Testimonial;
use Illuminate\Console\Command;

class NormalizeReviewerNames extends Command
{
    protected $signature = 'testimonials:normalize-names
        {--dry-run : Show changes without writing to DB}';

    protected $description = 'Convert all reviewer names to "First L." format.';

    public function handle(): int
    {
        $updated = 0;

        Testimonial::all()->each(function (Testimonial $t) use (&$updated) {
            $name = trim($t->reviewer_name);

            // Skip business names (contain keywords like "of", "Design", "LLC", etc.)
            if (preg_match('/\b(of|Design|LLC|Inc|Corp|Co\b|Company|Services|Group)\b/i', $name)) {
                $this->line("Skipped (business): #{$t->id} {$name}");
                return;
            }

            $newName = $this->formatName($name);

            if ($newName === $name) {
                return;
            }

            if ($this->option('dry-run')) {
                $this->line("[DRY RUN] #{$t->id}: {$name} → {$newName}");
            } else {
                $t->update(['reviewer_name' => $newName]);
                $this->line("#{$t->id}: {$name} → {$newName}");
            }

            $updated++;
        });

        $prefix = $this->option('dry-run') ? '[DRY RUN] Would update' : 'Updated';
        $this->info("{$prefix} {$updated} name(s).");

        return self::SUCCESS;
    }

    /**
     * Format name to "First L." or "First & Second L." for couples.
     */
    protected function formatName(string $name): string
    {
        // Handle couple names: "Neal & Ivana S" or "Jason & Becky H"
        if (str_contains($name, '&')) {
            // Split around "&": ["Neal ", " Ivana S"]
            $sides = explode('&', $name, 2);
            $firstName1 = trim($sides[0]);
            $rightParts = preg_split('/\s+/', trim($sides[1]));

            if (count($rightParts) >= 2) {
                $firstName2 = $rightParts[0];
                $lastPart = end($rightParts);
                $lastInitial = mb_strtoupper(mb_substr($lastPart, 0, 1));
                $suffix = mb_strlen($lastPart) <= 2 && str_ends_with($lastPart, '.') ? $lastPart : "{$lastInitial}.";
                return "{$firstName1} & {$firstName2} {$suffix}";
            }

            return $name;
        }

        $parts = preg_split('/\s+/', $name);

        if (count($parts) < 2) {
            return $name;
        }

        $lastPart = end($parts);

        // Already abbreviated with period (e.g. "A.")
        if (mb_strlen($lastPart) <= 2 && str_ends_with($lastPart, '.')) {
            return $name;
        }

        $firstName = $parts[0];
        $lastInitial = mb_strtoupper(mb_substr($lastPart, 0, 1));

        return "{$firstName} {$lastInitial}.";
    }
}
