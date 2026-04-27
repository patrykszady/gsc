<?php

namespace App\Console\Commands;

use App\Models\ProjectTimelapseFrame;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrateLegacyTimelapseFrames extends Command
{
    protected $signature = 'timelapse:migrate-legacy-frames {--dry-run : Show what would be migrated without writing files}';

    protected $description = 'Copy legacy timelapse frame files from storage/app into the public disk';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $frames = ProjectTimelapseFrame::query()->orderBy('id')->get();

        $this->info("Checking {$frames->count()} timelapse frames...");

        $normalized = 0;
        $alreadyPresent = 0;
        $missing = 0;
        $legacyOnly = 0;
        $legacyDuplicates = 0;

        foreach ($frames as $frame) {
            $publicExists = Storage::disk($frame->disk)->exists($frame->path);
            $legacyExists = is_file($frame->legacyPath());

            if ($publicExists && !$legacyExists) {
                $alreadyPresent++;
                continue;
            }

            if (!$publicExists && $legacyExists) {
                $legacyOnly++;
            }

            if ($publicExists && $legacyExists) {
                $legacyDuplicates++;
            }

            if (!$legacyExists && !$publicExists) {
                $missing++;
                $this->warn("Missing legacy source: {$frame->path}");
                continue;
            }

            if ($dryRun) {
                $action = $publicExists ? 'Would remove legacy duplicate' : 'Would move legacy file';
                $this->line("{$action}: {$frame->path}");
                $normalized++;
                continue;
            }

            if ($frame->normalizeStorageLocation()) {
                $action = $publicExists ? 'Removed legacy duplicate' : 'Moved legacy file';
                $this->line("{$action}: {$frame->path}");
                $normalized++;
                continue;
            }

            $this->warn("Skipped: {$frame->path}");
        }

        $this->newLine();
        $this->info("Already present: {$alreadyPresent}");
        $this->info("Legacy-only: {$legacyOnly}");
        $this->info("Legacy duplicates: {$legacyDuplicates}");
        $this->info(($dryRun ? 'Would normalize' : 'Normalized') . ": {$normalized}");

        if ($missing > 0) {
            $this->warn("Missing legacy files: {$missing}");
        }

        return $missing > 0 ? self::FAILURE : self::SUCCESS;
    }
}