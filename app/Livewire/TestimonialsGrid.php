<?php

namespace App\Livewire;

use App\Models\AreaServed;
use App\Models\ProjectImage;
use App\Models\Testimonial;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class TestimonialsGrid extends Component
{
    public ?AreaServed $area = null;

    public bool $showHeader = true;

    /**
     * Render the five-star graphic under the heading.
     *
     * Opt-in, because the asset asserts five stars. /reviews turns it on: all
     * 70 visible testimonials are 5/5 (avg 5.0). Anywhere the shown reviews
     * could include a lower rating, leave it off.
     */
    public bool $showStars = false;

    public int $visibleRows = 3; // Start with 3 rows (top + row2 + row3)

    /**
     * Seeds every random choice in this component (pool order, featured pick,
     * image assignment). Mounted once, so loadMore() re-renders extend the SAME
     * shuffle instead of dealing every card a new order and photo — the grid
     * used to visibly reshuffle on each click. ProjectsGrid set the precedent
     * (its $randomSeed, for the same reason).
     */
    public int $randomSeed = 0;

    /** @var \Illuminate\Support\Collection<int, ProjectImage>|null In-memory image pool for the render pass. */
    protected $imagePool = null;

    /** @var array<string, string>|null lowercase city => area slug. */
    protected ?array $areaSlugMap = null;

    public function mount(): void
    {
        $this->randomSeed = random_int(1, PHP_INT_MAX >> 8);
    }

    public function loadMore(): void
    {
        $this->visibleRows += 2; // Load 2 more rows each time
    }

    public function render()
    {
        // ONE image pool and ONE city=>slug map for the whole render.
        //
        // formatTestimonial() used to run up to nine queries PER ROW (linked
        // image x4, by-type image x2, global fallback x2, plus an AreaServed
        // lookup) across a ~70-row pool — ~150 queries per render, repeated in
        // full on every loadMore(). 227 eligible images fit comfortably in
        // memory, so every per-row pick is now an in-memory filter.
        mt_srand($this->randomSeed);

        $this->imagePool = ProjectImage::query()
            ->whereHas('project', fn ($q) => $q->published())
            ->with('project:id,project_type')
            ->get()
            ->shuffle(mt_rand());

        $this->areaSlugMap = AreaServed::query()
            ->pluck('slug', 'city')
            ->mapWithKeys(fn ($slug, $city) => [mb_strtolower(trim($city)) => $slug])
            ->all();

        // First 10 random from the last 6 years — RAND(seed), not
        // inRandomOrder(): the pool must not re-deal between loadMore() calls.
        $recentCutoff = now()->subYears(6)->startOfDay();

        $recentTestimonials = Testimonial::query()
            ->visible()
            ->whereNotNull('review_date')
            ->where('review_date', '>=', $recentCutoff)
            ->with('projects:id')
            ->tap(fn ($q) => \App\Support\SeededRandom::order($q, $this->randomSeed))
            ->take(10)
            ->get();

        $recentIds = $recentTestimonials->pluck('id')->toArray();

        // Then random older ones
        $olderTestimonials = Testimonial::query()
            ->visible()
            ->whereNotIn('id', $recentIds)
            ->with('projects:id')
            ->tap(fn ($q) => \App\Support\SeededRandom::order($q, $this->randomSeed))
            ->get();
        
        // Combine: recent first, then older
        $allTestimonials = $recentTestimonials->concat($olderTestimonials);
        
        $usedImageIds = [];

        $testimonials = $allTestimonials
            ->map(fn ($t) => $this->formatTestimonial($t, $usedImageIds))
            ->values();

        // Pick a random featured testimonial from recent ones (biased toward longer descriptions).
        $recentFormatted = $testimonials
            ->whereIn('id', $recentIds)
            ->values();

        $featuredPoolSource = $recentFormatted;
        if ($this->area) {
            $areaSlug = $this->area->slug;
            // Filter the FULL collection, not the 10-row recent sample. The
            // sample is random and recent-only, so a town whose own reviews are
            // older than 6 years (Buffalo Grove, Evanston, Chicago — 11 towns)
            // could NEVER lead with a local review, and towns with few local
            // reviews only led locally when the draw happened to include one.
            // The badge and quote cards beside this are strictly local-first,
            // so the spotlight was contradicting them.
            $areaFeatured = $testimonials->filter(fn ($t) => $t['area_slug'] === $areaSlug);
            if ($areaFeatured->isNotEmpty()) {
                $featuredPoolSource = $areaFeatured;
            }
        }

        $featuredPool = $featuredPoolSource
            ->sortByDesc(fn ($t) => strlen($t['description'] ?? ''))
            ->take(6)
            ->values();

        $featuredSource = ($featuredPool->isNotEmpty() ? $featuredPool : $testimonials)->values();
        // Seeded pick — Collection::random() uses the CSPRNG and re-picked a
        // different spotlight on every loadMore(). Null on an empty database
        // (the view guards @if($featured)); the old ->random() threw there.
        $featured = $featuredSource->isNotEmpty()
            ? $featuredSource[$this->randomSeed % $featuredSource->count()]
            : null;

        if ($this->area) {
            $areaSlug = $this->area->slug;
            [$areaFirst, $other] = $testimonials->partition(fn ($t) => $t['area_slug'] === $areaSlug);
            $testimonials = $areaFirst->concat($other)->values();
        }

        // Remaining testimonials - keep order (recent first).
        $others = $testimonials->reject(fn ($t) => $featured && $t['id'] === $featured['id'])->values();

        // Calculate how many testimonials we can show based on visible rows
        // Row 1: featured (2 cols) + leftTop + rightTop = 3 testimonials from $others (indices 0, 1)
        // Row 2: 4 testimonials (indices 2-5)
        // Row 3: 4 testimonials (indices 6-9)
        // Row 4+: 4 testimonials each
        $maxVisible = 2 + (($this->visibleRows - 1) * 4); // 2 for top row sides, then 4 per additional row
        $hasMore = $others->count() > $maxVisible;

        return view('livewire.testimonials-grid', [
            'featured' => $featured,
            'testimonials' => $others,
            'area' => $this->area,
            'visibleRows' => $this->visibleRows,
            'hasMore' => $hasMore,
        ]);
    }

    protected function formatTestimonial(Testimonial $testimonial, array &$usedImageIds): array
    {
        $projectType = $this->normalizeProjectType($testimonial->project_type);

        // Every pick is an in-memory filter over the preloaded, seed-shuffled
        // pool — the same preference order the old per-row queries expressed:
        // linked cover, linked any, same-type unused, same-type any, global
        // unused, global any.
        $image = $this->linkedProjectImage($testimonial, $usedImageIds);

        if ($projectType) {
            $ofType = $this->imagePool->filter(fn ($i) => $i->project?->project_type === $projectType);
            $image ??= $ofType->first(fn ($i) => ! in_array($i->id, $usedImageIds, true))
                ?? $ofType->first();
        }

        $image ??= $this->imagePool->first(fn ($i) => ! in_array($i->id, $usedImageIds, true))
            ?? $this->imagePool->first();

        if ($image) {
            $usedImageIds[] = $image->id;
        }

        // Always prefer a real project image over generated initials avatars.
        $imageUrl = $image?->getThumbnailUrl('medium') ?: $this->fallbackProjectImageUrl();

        // Extract city name (strip ", IL" or similar state suffix)
        $cityName = preg_replace('/,\s*[A-Z]{2}$/', '', $testimonial->project_location);

        return [
            'id' => $testimonial->id,
            'slug' => $testimonial->slug,
            'name' => $testimonial->display_name,
            'location' => $testimonial->project_location,
            'area_slug' => $this->areaSlugMap[mb_strtolower(trim((string) $cityName))] ?? null,
            'project_type' => $testimonial->project_type,
            'description' => $testimonial->review_description,
            'date' => $testimonial->review_date?->format('M Y'),
            'image' => $imageUrl,
        ];
    }

    protected function normalizeProjectType(?string $testimonialProjectType): ?string
    {
        if (! $testimonialProjectType) {
            return null;
        }

        $type = strtolower(trim($testimonialProjectType));

        return match ($type) {
            'kitchens', 'kitchen' => 'kitchen',
            'bathrooms', 'bathroom' => 'bathroom',
            'basements', 'basement' => 'basement',
            'home-remodel', 'home-remodels', 'home remodel', 'home remodels', 'whole-home', 'whole home' => 'home-remodel',
            'additions', 'addition' => 'addition',
            'mudroom', 'mudrooms', 'laundry', 'laundry room', 'laundry rooms', 'mudroom/laundry', 'mudroom / laundry' => 'mudroom',
            'exteriors', 'exterior' => 'exterior',
            default => null,
        };
    }

    protected function fallbackProjectImageUrl(): string
    {
        return Cache::remember('testimonials.fallback-project-image.medium.v1', now()->addMinutes(30), function () {
            $image = ProjectImage::query()
                ->whereHas('project', fn ($q) => $q->published())
                ->inRandomOrder()
                ->first();

            return $image?->getThumbnailUrl('medium') ?: asset('images/greg-patryk-thumb.jpg');
        });
    }

    protected function linkedProjectImage(Testimonial $testimonial, array &$usedImageIds): ?ProjectImage
    {
        $linkedProjectIds = collect([$testimonial->project_id])
            ->filter()
            ->merge($testimonial->projects->pluck('id'))
            ->unique()
            ->values();

        if ($linkedProjectIds->isEmpty()) {
            return null;
        }

        $linked = $this->imagePool->filter(fn ($i) => $linkedProjectIds->contains($i->project_id));
        $unused = fn ($i) => ! in_array($i->id, $usedImageIds, true);

        return $linked->first(fn ($i) => $i->is_cover && $unused($i))
            ?? $linked->first($unused)
            // If the linked pool is too small to stay unique, still prefer linked images.
            ?? $linked->first(fn ($i) => (bool) $i->is_cover)
            ?? $linked->first();
    }
}
