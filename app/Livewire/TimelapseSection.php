<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Livewire\Component;

class TimelapseSection extends Component
{
    public function placeholder(): string
    {
        return <<<'HTML'
        <section class="relative bg-zinc-50 py-12 sm:py-16 lg:py-20 dark:bg-slate-900">
            <div class="mx-auto max-w-7xl px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center space-y-4">
                    <div class="h-4 w-32 mx-auto bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse"></div>
                    <div class="h-10 w-3/4 mx-auto bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse"></div>
                    <div class="h-6 w-full bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse"></div>
                </div>
                <div class="mt-10 aspect-video max-w-4xl mx-auto bg-zinc-200 dark:bg-zinc-700 rounded-2xl animate-pulse"></div>
            </div>
        </section>
        HTML;
    }

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
