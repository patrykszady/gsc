<?php

namespace App\Livewire\Admin;

use App\Models\SeoAction;
use App\Services\Seo\SeoAutopilotService;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin surface for the self-improving SEO Autopilot. Shows the scored action
 * ledger, what's been applied and how it measured, the learned per-category
 * weights, and lets the operator run the loop or apply/skip/revert individual
 * actions on demand.
 */
#[Layout('components.layouts.admin')]
#[Title('SEO Autopilot')]
class SeoAutopilotPanel extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'open'; // open | applied | all

    public ?string $flash = null;

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    /** Synthesize + measure now, without auto-applying (operator reviews first). */
    public function synthesize(): void
    {
        $svc = app(SeoAutopilotService::class);
        $svc->measure();
        $created = $svc->synthesize();
        $this->flash = "Synthesized {$created} new action(s) and refreshed measurements.";
        $this->clearCaches();
    }

    /** Full autonomous run: measure, synthesize, auto-apply the safe allowlist. */
    public function runAutopilot(): void
    {
        Artisan::call('seo:autopilot', ['--max' => 25]);
        $this->flash = 'Autopilot run complete. ' . trim(str(Artisan::output())->explode("\n")->first());
        $this->clearCaches();
    }

    public function applyOne(int $id): void
    {
        $action = SeoAction::open()->whereKey($id)->first();
        if (! $action) {
            return;
        }
        // Apply just this one through the service (cap 1 by pre-lowering others
        // is unnecessary — we call the applier path directly via act-of-one).
        $svc = app(SeoAutopilotService::class);
        $result = $svc->applyOne($action);
        $this->flash = $result ? "Applied: {$action->title}" : "Could not apply #{$id}.";
        $this->clearCaches();
    }

    public function revertOne(int $id): void
    {
        $action = SeoAction::applied()->whereKey($id)->first();
        if (! $action) {
            return;
        }
        app(SeoAutopilotService::class)->revert($action);
        $this->flash = "Reverted: {$action->title}";
        $this->clearCaches();
    }

    public function skipOne(int $id): void
    {
        SeoAction::open()->whereKey($id)->update(['status' => SeoAction::STATUS_SKIPPED]);
        $this->flash = 'Action skipped.';
    }

    private function clearCaches(): void
    {
        unset($this->stats, $this->weights);
    }

    /**
     * @return array{open:int,applied:int,reverted:int,worked:int,regressed:int,no_effect:int,est_uplift:float}
     */
    #[Computed]
    public function stats(): array
    {
        return [
            'open' => SeoAction::open()->count(),
            'applied' => SeoAction::applied()->count(),
            'reverted' => SeoAction::where('status', SeoAction::STATUS_REVERTED)->count(),
            'worked' => SeoAction::where('outcome', SeoAction::OUTCOME_WORKED)->count(),
            'regressed' => SeoAction::where('outcome', SeoAction::OUTCOME_REGRESSED)->count(),
            'no_effect' => SeoAction::where('outcome', SeoAction::OUTCOME_NO_EFFECT)->count(),
            'est_uplift' => round((float) SeoAction::open()->sum('impact_score'), 0),
        ];
    }

    /**
     * @return array<int,array{category:string,weight:float,worked:int,regressed:int,no_effect:int}>
     */
    #[Computed]
    public function weights(): array
    {
        $svc = app(SeoAutopilotService::class);
        $out = [];
        foreach (SeoAutopilotService::SAFE_ALLOWLIST as $cat) {
            $out[] = [
                'category' => $cat,
                'weight' => $svc->learnedWeight($cat),
                'worked' => SeoAction::where('category', $cat)->where('outcome', SeoAction::OUTCOME_WORKED)->count(),
                'regressed' => SeoAction::where('category', $cat)->where('outcome', SeoAction::OUTCOME_REGRESSED)->count(),
                'no_effect' => SeoAction::where('category', $cat)->where('outcome', SeoAction::OUTCOME_NO_EFFECT)->count(),
            ];
        }

        return $out;
    }

    public function render()
    {
        $query = match ($this->tab) {
            'applied' => SeoAction::whereIn('status', [SeoAction::STATUS_APPLIED, SeoAction::STATUS_REVERTED])
                ->orderByDesc('applied_at'),
            'all' => SeoAction::query()->orderByDesc('priority'),
            default => SeoAction::open()->orderByDesc('priority'),
        };

        return view('livewire.admin.seo-autopilot-panel', [
            'actions' => $query->paginate(20),
        ]);
    }
}
