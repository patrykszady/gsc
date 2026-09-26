<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\BingDailyTotal;
use App\Models\ContactSubmission;
use App\Models\GscCoverageState;
use App\Models\GscDailyTotal;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\SeoAction;
use App\Models\Tag;
use App\Models\Testimonial;
use App\Models\TrackedEvent;
use App\Services\Seo\RecommendationEngine;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
                // Every tenant's dashboard shows the same trend-tile grid
                // (owner's rule: "dashboards show trends, not lone
                // numbers"). The first four are the COMMON row every site
                // sends (leads, contacts, search clicks, reviews); projects
                // and photos are this site's own. Cheap single-aggregate
                // queries, cached per tenant for 5 minutes — see tiles().
                'tiles' => $this->tiles(),
            ],
        ]);
    }

    /**
     * The generic stat-tile row ss-systems renders on every dashboard —
     * {key, label, value, note, delta_pct, href}, in the fixed order the
     * central admin expects. Cached 5 minutes per tenant (Tenancy::cacheKey)
     * since every source here is a single aggregate query but the dashboard
     * is loaded often.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function tiles(): array
    {
        return Cache::remember(Tenancy::cacheKey('dashboard.tiles'), now()->addMinutes(5), fn () => [
            $this->leadsTile(),
            $this->contactsTile(),
            $this->searchClicksTile(),
            $this->reviewsTile(),
            $this->projectsTile(),
            $this->photosTile(),
        ]);
    }

    protected function leadsTile(): array
    {
        $start = now()->subDays(7);
        $priorStart = now()->subDays(14);

        $value = ContactSubmission::query()
            ->where('status', '!=', 'spam')
            ->where('created_at', '>=', $start)
            ->count();

        $prior = ContactSubmission::query()
            ->where('status', '!=', 'spam')
            ->where('created_at', '>=', $priorStart)
            ->where('created_at', '<', $start)
            ->count();

        $total = ContactSubmission::query()->where('status', '!=', 'spam')->count();

        return [
            'key' => 'leads',
            'label' => 'Leads (7 days)',
            'value' => $value,
            'note' => number_format($total).' total',
            'delta_pct' => $this->deltaPct($value, $prior),
            'href' => 'leads',
        ];
    }

    /**
     * Phone clicks + email clicks + form submits, from the exact same
     * TrackedEvent counts AnalyticsController::summary() reports — reused
     * via its countsByType(), not recounted here.
     */
    protected function contactsTile(): array
    {
        $start = now()->subDays(7);
        $priorStart = now()->subDays(14);

        $current = AnalyticsController::countsByType(TrackedEvent::query()->where('created_at', '>=', $start));
        $prior = AnalyticsController::countsByType(
            TrackedEvent::query()->where('created_at', '>=', $priorStart)->where('created_at', '<', $start)
        );

        $value = $current['phone'] + $current['email'] + $current['form'];
        $priorValue = $prior['phone'] + $prior['email'] + $prior['form'];

        return [
            'key' => 'contacts',
            'label' => 'Calls, emails & forms (7 days)',
            'value' => $value,
            'note' => null,
            'delta_pct' => $this->deltaPct($value, $priorValue),
            'href' => 'analytics',
        ];
    }

    /**
     * Google + Bing clicks over the newest 7 days actually present in the
     * daily-totals tables (Search Console lags 2-3 days, so "the last 7
     * days" would silently undercount the most recent ones), and the 7
     * days before those for the delta.
     */
    protected function searchClicksTile(): array
    {
        $maxDate = collect([
            GscDailyTotal::query()->max('date'),
            BingDailyTotal::query()->max('date'),
        ])->filter()->map(fn ($d) => Carbon::parse($d))->max();

        if (! $maxDate) {
            return [
                'key' => 'search_clicks',
                'label' => 'Search clicks (7 days)',
                'value' => 0,
                'note' => '0 impressions',
                'delta_pct' => null,
                'href' => 'seo',
            ];
        }

        $end = $maxDate->copy()->startOfDay();
        $start = $end->copy()->subDays(6);
        $priorEnd = $start->copy()->subDay();
        $priorStart = $priorEnd->copy()->subDays(6);

        [$clicks, $impressions] = $this->searchTotals($start, $end);
        [$priorClicks] = $this->searchTotals($priorStart, $priorEnd);

        return [
            'key' => 'search_clicks',
            'label' => 'Search clicks (7 days)',
            'value' => $clicks,
            'note' => number_format($impressions).' impressions',
            'delta_pct' => $this->deltaPct($clicks, $priorClicks),
            'href' => 'seo',
        ];
    }

    /**
     * [clicks, impressions] summed across Google + Bing for the window.
     * whereDate() rather than whereBetween('date', [...]): the `date`
     * column stores a plain calendar date on MySQL (which truncates the
     * time off whatever Eloquent's `date` cast writes — see CLAUDE.md), but
     * a whereBetween against two plain 'Y-m-d' bounds would still exclude a
     * row whose engine keeps the full datetime string (SQLite's dynamic
     * typing, used in tests): '2026-09-22 00:00:00' sorts AFTER the bound
     * string '2026-09-22'. whereDate() wraps both sides in DATE(), so the
     * comparison is calendar-date-only on every driver.
     *
     * @return array{0:int,1:int}
     */
    protected function searchTotals(Carbon $start, Carbon $end): array
    {
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

        $scope = fn ($query) => $query->whereDate('date', '>=', $startDate)->whereDate('date', '<=', $endDate);

        $clicks = (int) $scope(GscDailyTotal::query())->sum('clicks')
            + (int) $scope(BingDailyTotal::query())->sum('clicks');
        $impressions = (int) $scope(GscDailyTotal::query())->sum('impressions')
            + (int) $scope(BingDailyTotal::query())->sum('impressions');

        return [$clicks, $impressions];
    }

    protected function reviewsTile(): array
    {
        $start = now()->subDays(30);
        $priorStart = now()->subDays(60);

        $total = Testimonial::query()->visible()->count();
        $new = Testimonial::query()->visible()->where('created_at', '>=', $start)->count();
        $priorNew = Testimonial::query()->visible()
            ->where('created_at', '>=', $priorStart)
            ->where('created_at', '<', $start)
            ->count();

        return [
            'key' => 'reviews',
            'label' => 'Reviews',
            'value' => $total,
            'note' => '+'.number_format($new).' in 30 days',
            'delta_pct' => $this->deltaPct($new, $priorNew),
            'href' => 'reviews',
        ];
    }

    protected function projectsTile(): array
    {
        $start = now()->subDays(30);
        $priorStart = now()->subDays(60);

        $total = Project::query()->published()->count();
        $new = Project::query()->published()->where('created_at', '>=', $start)->count();
        $priorNew = Project::query()->published()
            ->where('created_at', '>=', $priorStart)
            ->where('created_at', '<', $start)
            ->count();

        return [
            'key' => 'projects',
            'label' => 'Projects',
            'value' => $total,
            'note' => '+'.number_format($new).' in 30 days',
            'delta_pct' => $this->deltaPct($new, $priorNew),
            'href' => 'projects',
        ];
    }

    /**
     * No cheap ledger of which photos are on Google exists on THIS side —
     * GBP uploads are owned by ss-systems (GBP_PHOTOS_OWNED_BY, see
     * CLAUDE.md), which keeps that count, not this app. Note stays null
     * rather than guessing from the retired local uploader's stale rows.
     */
    protected function photosTile(): array
    {
        return [
            'key' => 'photos',
            'label' => 'Photos',
            'value' => ProjectImage::query()->count(),
            'note' => null,
            'delta_pct' => null,
            'href' => 'projects',
        ];
    }

    /** vs the prior same-length window; null when there's nothing to compare. */
    protected function deltaPct(int|float $current, int|float $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
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
