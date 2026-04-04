<?php

namespace App\Console\Commands;

use App\Models\Testimonial;
use Illuminate\Console\Command;

class FixTestimonialEncoding extends Command
{
    protected $signature = 'testimonials:fix-encoding
        {--dry-run : Show what would be changed without writing to DB}';

    protected $description = 'Fix Windows-1252 mojibake encoding in testimonial text (e.g. â€™ → \').';

    /**
     * Common Windows-1252 → UTF-8 double-encoding (mojibake) patterns.
     * Order matters: longer sequences must come first to avoid partial replacements.
     */
    protected const REPLACEMENTS = [
        "\xC3\xA2\xE2\x82\xAC\xE2\x84\xA2" => "\xE2\x80\x99", // â€™ → ' (right single quote)
        "\xC3\xA2\xE2\x82\xAC\xC5\x93"     => "\xE2\x80\x9C", // â€œ → " (left double quote)
        "\xC3\xA2\xE2\x82\xAC\xC2\x9D"     => "\xE2\x80\x9D", // â€ → " (right double quote)
        "\xC3\xA2\xE2\x82\xAC\xE2\x80\x9C" => "\xE2\x80\x94", // â€" → — (em dash)
        "\xC3\xA2\xE2\x82\xAC\xE2\x80\x9D" => "\xE2\x80\x93", // â€" → – (en dash)
        "\xC3\xA2\xE2\x82\xAC\xC2\xA6"     => "\xE2\x80\xA6", // â€¦ → … (ellipsis)
        "\xC3\xA2\xE2\x82\xAC\xCB\x9C"     => "\xE2\x80\x98", // â€˜ → ' (left single quote)
    ];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $prefix = $dryRun ? '[DRY RUN] ' : '';

        // The mojibake marker: â€ is \xC3\xA2\xE2\x82\xAC in raw bytes
        $marker = "\xC3\xA2\xE2\x82\xAC";

        $affected = Testimonial::where('review_description', 'like', '%' . $marker . '%')
            ->orWhere('reviewer_name', 'like', '%' . $marker . '%')
            ->get();

        if ($affected->isEmpty()) {
            $this->info('No testimonials with encoding issues found.');

            return self::SUCCESS;
        }

        $this->info("{$prefix}Found {$affected->count()} testimonial(s) with encoding issues.");
        $fixed = 0;

        foreach ($affected as $testimonial) {
            $originalDesc = $testimonial->review_description;
            $originalName = $testimonial->reviewer_name;

            $fixedDesc = $this->fixEncoding($originalDesc);
            $fixedName = $this->fixEncoding($originalName);

            $descChanged = $fixedDesc !== $originalDesc;
            $nameChanged = $fixedName !== $originalName;

            if (! $descChanged && ! $nameChanged) {
                $this->line("  #{$testimonial->id} [{$originalName}] — no replaceable patterns found.");

                continue;
            }

            $this->line("{$prefix}Fix #{$testimonial->id} [{$originalName}]");

            if ($nameChanged) {
                $this->line("  Name: {$originalName} → {$fixedName}");
            }

            if ($descChanged) {
                $this->line('  Text: ' . mb_substr($fixedDesc, 0, 100) . '...');
            }

            if (! $dryRun) {
                $testimonial->update([
                    'review_description' => $fixedDesc,
                    'reviewer_name' => $fixedName,
                ]);
            }

            $fixed++;
        }

        $this->newLine();
        $this->info("{$prefix}Fixed: {$fixed} testimonial(s).");

        return self::SUCCESS;
    }

    protected function fixEncoding(string $text): string
    {
        foreach (self::REPLACEMENTS as $bad => $good) {
            $text = str_replace($bad, $good, $text);
        }

        return $text;
    }
}
