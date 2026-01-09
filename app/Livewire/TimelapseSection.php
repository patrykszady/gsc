<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Livewire\Component;

class TimelapseSection extends Component
{
    public function render()
    {
        $frames = Cache::remember('timelapse.frames.timelapse1.v1', now()->addHours(6), function () {
            $dir = public_path('images/timelapse1');
            if (! File::exists($dir)) {
                return [];
            }

            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            $files = collect(File::files($dir))
                ->filter(fn ($file) => in_array(strtolower($file->getExtension()), $allowed, true))
                ->filter(fn ($file) => ! str_contains($file->getFilename(), '-thumb')) // Exclude thumb files
                ->sortBy(fn ($file) => $file->getFilename(), SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->map(fn ($file) => asset('images/timelapse1/' . $file->getFilename()))
                ->all();

            return $files;
        });

        $frameCount = max(count($frames), 1);
        $middleTick = (int) ceil($frameCount / 2);

        return view('livewire.timelapse-section', [
            'frames' => $frames,
            'frameCount' => $frameCount,
            'middleTick' => $middleTick,
        ]);
    }
}
