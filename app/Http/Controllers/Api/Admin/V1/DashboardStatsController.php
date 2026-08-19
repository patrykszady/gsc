<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\ContactSubmission;
use App\Models\GscCoverageState;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\SeoAction;
use App\Models\Tag;
use App\Models\Testimonial;
use App\Services\Seo\RecommendationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class DashboardStatsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'projects' => [
                    'total' => Project::query()->count(),
                    'published' => Project::query()->published()->count(),
                    'featured' => Project::query()->featured()->count(),
                ],
                'testimonials' => [
                    'total' => Testimonial::query()->count(),
                    'visible' => Testimonial::query()->visible()->count(),
                ],
                // Restored from the legacy monolith Dashboard's 5-tile stat
                // grid (Total Projects, Published, Images, Tags, Leads).
                'images' => [
                    'total' => ProjectImage::query()->count(),
                ],
                'tags' => [
                    'total' => Tag::query()->count(),
                ],
                'leads' => [
                    'total' => ContactSubmission::query()->count(),
                    'pending' => ContactSubmission::query()->where('status', 'pending')->count(),
                    'today' => ContactSubmission::query()->whereDate('created_at', now()->today())->count(),
                    'this_week' => ContactSubmission::query()->where('created_at', '>=', now()->subWeek())->count(),
                ],
                // Built here rather than via Project::toApiArray() (owned by
                // another agent, and columnless of created_at): the legacy
                // Dashboard's Recent Projects table has a "Created" column
                // (created_at->diffForHumans()), so the rows this screen
                // needs are assembled directly in the one payload this
                // controller owns.
                'recent_projects' => Project::query()
                    ->with('images')
                    ->latest()
                    ->take(5)
                    ->get()
                    ->map(fn (Project $project) => [
                        'id' => $project->id,
                        'title' => $project->title,
                        'project_type' => $project->project_type,
                        'is_published' => (bool) $project->is_published,
                        'cover_url' => $project->cover()?->url,
                        'created_at' => optional($project->created_at)->toIso8601String(),
                    ])
                    ->all(),
                'recent_leads' => ContactSubmission::query()
                    ->latest()
                    ->take(5)
                    ->get()
                    ->map(fn (ContactSubmission $lead) => $lead->toApiArray())
                    ->all(),
                // Restored from the legacy monolith Dashboard (app/Livewire/
                // Admin/Dashboard.php), dropped in the first port. gsc-only:
                // any tenant/site without the SEO Autopilot tables simply
                // never populates this — every source below is individually
                // guarded, same as the original, so one missing table
                // degrades one number instead of the whole block. A site
                // whose own dashboard-stats response omits "automation"
                // entirely (e.g. jpeterson's separate application) is
                // handled on the ss-systems side, not here.
                'automation' => $this->automationSnapshot(),
            ],
        ]);
    }

    /**
     * @return array{open:int,applied_week:int,measuring:int,next_outcome:?string,problems:int,advisories:int,urgent:array<int,string>,engine_at:?string,healed:int}
     */
    protected function automationSnapshot(): array
    {
        $out = [
            'open' => 0, 'applied_week' => 0, 'measuring' => 0, 'next_outcome' => null,
            'problems' => 0, 'advisories' => 0, 'urgent' => [], 'engine_at' => null, 'healed' => 0,
        ];

        try {
            if (Schema::hasTable('seo_actions')) {
                $out['open'] = (int) SeoAction::where('status', SeoAction::STATUS_PROPOSED)->count();
                $out['applied_week'] = (int) SeoAction::where('applied_at', '>=', now()->subDays(7))->count();
                $out['measuring'] = (int) SeoAction::whereNotNull('applied_at')->whereNull('measured_at')->count();
                $due = SeoAction::whereNull('measured_at')->whereNotNull('measure_after')->min('measure_after');
                $out['next_outcome'] = $due ? 'in '.Carbon::parse($due)->diffForHumans(null, true) : null;
                $out['advisories'] = (int) SeoAction::where('risk', SeoAction::RISK_REVIEW)
                    ->where('status', SeoAction::STATUS_PROPOSED)->count();
            }
        } catch (\Throwable) {
        }

        try {
            if (Schema::hasTable('gsc_coverage_states')) {
                $out['problems'] = (int) GscCoverageState::where(
                    fn ($q) => $q->where('verdict', '!=', 'PASS')->orWhereNull('verdict')
                )->count();
            }
        } catch (\Throwable) {
        }

        try {
            $engine = RecommendationEngine::latest();
            if ($engine) {
                $out['engine_at'] = Carbon::parse($engine['generated_at'])->diffForHumans();
                $out['healed'] = count($engine['healed'] ?? []);
                $out['urgent'] = array_values(array_filter(
                    $engine['action_items'] ?? [],
                    fn ($i) => str_starts_with($i, 'URGENT')
                ));
            }
        } catch (\Throwable) {
        }

        return $out;
    }
}
