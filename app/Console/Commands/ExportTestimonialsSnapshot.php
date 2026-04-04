<?php

namespace App\Console\Commands;

use App\Models\Testimonial;
use Illuminate\Console\Command;

class ExportTestimonialsSnapshot extends Command
{
    protected $signature = 'testimonials:export-snapshot
        {--path=database/data/testimonials_snapshot.json : Snapshot output path relative to project root}';

    protected $description = 'Export testimonials + review URLs into a JSON snapshot for production deploy sync.';

    public function handle(): int
    {
        $relativePath = trim((string) $this->option('path'));
        if ($relativePath === '') {
            $this->error('Path cannot be empty.');

            return self::FAILURE;
        }

        $absolutePath = base_path($relativePath);

        $rows = Testimonial::query()
            ->with(['reviewUrls' => fn ($q) => $q->orderBy('platform')->orderBy('id')])
            ->orderBy('id')
            ->get();

        $payload = [
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'app_env' => config('app.env'),
                'count' => $rows->count(),
            ],
            'testimonials' => $rows->map(function (Testimonial $t) {
                return [
                    'reviewer_name' => (string) $t->reviewer_name,
                    'project_location' => $t->project_location,
                    'project_type' => $t->project_type,
                    'review_description' => (string) $t->review_description,
                    'review_date' => $t->review_date?->toDateString(),
                    'star_rating' => $t->star_rating,
                    'review_urls' => $t->reviewUrls->map(function ($u) {
                        return [
                            'platform' => (string) $u->platform,
                            'url' => (string) $u->url,
                            'external_id' => $u->external_id,
                        ];
                    })->values()->all(),
                ];
            })->values()->all(),
        ];

        $dir = dirname($absolutePath);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Unable to create directory: {$dir}");

            return self::FAILURE;
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->error('Failed to encode snapshot JSON.');

            return self::FAILURE;
        }

        file_put_contents($absolutePath, $json . PHP_EOL);

        $this->info('Export complete.');
        $this->line("Path: {$relativePath}");
        $this->line('Testimonials: ' . $rows->count());

        return self::SUCCESS;
    }
}
