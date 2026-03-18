<?php

namespace App\Console\Commands;

use App\Models\ProjectTimelapseFrame;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;

class ResizeTimelapseFrames extends Command
{
    protected $signature = 'timelapse:resize-frames {--max-width=1920} {--quality=80}';
    protected $description = 'Resize existing timelapse frames that exceed the max width';

    public function handle(): int
    {
        $maxWidth = (int) $this->option('max-width');
        $quality = (int) $this->option('quality');
        $disk = Storage::disk('public');

        $frames = ProjectTimelapseFrame::all();
        $this->info("Checking {$frames->count()} timelapse frames...");

        $resized = 0;

        foreach ($frames as $frame) {
            if (!$disk->exists($frame->path)) {
                $this->warn("Missing: {$frame->path}");
                continue;
            }

            $fullPath = $disk->path($frame->path);
            $image = Image::read($fullPath);

            if ($image->width() <= $maxWidth) {
                continue;
            }

            $originalSize = $disk->size($frame->path);
            $image->scale(width: $maxWidth);

            $extension = pathinfo($frame->filename, PATHINFO_EXTENSION);
            $encoded = match (strtolower($extension)) {
                'png' => $image->toPng()->toString(),
                'webp' => $image->toWebp($quality)->toString(),
                default => $image->toJpeg($quality)->toString(),
            };

            $disk->put($frame->path, $encoded);
            $newSize = strlen($encoded);

            $this->line(sprintf(
                '  Resized %s: %s → %s',
                $frame->path,
                $this->formatBytes($originalSize),
                $this->formatBytes($newSize),
            ));
            $resized++;
        }

        $this->info("Done. Resized {$resized} of {$frames->count()} frames.");

        return self::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return round($bytes / 1024, 1) . ' KB';
    }
}
