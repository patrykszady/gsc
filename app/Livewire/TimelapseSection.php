<?php

namespace App\Livewire;

use App\Models\ProjectTimelapse;
use Livewire\Component;

class TimelapseSection extends Component
{
    public ?int $timelapseId = null;

    public function placeholder(): string
    {
        return <<<'HTML'
        <section class="relative bg-zinc-50 py-12 sm:py-16 lg:py-20 dark:bg-slate-900">
            <div class="mx-auto max-w-7xl px-6 lg:px-8">
                <div class="mt-10 aspect-video max-w-4xl mx-auto bg-zinc-200 dark:bg-zinc-700 rounded-2xl animate-pulse"></div>
            </div>
        </section>
        HTML;
    }

    public function render()
    {
        $timelapse = $this->timelapseId
            ? ProjectTimelapse::with(['frames', 'project'])->find($this->timelapseId)
            : ProjectTimelapse::with(['frames', 'project'])
                ->whereHas('frames')
                ->inRandomOrder()
                ->first();

        $frames = $timelapse
            ? $timelapse->frames->sortBy('sort_order')->pluck('url')->all()
            : [];

        $frameCount = max(count($frames), 1);
        $middleTick = (int) ceil($frameCount / 2);

        return view('livewire.timelapse-section', [
            'frames' => $frames,
            'frameCount' => $frameCount,
            'middleTick' => $middleTick,
            'timelapse' => $timelapse,
        ]);
    }
}
