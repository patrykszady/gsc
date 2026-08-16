<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use Illuminate\Console\Command;

/**
 * Apply reviewed local_intro copy from a JSON file to areas_served.
 *
 *   php artisan seo:apply-area-intros storage/app/deepened-intros.json
 *
 * Exists because AI-deepened copy must be REVIEWED before it ships, and the
 * apply step must ship exactly the reviewed text — not a second
 * nondeterministic generation. The workflow is: `seo:generate-area-content
 * --deepen --dry-run` (or a scripted run) produces candidate copy, a human or
 * assistant reviews and corrects it in the JSON, then this command applies it.
 * A timestamped backup of the previous values is written next to the input
 * file before anything changes.
 *
 * JSON shape: { "<slug>": { "text": "..." }, ... }
 */
class ApplyAreaIntros extends Command
{
    protected $signature = 'seo:apply-area-intros
        {file : Path to the reviewed JSON (absolute, or relative to base_path)}
        {--dry-run : Show what would change without writing}';

    protected $description = 'Apply reviewed area local_intro copy from a JSON file, with automatic backup';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }

        if (! is_file($path)) {
            $this->error("Not a file: {$path}");

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || $data === []) {
            $this->error('File did not parse to a non-empty JSON object.');

            return self::FAILURE;
        }

        $areas = [];
        foreach ($data as $slug => $row) {
            $text = trim((string) ($row['text'] ?? ''));
            if ($text === '') {
                $this->error("Entry '{$slug}' has no text — refusing to blank a page.");

                return self::FAILURE;
            }

            $area = AreaServed::where('slug', $slug)->first();
            if (! $area) {
                $this->error("No area with slug '{$slug}'.");

                return self::FAILURE;
            }

            $areas[$slug] = [$area, $text];
        }

        $dry = (bool) $this->option('dry-run');

        if (! $dry) {
            $backupPath = dirname($path) . '/local-intros-backup-' . now()->format('Y-m-d-His') . '.json';
            $backup = [];
            foreach ($areas as $slug => [$area]) {
                $backup[$slug] = ['text' => (string) $area->local_intro];
            }
            file_put_contents($backupPath, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->info("Backup of previous values: {$backupPath}");
            $this->info('(Roll back by running this command against the backup file.)');
        }

        foreach ($areas as $slug => [$area, $text]) {
            $before = mb_strlen((string) $area->local_intro);
            $this->line(sprintf('  %-16s %5d -> %5d chars%s', $slug, $before, mb_strlen($text), $dry ? ' (dry-run)' : ''));

            if (! $dry) {
                $area->forceFill(['local_intro' => $text])->save();
            }
        }

        $this->info($dry ? 'Dry run — nothing written.' : 'Applied ' . count($areas) . ' area(s).');

        return self::SUCCESS;
    }
}
