<?php

namespace App\Livewire;

use App\Models\ProjectTimelapse;
use App\Support\SEO\SEOBuilder;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Every before/after timelapse we have, on one page (/timelapses).
 *
 * The timelapses already existed but were only reachable one at a time —
 * <livewire:timelapse-section> picks a random one on the home page, the
 * projects index and the area pages, so a visitor had no way to see them all
 * or to find a specific project's.
 *
 * Deduplicated by project: several projects carry two timelapse records of the
 * same job (ids 2/3, 4/5, 6/7), and showing both would read as two different
 * remodels. The one with the most frames wins, since that is the fuller
 * sequence.
 */
#[Layout('components.layouts.app')]
class TimelapsesPage extends Component
{
    public function render()
    {
        $timelapses = ProjectTimelapse::query()
            ->with(['frames', 'project'])
            ->whereHas('frames')
            ->get()
            ->sortByDesc(fn (ProjectTimelapse $t) => $t->frames->count())
            // Keyed by project so duplicates of the same job collapse; records
            // with no project keep their own key rather than colliding on null.
            ->unique(fn (ProjectTimelapse $t) => $t->project_id ?? 'orphan-' . $t->id)
            ->sortByDesc(fn (ProjectTimelapse $t) => optional($t->project)->completed_at)
            ->values();

        app(SEOBuilder::class)
            ->title('Before & After Timelapses')
            ->description('Watch ' . $timelapses->count() . ' Chicago-suburb remodels go from demolition to '
                . 'final walkthrough — real before-and-after timelapses of kitchens, bathrooms and whole-home '
                . 'projects built by ' . config('brand.name') . '.')
            ->canonical(url('/timelapses'))
            ->url(url('/timelapses'))
            ->type('website');

        return view('livewire.timelapses-page', [
            'timelapses' => $timelapses,
        ]);
    }
}
