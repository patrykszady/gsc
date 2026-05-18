<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Prints a ready-to-paste Q&A checklist for the GBP listing.
 *
 * Google deprecated the public Q&A write API in 2019 — questions and answers
 * can still be posted only through the GBP web UI / mobile app. This command
 * formats the curated answers from config/geo-answers.php so the team can
 * copy/paste them straight into the GBP "Questions & Answers" tab.
 *
 *   php artisan gbp:qna-checklist
 *   php artisan gbp:qna-checklist --markdown > storage/app/gbp-qna.md
 */
class GbpQnaChecklist extends Command
{
    protected $signature = 'gbp:qna-checklist
        {--markdown : Output as markdown for copy/paste}
        {--limit=12 : Max number of Q&A pairs to print}';

    protected $description = 'Print curated Q&A pairs ready to seed on the Google Business Profile listing.';

    public function handle(): int
    {
        $answers = collect(config('geo-answers.answers', []))
            ->map(fn ($a) => ['q' => $a['q'] ?? null, 'a' => $a['a'] ?? null])
            ->filter(fn ($a) => $a['q'] && $a['a']);

        $extra = collect(config('gbp-services.qna_extra', []))
            ->map(fn ($a) => ['q' => $a['q'] ?? null, 'a' => $a['a'] ?? null])
            ->filter(fn ($a) => $a['q'] && $a['a']);

        $all = $answers->merge($extra)->take((int) $this->option('limit'));

        if ($all->isEmpty()) {
            $this->warn('No Q&A pairs found in config/geo-answers.php.');
            return self::FAILURE;
        }

        $md = $this->option('markdown');

        if ($md) {
            $this->line('# GBP Q&A Pre-Seed Checklist');
            $this->line('');
            $this->line('Post these one-by-one in your GBP "Questions & answers" tab.');
            $this->line('After posting each question, log in as the **owner** to answer it.');
            $this->line('');
        } else {
            $this->info('GBP Q&A Pre-Seed Checklist');
            $this->line('Post these in GBP → Questions & answers. Answer as the owner.');
            $this->newLine();
        }

        foreach ($all as $i => $qa) {
            $n = $i + 1;
            if ($md) {
                $this->line("## {$n}. {$qa['q']}");
                $this->line('');
                $this->line($qa['a']);
                $this->line('');
            } else {
                $this->line("<options=bold>[{$n}] Q:</> {$qa['q']}");
                $this->line("    <fg=cyan>A:</> " . wordwrap($qa['a'], 78, "\n       ", true));
                $this->newLine();
            }
        }

        return self::SUCCESS;
    }
}
