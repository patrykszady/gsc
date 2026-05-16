<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\ImageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ProcessProjectImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public int $projectId,
        public string $tempPath,
        public string $originalFilename,
        public string $mimeType,
        public int $sortOrder,
        public bool $isCover = false,
    ) {}

    public function handle(ImageService $imageService): void
    {
        $project = Project::find($this->projectId);
        
        if (!$project) {
            \Log::warning('ProcessProjectImage: Project not found', ['projectId' => $this->projectId]);
            return;
        }

        // Support both absolute paths (legacy jobs) and local-disk relative paths.
        $resolvedTempPath = $this->tempPath;
        $isLocalDiskPath = false;

        if (!file_exists($resolvedTempPath)) {
            if (Storage::disk('local')->exists($this->tempPath)) {
                $resolvedTempPath = Storage::disk('local')->path($this->tempPath);
                $isLocalDiskPath = true;
            } else {
                \Log::warning('ProcessProjectImage: Temp file not found', ['path' => $this->tempPath]);
                return;
            }
        }

        // Create an UploadedFile from the temp path
        $file = new \Illuminate\Http\UploadedFile(
            $resolvedTempPath,
            $this->originalFilename,
            $this->mimeType,
            null,
            true
        );

        $imageService->upload($file, $project, [
            'sort_order' => $this->sortOrder,
            'is_cover' => $this->isCover,
        ]);

        // Clean up temp artifact
        if ($isLocalDiskPath && Storage::disk('local')->exists($this->tempPath)) {
            Storage::disk('local')->delete($this->tempPath);
        } else {
            @unlink($resolvedTempPath);
        }
    }
}
