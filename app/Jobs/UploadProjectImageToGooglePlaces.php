<?php

namespace App\Jobs;

use App\Models\ProjectImage;
use App\Services\GoogleBusinessProfileService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UploadProjectImageToGooglePlaces implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public int $imageId
    ) {}

    public function handle(GoogleBusinessProfileService $service): void
    {
        $image = ProjectImage::with('project')->find($this->imageId);

        if (! $image) {
            Log::warning('GBP: Image not found', ['image_id' => $this->imageId]);
            return;
        }

        $project = $image->project;
        if (! $project || ! $project->is_published) {
            return;
        }

        if ($image->google_places_uploaded_at) {
            return;
        }

        if (! $service->isConfigured()) {
            return;
        }

        $mediaName = $service->uploadProjectImage($image);

        if ($mediaName) {
            $image->update([
                'google_places_media_name' => $mediaName,
                'google_places_uploaded_at' => now(),
            ]);
        }
    }
}
