<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Models\SeoAction;
use App\Services\Seo\SeoAutopilotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * Ported from the Livewire admin's SeoAutopilotPanel (nested inside the SEO
 * Reports page there; a standalone screen's worth of endpoints here).
 *
 * run/apply/revert all reach the real Autopilot appliers (Google Indexing
 * API pings, llms.txt regen, page creation, title/meta rewrites) — never
 * exercise those three against the live app. skip() only writes a status
 * column and is safe, but a skip has no inverse in the source UI either, so
 * it is still left to phpunit rather than a live round-trip.
 */
class SeoAutopilotController extends Controller
{
    use BuildsApiResponses;

    public function index(Request $request): JsonResponse
    {
        $tab = in_array($request->string('tab', 'open')->toString(), ['open', 'applied', 'all'], true)
            ? $request->string('tab', 'open')->toString()
            : 'open';

        $query = match ($tab) {
            'applied' => SeoAction::whereIn('status', [SeoAction::STATUS_APPLIED, SeoAction::STATUS_REVERTED])
                ->orderByRaw('(measured_at IS NULL) DESC')
                ->orderByRaw('CASE WHEN measured_at IS NULL THEN COALESCE(measure_after, applied_at) END ASC')
                ->orderByDesc('measured_at'),
            'all' => SeoAction::query()->orderByDesc('priority'),
            default => SeoAction::open()->orderByDesc('priority'),
        };

        $paginator = $query->paginate($this->perPage($request, 20));

        $response = $this->paginatedResponse($paginator, fn (SeoAction $a) => $this->serialize($a));
        $payload = $response->getData(true);
        $payload['stats'] = $this->stats();
        $payload['weights'] = $this->weights();

        return response()->json($payload);
    }

    /** Sole manual override — the identical cycle runs on the schedule daily. */
    public function run(Request $request): JsonResponse
    {
        Artisan::call('seo:autopilot', ['--max' => 25]);
        $message = 'Autopilot run complete. '.trim(str(Artisan::output())->explode("\n")->first());

        return $this->itemResponse(['message' => $message, 'stats' => $this->stats()]);
    }

    public function apply(Request $request, int $action): JsonResponse
    {
        $model = SeoAction::open()->whereKey($action)->first();
        if (! $model) {
            abort(404, 'No open action with that id.');
        }

        $result = app(SeoAutopilotService::class)->applyOne($model);

        return $this->itemResponse([
            'message' => $result ? "Applied: {$model->title}" : "Could not apply #{$action}.",
            'action' => $this->serialize($model->fresh()),
        ]);
    }

    public function revert(Request $request, int $action): JsonResponse
    {
        $model = SeoAction::applied()->whereKey($action)->first();
        if (! $model) {
            abort(404, 'No applied action with that id.');
        }

        app(SeoAutopilotService::class)->revert($model);

        return $this->itemResponse([
            'message' => "Reverted: {$model->title}",
            'action' => $this->serialize($model->fresh()),
        ]);
    }

    public function skip(Request $request, int $action): JsonResponse
    {
        $updated = SeoAction::open()->whereKey($action)->update(['status' => SeoAction::STATUS_SKIPPED]);

        if (! $updated) {
            abort(404, 'No open action with that id.');
        }

        return $this->itemResponse([
            'message' => 'Action skipped.',
            'action' => $this->serialize(SeoAction::findOrFail($action)),
        ]);
    }

    /**
     * @return array{open:int,applied:int,reverted:int,worked:int,regressed:int,no_effect:int,est_uplift:float}
     */
    protected function stats(): array
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
     * @return array<int,array{category:string,weight:float,worked:int,regressed:int,no_effect:int,measuring:int,next_due:?string}>
     */
    protected function weights(): array
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
                'measuring' => SeoAction::where('category', $cat)->whereNotNull('applied_at')->whereNull('measured_at')->count(),
                'next_due' => ($due = SeoAction::where('category', $cat)->whereNull('measured_at')->whereNotNull('measure_after')->min('measure_after'))
                    ? 'in '.Carbon::parse($due)->diffForHumans(null, true)
                    : null,
            ];
        }

        return $out;
    }

    protected function serialize(SeoAction $a): array
    {
        return [
            'id' => $a->id,
            'category' => $a->category,
            'risk' => $a->risk,
            'title' => $a->title,
            'hypothesis' => $a->hypothesis,
            'payload' => $a->payload,
            'priority' => $a->priority,
            'impact_score' => $a->impact_score,
            'status' => $a->status,
            'outcome' => $a->outcome,
            'auto_applied' => $a->auto_applied,
            'delta_pct' => $a->delta_pct,
            'target_url' => $a->target_url,
            'applied_at' => $a->applied_at?->toIso8601String(),
            'measure_after' => $a->measure_after?->toIso8601String(),
            'measure_after_human' => $a->measure_after?->diffForHumans(),
            'measured_at' => $a->measured_at?->toIso8601String(),
            'is_safe_allowlisted' => in_array($a->category, SeoAutopilotService::SAFE_ALLOWLIST, true),
        ];
    }
}
