<?php

namespace App\Services;

use App\Models\AreaServed;
use App\Models\Project;
use App\Models\Testimonial;
use App\Support\SEO\SEOBuilder;
use Illuminate\Support\Str;

class SeoService
{
    protected static function seo(): SEOBuilder
    {
        return app(SEOBuilder::class);
    }

    /**
     * Apply domain-specific SEO enhancements.
     * Call this at the end of any page's SEO setup when on alternate domains.
     */
    public static function applyDomainEnhancements(): void
    {
        $domainConfig = view()->shared('domainConfig');
        $isAlternateDomain = view()->shared('isAlternateDomain', false);
        $primaryDomain = config('services.domains.primary', 'gs.construction');

        if (!$domainConfig) {
            return;
        }

        // Add domain-specific keywords
        if (!empty($domainConfig['keywords'])) {
            self::seo()->keywords($domainConfig['keywords']);
        }

        // Set canonical to primary domain (critical for SEO)
        // This tells search engines the primary domain is authoritative
        if ($isAlternateDomain) {
            $canonicalUrl = 'https://' . $primaryDomain . request()->getRequestUri();
            self::seo()->canonical($canonicalUrl)->url($canonicalUrl);
        }
    }

    /**
     * Get domain-aware title enhancement.
     * Adds domain-specific prefix/suffix if on alternate domain.
     */
    public static function getDomainAwareTitle(string $baseTitle): string
    {
        $domainConfig = view()->shared('domainConfig');
        
        if (!$domainConfig || empty($domainConfig['title_prefix'])) {
            return $baseTitle;
        }
        
        // Only enhance home page or main landing pages
        return $baseTitle;
    }

    /**
     * Set SEO tags for the home page.
     * Domain-aware: uses domain-specific title/description if on alternate domain.
     */
    public static function home(?AreaServed $area = null): void
    {
        $city = $area?->city;
        $domainConfig = view()->shared('domainConfig');
        $isAlternateDomain = view()->shared('isAlternateDomain', false);
        
        // Use domain-specific title/description if on alternate domain
        if ($isAlternateDomain && $domainConfig) {
            $title = $domainConfig['title_prefix'];
            $description = $domainConfig['description'];
        } elseif ($city) {
            // City landing page. Lead with city name (matches "{city} kitchen remodel" queries word-for-word)
            // and add a star + review-count proof element to lift CTR. Keep under ~58 chars before " | GS Construction".
            $reviewCount = self::getReviewCountLabel();
            $reviewNum = self::getReviewCountNumeric();
            $title = "{$city} Kitchen & Bath Remodeling — " . ($reviewNum ? "{$reviewNum}★ Reviews" : 'Local Pros');
            $description = "{$city}, IL homeowners' choice for kitchen, bathroom & full home remodels. {$reviewCount} 5-star reviews, licensed & insured, free in-home estimate. Call (224) 735-4200.";
        } else {
            // Lead with brand on the homepage so Google adopts "GS Construction"
            // as the site name (https://developers.google.com/search/docs/appearance/site-names)
            $title = 'GS Construction — Kitchen & Bathroom Remodeling, Chicagoland';
            $description = 'GS Construction is a family-owned kitchen, bathroom, and home remodeling contractor serving the Chicago suburbs. 40+ years experience, 5-star rated, free estimates.';
        }

        // Use a hero project image for home page OG sharing
        $image = self::getCoverImageForType('kitchen');

        self::setTags($title, $description, $image);
    }

    /**
     * Set SEO tags for the projects page.
     */
    public static function projects(?AreaServed $area = null, ?string $type = null): void
    {
        $city = $area?->city;
        $typeLabel = $type ? ucfirst(str_replace('-', ' ', $type)) : null;
        
        if ($typeLabel && $city) {
            $title = "{$typeLabel} Remodeling in {$city} - Our Work";
            $reviewCount = self::getReviewCountLabel();
            $description = "View our {$typeLabel} remodeling projects in {$city}, IL. Before & after photos, {$reviewCount} five-star reviews. See why homeowners trust GS Construction!";
        } elseif ($typeLabel) {
            $title = "{$typeLabel} Remodeling Portfolio";
            $description = "Browse our {$typeLabel} remodeling before & after photos. See real kitchen, bathroom & home renovation projects in the Chicago suburbs.";
        } elseif ($city) {
            $title = "Remodeling Projects in {$city}, IL";
            $reviewCount = self::getReviewCountLabel();
            $description = "View kitchen, bathroom & home remodeling projects in {$city}, IL. Real before & after photos, {$reviewCount} five-star reviews. Get inspired for your renovation!";
        } else {
            $title = 'Kitchen & Bathroom Remodeling Portfolio';
            $description = 'Browse our kitchen, bathroom & home remodeling projects in Chicagoland. Before & after photos from Arlington Heights, Palatine, Lake Zurich & more.';
        }

        // Get a relevant cover image
        $image = $type ? self::getCoverImageForType($type) : self::getCoverImageForType('kitchen');

        self::setTags($title, $description, $image);
    }

    /**
     * Set SEO tags for testimonials page.
     */
    public static function testimonials(?AreaServed $area = null): void
    {
        $city = $area?->city;
        
        // Keep under 70 chars with suffix
        $title = $city
            ? "Remodeling Reviews in {$city}, IL"
            : 'Kitchen & Bathroom Remodeling Reviews';
        
        $reviewCount = self::getReviewCountLabel();
        $description = $city
            ? "Read {$reviewCount} five-star reviews from {$city} homeowners. Real kitchen & bathroom remodeling experiences. See why we're the top-rated contractors in {$city}, IL."
            : "{$reviewCount} five-star rated kitchen & bathroom remodeling in Chicago suburbs. Read reviews from Arlington Heights, Palatine, Buffalo Grove & more homeowners.";

        // Use a relevant project cover image for testimonials pages
        $image = self::getCoverImageForType('kitchen');

        self::setTags($title, $description, $image);
    }

    /**
     * Set SEO tags for individual testimonial page.
     */
    public static function testimonial(Testimonial $testimonial): void
    {
        $name = $testimonial->display_name;
        $location = $testimonial->project_location;
        $rawType = $testimonial->project_type ?? 'home';
        
        // Normalize project type for display (remove hyphens, proper capitalization)
        $projectType = match(strtolower($rawType)) {
            'home-remodel' => 'Home',
            'kitchen' => 'Kitchen',
            'bathroom' => 'Bathroom',
            'basement' => 'Basement',
            default => ucfirst(str_replace('-', ' ', $rawType)),
        };
        
        // Keep under 70 chars total (with " | GS Construction" = 18 chars suffix)
        // So page title should be ~52 chars max
        $shortName = strlen($name) > 15 ? explode(' ', $name)[0] : $name;
        $title = "{$shortName}'s {$projectType} Remodel Review";
        
        $description = Str::limit($testimonial->review_description, 155);

        // Use a project cover image matching the review's service type
        $projectType = match(strtolower($rawType)) {
            'kitchen' => 'kitchen',
            'bathroom' => 'bathroom',
            'home-remodel', 'home' => 'home-remodel',
            'basement' => 'basement',
            default => 'kitchen',
        };
        $image = self::getCoverImageForType($projectType);

        self::setTags($title, $description, $image);
    }

    /**
     * Set SEO tags for about page.
     */
    public static function about(?AreaServed $area = null): void
    {
        $city = $area?->city;
        
        // Keep under 70 chars with suffix
        $title = $city
            ? "Remodeling Contractors in {$city}"
            : 'About Us - Family-Owned Contractors';
        
        $reviewCount = self::getReviewCountLabel();
        $description = $city
            ? "Meet GS Construction — family-owned remodeling contractors serving {$city}, IL. {$reviewCount} five-star reviews, 40+ years experience. Licensed & insured."
            : 'Meet Greg & Patryk — family-owned kitchen & bathroom remodeling contractors in Chicago. 40+ years combined experience. Licensed & insured.';

        self::setTags($title, $description, asset('images/greg-patryk.jpg'));
    }

    /**
     * Set SEO tags for contact page.
     */
    public static function contact(?AreaServed $area = null): void
    {
        $city = $area?->city;
        
        // Keep under 70 chars with suffix
        $title = $city
            ? "Free {$city} Remodeling Estimate"
            : 'Get a Free Remodeling Quote';
        
        $description = $city
            ? "Request a free kitchen or bathroom remodeling estimate in {$city}, IL. Call (224) 735-4200 or schedule online. Same-week consultations available!"
            : 'Get a free kitchen or bathroom remodeling estimate in Chicago suburbs. Call (224) 735-4200 or schedule online. Same-week consultations available!';

        // Use the team photo for contact page — builds trust
        self::setTags($title, $description, asset('images/greg-patryk.jpg'));
    }

    /**
     * Set SEO tags for areas served index page.
     */
    public static function areasServed(): void
    {
        // Keep under 52 chars (suffix adds ~18 chars)
        $title = 'Service Areas - Chicago Suburbs';
        $description = 'Kitchen & bathroom remodeling in Arlington Heights, Palatine, Buffalo Grove, Barrington, Lake Zurich & 50+ Chicago suburbs. Local contractors, free estimates.';

        // Use a project cover image for areas served
        $image = self::getCoverImageForType('kitchen');

        self::setTags($title, $description, $image);
    }

    /**
     * Set SEO tags for services overview page.
     */
    public static function services(?AreaServed $area = null): void
    {
        $city = $area?->city;
        
        // Keep titles under 70 chars (with " | GS Construction" suffix = 18 chars)
        // Max page title: ~52 chars for longest city names (17 chars)
        $title = $city
            ? "{$city} Remodeling Services — Kitchen, Bath & Whole-Home"
            : 'Kitchen, Bathroom & Home Remodeling Services';
        
        $reviewCount = self::getReviewCountLabel();
        $description = $city
            ? "Kitchen, bathroom & whole-home remodeling in {$city}, IL. {$reviewCount} 5-star reviews, 40+ yrs experience, licensed & insured. Free in-home estimate — call (224) 735-4200."
            : 'Kitchen remodeling, bathroom renovations & home remodeling in Chicago suburbs. Top-rated local contractors serving Palatine, Arlington Heights & more.';

        // Get a kitchen image as the default for services overview
        $image = self::getCoverImageForType('kitchen');

        self::setTags($title, $description, $image);

        self::seo()->keywords([
            'remodeling services',
            'kitchen remodeling',
            'bathroom remodeling',
            'home renovation',
            'basement finishing',
            'Chicago contractors',
            'home improvement',
        ]);
    }

    /**
     * Set SEO tags for individual project page.
     */
    public static function project(Project $project): void
    {
        $types = Project::projectTypes();
        $typeLabel = $types[$project->project_type] ?? ucfirst(str_replace('-', ' ', $project->project_type));
        
        // Build title: "Project Title | Kitchen Remodel"
        $title = $project->title;
        if ($project->location) {
            $title .= " in {$project->location}";
        }
        
        // Build description
        $description = $project->description 
            ? Str::limit($project->description, 155)
            : "View our {$typeLabel} project" . ($project->location ? " in {$project->location}" : '') . ". See photos and details of this beautiful renovation by GS Construction.";

        // Get cover image
        $image = null;
        if ($project->relationLoaded('images') && $project->images->isNotEmpty()) {
            $coverImage = $project->images->firstWhere('is_cover', true) ?? $project->images->first();
            $image = $coverImage->getWebpThumbnailUrl('large') ?? $coverImage->getThumbnailUrl('large') ?? $coverImage->url;
        }

        self::setTags($title, $description, $image);

        self::seo()->keywords([
            $typeLabel,
            'remodeling project',
            $project->location ?? 'Chicago',
            'before and after',
            'home renovation',
        ]);
    }

    /**
     * Set SEO tags for a service page.
     */
    public static function service(string $serviceType): void
    {
        
        $reviewNum = self::getReviewCountNumeric();
        $reviewBadge = $reviewNum ? "{$reviewNum}★ Reviews" : 'Top-Rated';

        $services = [
            'kitchen-remodeling' => [
                'label' => 'Kitchen Remodeling',
                'title' => "Chicago Suburbs Kitchen Remodeling — {$reviewBadge} & 40 Yrs",
                'description' => 'Custom kitchen remodeling in Chicago\'s NW suburbs: cabinets, quartz & granite countertops, IKEA installs, full gut renovations. %s 5-star reviews, licensed & insured. Free in-home estimate — (224) 735-4200.',
                'keywords' => ['kitchen remodel', 'kitchen renovation', 'kitchen cabinets', 'kitchen countertops', 'kitchen remodeling near me', 'kitchen contractors chicago', 'kitchen remodelers'],
            ],
            'bathroom-remodeling' => [
                'label' => 'Bathroom Remodeling',
                'title' => "Chicago Suburbs Bathroom Remodeling — {$reviewBadge} & 40 Yrs",
                'description' => 'Bathroom remodeling in Chicago\'s NW suburbs: walk-in showers, tub-to-shower conversions, tile & vanities, full gut renovations. %s 5-star reviews, licensed & insured. Free in-home estimate — (224) 735-4200.',
                'keywords' => ['bathroom remodel', 'bathroom renovation', 'shower remodel', 'bathroom tile', 'bathroom remodeling near me', 'bathroom contractors chicago', 'bathroom remodelers'],
            ],
            'home-remodeling' => [
                'label' => 'Home Remodeling',
                'title' => "Chicago Suburbs Whole-Home Remodeling — {$reviewBadge} & 40 Yrs",
                'description' => 'Whole-home remodeling in Chicago\'s NW suburbs: room additions, open floor plans, kitchens, baths & basements. %s 5-star reviews, licensed & insured. Free in-home estimate — (224) 735-4200.',
                'keywords' => ['home renovation', 'whole home remodel', 'house renovation', 'interior remodeling', 'home remodeling near me', 'general contractors chicago'],
            ],
            'basement-remodeling' => [
                'label' => 'Basement Remodeling',
                'title' => "Chicago Suburbs Basement Finishing — {$reviewBadge} & 40 Yrs",
                'description' => 'Basement finishing & remodeling in Chicago\'s NW suburbs: home theaters, guest suites, rec rooms, wet bars. %s 5-star reviews, licensed & insured. Free in-home estimate — (224) 735-4200.',
                'keywords' => ['basement finishing', 'basement renovation', 'finished basement', 'basement remodel', 'basement remodeling near me'],
            ],
            'home-additions' => [
                'label' => 'Home Additions',
                'title' => "Chicago Suburbs Home Additions — {$reviewBadge} & 40 Yrs",
                'description' => 'Home additions in Chicago\'s NW suburbs: room additions, master suite additions, sunrooms, second-story expansions. %s 5-star reviews, licensed & insured. Free in-home estimate — (224) 735-4200.',
                'keywords' => ['home addition', 'room addition', 'master suite addition', 'sunroom addition', 'second story addition', 'home addition contractors', 'addition builders near me', 'general contractor additions'],
            ],
        ];

        $service = $services[$serviceType] ?? ['label' => 'Remodeling', 'title' => 'Remodeling Services', 'description' => 'Expert remodeling services.', 'keywords' => []];
        
        $title = $service['title'];
        $reviewCount = self::getReviewCountLabel();
        $description = sprintf($service['description'], $reviewCount);

        // Get a relevant cover image for this service type
        $projectType = match ($serviceType) {
            'kitchen-remodeling' => 'kitchen',
            'bathroom-remodeling' => 'bathroom',
            'home-remodeling' => 'home-remodel',
            'basement-remodeling' => 'basement',
            'home-additions' => 'addition',
            default => null,
        };
        $image = $projectType ? self::getCoverImageForType($projectType) : null;

        self::setTags($title, $description, $image);

        self::seo()->keywords($service['keywords']);
    }

    /**
     * Set SEO tags for an area-specific service page.
     * Example: /areas-served/palatine/services/bathroom-remodeling
     */
    public static function areaService(AreaServed $area, string $serviceType): void
    {
        $city = $area->city;
        
        $services = [
            'kitchen-remodeling' => [
                'label' => 'Kitchen Remodeling',
                'shortLabel' => 'Kitchen',
                'titleTemplate' => '%s Kitchen Remodeling — %s',
                'descriptionTemplate' => '%s, IL kitchen remodeling: custom cabinets, quartz & granite countertops, IKEA installs, full gut renovations. %s 5-star reviews, licensed & insured. Free in-home estimate — (224) 735-4200.',
                'keywords' => ['kitchen remodel', 'kitchen renovation', 'kitchen cabinets', 'kitchen countertops', 'kitchen contractors', 'kitchen remodelers'],
            ],
            'bathroom-remodeling' => [
                'label' => 'Bathroom Remodeling',
                'shortLabel' => 'Bathroom',
                'titleTemplate' => '%s Bathroom Remodeling — %s',
                'descriptionTemplate' => '%s, IL bathroom remodeling: walk-in showers, tub-to-shower conversions, tile & vanities, full renovations. %s 5-star reviews, licensed & insured. Free in-home estimate — (224) 735-4200.',
                'keywords' => ['bathroom remodel', 'bathroom renovation', 'shower remodel', 'bathroom tile', 'bathroom contractors', 'bathroom remodelers'],
            ],
            'home-remodeling' => [
                'label' => 'Home Remodeling',
                'shortLabel' => 'Home',
                'titleTemplate' => '%s Whole-Home Remodeling — %s',
                'descriptionTemplate' => '%s, IL whole-home remodeling: room additions, open floor plans, kitchens, baths & basements. %s 5-star reviews, licensed & insured. Free in-home estimate — (224) 735-4200.',
                'keywords' => ['home renovation', 'whole home remodel', 'house renovation', 'interior remodeling', 'general contractors', 'home remodelers'],
            ],
            'basement-remodeling' => [
                'label' => 'Basement Remodeling',
                'shortLabel' => 'Basement',
                'titleTemplate' => '%s Basement Finishing — %s',
                'descriptionTemplate' => '%s, IL basement finishing & remodeling: home theaters, guest suites, rec rooms, wet bars, egress windows. %s 5-star reviews, licensed & insured. Free in-home estimate — (224) 735-4200.',
                'keywords' => ['basement finishing', 'basement renovation', 'finished basement', 'basement remodelers', 'basement contractors'],
            ],
            'home-additions' => [
                'label' => 'Home Additions',
                'shortLabel' => 'Addition',
                'titleTemplate' => '%s Home Additions — %s',
                'descriptionTemplate' => '%s, IL home additions: room additions, master suite additions, sunrooms, second-story expansions. %s 5-star reviews, licensed & insured. Free in-home estimate — (224) 735-4200.',
                'keywords' => ['home addition', 'room addition', 'master suite addition', 'sunroom', 'second story addition', 'addition contractors', 'addition builders'],
            ],
        ];

        $reviewNum = self::getReviewCountNumeric();
        $reviewBadge = $reviewNum ? "{$reviewNum}★ Reviews" : 'Top-Rated Local';

        $service = $services[$serviceType] ?? [
            'label' => 'Remodeling',
            'shortLabel' => 'Remodeling',
            'titleTemplate' => 'Remodeling in %s, IL',
            'descriptionTemplate' => 'Expert remodeling services in %s. Local contractors with 40+ years experience.',
            'keywords' => [],
        ];
        
        // Primary keyword targeting: "{City} {Service} Remodeling — {N★ Reviews}"
        $title = sprintf($service['titleTemplate'], $city, $reviewBadge);

        // Enhanced description with review count, CTA and city targeting
        $reviewCount = self::getReviewCountLabel();
        $description = sprintf($service['descriptionTemplate'], $city, $reviewCount);

        // Get a relevant cover image for this service type
        $projectType = match ($serviceType) {
            'kitchen-remodeling' => 'kitchen',
            'bathroom-remodeling' => 'bathroom',
            'home-remodeling' => 'home-remodel',
            'basement-remodeling' => 'basement',
            'home-additions' => 'addition',
            default => null,
        };
        $image = $projectType ? self::getCoverImageForType($projectType) : null;

        self::setTags($title, $description, $image);
        
        // Add area + service specific keywords (helps with semantic relevance)
        $cityLower = strtolower($city);
        $serviceLabel = strtolower($service['label']);
        $shortLabel = strtolower($service['shortLabel']);
        
        $areaKeywords = array_merge(
            $service['keywords'],
            [
                // Primary local keywords - matches "kitchen remodeling in palatine" searches
                "{$serviceLabel} in {$cityLower}",
                "{$serviceLabel} {$cityLower}",
                "{$serviceLabel} {$cityLower} il",
                "{$shortLabel} renovation in {$cityLower}",
                "{$shortLabel} renovations {$cityLower}",
                // Contractor-focused keywords
                "{$shortLabel} contractors {$cityLower}",
                "{$shortLabel} remodelers in {$cityLower}",
                "{$cityLower} {$shortLabel} contractors",
                // "Near me" signals (helps semantic matching)
                "{$shortLabel} remodeling near me",
                "best {$shortLabel} remodelers {$cityLower}",
                // Long-tail variations
                "{$cityLower} il {$shortLabel} remodel",
                "{$shortLabel} remodeling company {$cityLower}",
            ]
        );
        self::seo()->keywords($areaKeywords);
    }

    /**
     * Get a marketing-friendly review count label (e.g., "50+").
     */
    protected static function getReviewCountLabel(): string
    {
        $count = cache()->remember('testimonial_count', 3600, fn () => \App\Models\Testimonial::count());
        $rounded = (int) floor($count / 5) * 5;
        return $rounded . '+';
    }

    /**
     * Get the raw review count (for tight title slots where "70+" is too long).
     */
    protected static function getReviewCountNumeric(): int
    {
        return (int) cache()->remember('testimonial_count', 3600, fn () => \App\Models\Testimonial::count());
    }

    /**
     * Set SEO tags for the /compare hub.
     */
    public static function compareIndex(): void
    {
        $title = 'Compare Chicago Remodeling Contractors';
        $description = 'Compare GS Construction to other Chicago-area kitchen, bathroom, and home remodeling contractors. Factual side-by-side on service area, communication, reviews and more.';

        self::setTags($title, $description, asset('images/greg-patryk.jpg'));

        self::seo()->keywords([
            'compare remodeling contractors chicago',
            'best chicago remodeling contractor',
            'remodeling contractor alternatives',
            'kitchen remodeling alternatives chicago',
        ]);
    }

    /**
     * Set SEO tags for a per-competitor comparison page.
     *
     * Uses "alternative to" framing in the title to avoid trademark issues and
     * to align with how users search ("alternative to {brand}", "{brand} vs ...").
     *
     * @param array<string, mixed> $competitor
     */
    public static function compareCompetitor(array $competitor): void
    {
        $name = (string) ($competitor['name'] ?? 'Competitor');

        $title = "Alternative to {$name} - GS Construction";
        $description = "Comparing GS Construction with {$name}? See a factual side-by-side on service area, project types, communication and reviews — and request a free Chicagoland estimate.";

        self::setTags($title, $description, asset('images/greg-patryk.jpg'));

        self::seo()->keywords([
            "alternative to {$name}",
            "{$name} vs",
            "{$name} reviews",
            'compare chicago remodeling contractors',
        ]);
    }

    /**
     * Helper to set common meta tags via the SEO builder.
     */
    protected static function setTags(string $title, string $description, ?string $image = null): void
    {
        // Canonical: strip noisy params (utm_*, gclid, fbclid, pagination) so
        // each indexable URL maps to one clean canonical regardless of source.
        $canonical = self::buildCleanCanonical();
        $ogImage = $image ?? asset('images/greg-patryk.jpg');

        self::seo()
            ->title($title)
            ->description($description)
            ->canonical($canonical)
            ->url($canonical)
            ->image($ogImage)
            ->siteName('GS Construction')
            ->locale('en_US')
            ->type('website');

        // Apply domain-specific SEO enhancements (keywords, canonical)
        self::applyDomainEnhancements();
    }

    /**
     * Build a canonical URL stripped of tracking params + pagination noise.
     */
    protected static function buildCleanCanonical(): string
    {
        $url = url()->current();
        $query = request()->query();
        if (empty($query)) {
            return $url;
        }
        $strip = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
                  'gclid', 'fbclid', 'msclkid', 'mc_cid', 'mc_eid', '_ga', 'ref', 'page'];
        foreach ($strip as $key) {
            unset($query[$key]);
        }
        return empty($query) ? $url : $url . '?' . http_build_query($query);
    }

    /**
     * Get a random cover image URL for a project type.
     */
    protected static function getCoverImageForType(string $projectType): ?string
    {
        $image = \App\Models\ProjectImage::query()
            ->where('is_cover', true)
            ->whereHas('project', fn ($q) => $q->where('is_published', true)->where('project_type', $projectType))
            ->orderBy('id')
            ->first();

        if ($image?->url) {
            return $image->url;
        }

        if (in_array($projectType, ['basement', 'addition'], true)) {
            $aiUrl = app(AiContentService::class)->chooseServiceFallbackImageUrl($projectType);
            if (is_string($aiUrl) && $aiUrl !== '') {
                return $aiUrl;
            }
        }

        return null;
    }
}
