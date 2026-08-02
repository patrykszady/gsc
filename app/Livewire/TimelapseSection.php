<?php

namespace App\Livewire;

use App\Models\ProjectTimelapse;
use Livewire\Component;

class TimelapseSection extends Component
{
    public ?int $timelapseId = null;

    /**
     * Heading printed above the frame. Null hides it.
     *
     * The gallery page stacks eight of these and labels each with its own
     * project, so the generic line would repeat eight times.
     */
    public ?string $heading = 'Before & After & Timelapse';

    public function placeholder(): string
    {
        // Mirrors the hydrated shell: heading line + the fixed-height frame
        // (h-[375px] sm:h-[450px] lg:h-[525px]) with no section padding. The
        // old placeholder was a padded zinc band with an aspect-video box, so
        // the homepage visibly reflowed when the real component hydrated.
        return <<<'HTML'
        <section>
            <div class="mb-4 h-5"></div>
            <div class="relative h-[375px] animate-pulse rounded-2xl bg-zinc-200 sm:h-[450px] lg:h-[525px] dark:bg-zinc-700"></div>
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
