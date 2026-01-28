<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\ImageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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

        // tempPath is the full filesystem path from TemporaryUploadedFile->getRealPath()
        if (!file_exists($this->tempPath)) {
            \Log::warning('ProcessProjectImage: Temp file not found', ['path' => $this->tempPath]);
            return;
        }

        // Create an UploadedFile from the temp path
        $file = new \Illuminate\Http\UploadedFile(
            $this->tempPath,
            $this->originalFilename,
            $this->mimeType,
            null,
            true
        );

        $imageService->upload($file, $project, [
            'sort_order' => $this->sortOrder,
            'is_cover' => $this->isCover,
        ]);

        // Clean up the temp file
        @unlink($this->tempPath);
    }
}
