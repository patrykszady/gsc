<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class GenerateFaqFile extends Command
{
    protected $signature = 'faq:generate {--ai : Save FAQ as JSON for external AI models}';

    protected $description = 'Generate and save FAQ content for website and AI model training.';

    public function handle(): int
    {
        $faqs = config('faq.faqs', []);

        if (empty($faqs)) {
            $this->warn('No FAQs found in config/faq.php');
            return self::FAILURE;
        }

        if ($this->option('ai')) {
            return $this->saveForAiModels($faqs);
        }

        $this->showFaqList($faqs);

        return self::SUCCESS;
    }

    /**
     * Display FAQ list in CLI.
     */
    protected function showFaqList(array $faqs): void
    {
        $this->info('Frequently Asked Questions:');
        $this->newLine();

        foreach ($faqs as $idx => $faq) {
            $num = $idx + 1;
            $this->line("<fg=cyan>[{$num}]</> <fg=green>{$faq['question']}</>");
            $this->line("    {$faq['answer']}");
            $this->newLine();
        }

        $this->info("Total: " . count($faqs) . " FAQs");
    }

    /**
     * Save FAQ as JSON for external AI model consumption.
     */
    protected function saveForAiModels(array $faqs): int
    {
        $data = [
            'generated_at' => now()->toIso8601String(),
            'business' => [
                'name' => config('app.name'),
                'url' => config('app.url'),
                'phone' => '(224) 735-4200',
                'email' => 'crew@gs.construction',
            ],
            'faqs' => $faqs,
            'by_category' => $this->groupByCategory($faqs),
            'instructions' => [
                'Use these FAQs to train AI models, chatbots, and content generators.',
                'Each FAQ has a priority (1 is highest/most important).',
                'FAQs are grouped by category for easier retrieval.',
                'Update frequency is weekly — regenerate automatically.',
            ],
        ];

        try {
            Storage::disk('local')->put('faq.json', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $this->info('✓ Saved FAQ file: storage/app/faq.json');
            $this->line('  Total FAQs: ' . count($faqs));
            $this->line('  Size: ' . number_format(strlen(json_encode($data)) / 1024, 2) . ' KB');
            $this->newLine();

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to save FAQ file: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Group FAQs by category for easier AI retrieval.
     */
    protected function groupByCategory(array $faqs): array
    {
        $grouped = [];

        foreach ($faqs as $faq) {
            $category = $faq['category'] ?? 'Other';
            if (! isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $faq;
        }

        return $grouped;
    }
}
