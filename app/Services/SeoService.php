<?php

namespace App\Services;

use App\Models\AreaServed;
use App\Models\Project;
use App\Models\Testimonial;
use Artesaos\SEOTools\Facades\OpenGraph;
use Artesaos\SEOTools\Facades\SEOMeta;
use Artesaos\SEOTools\Facades\TwitterCard;
use Illuminate\Support\Str;

class SeoService
{
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
            SEOMeta::addKeyword($domainConfig['keywords']);
        }
        
        // Set canonical to primary domain (critical for SEO)
        // This tells search engines the primary domain is authoritative
        if ($isAlternateDomain) {
            $canonicalUrl = 'https://' . $primaryDomain . request()->getRequestUri();
            SEOMeta::setCanonical($canonicalUrl);
            OpenGraph::setUrl($canonicalUrl);
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
            // Keep under 52 chars - suffix adds ~18 chars
            $title = "Kitchen & Bathroom Remodeling in {$city}";
            $description = "Top-rated kitchen remodeling & bathroom renovations in {$city}, IL. Family-owned contractors with 40+ years experience. Free estimates available!";
        } else {
            $title = 'Kitchen & Bathroom Remodeling Contractors';
            $description = 'Expert kitchen remodeling & bathroom renovations in Chicagoland. Family-owned contractors with 40+ years experience. Arlington Heights, Palatine & more.';
        }

        self::setTags($title, $description);
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
            $description = "See our {$typeLabel} remodeling projects in {$city}, IL. Before & after photos showcasing quality kitchen & bathroom renovations by local contractors.";
        } elseif ($typeLabel) {
            $title = "{$typeLabel} Remodeling Portfolio";
            $description = "Browse our {$typeLabel} remodeling before & after photos. See real kitchen, bathroom & home renovation projects in the Chicago suburbs.";
        } elseif ($city) {
            $title = "Remodeling Projects in {$city}, IL";
            $description = "View kitchen, bathroom & home remodeling projects in {$city}. Real before & after photos from your neighbors. Get inspired for your renovation!";
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
        
        $description = $city
            ? "5-star reviews from {$city} homeowners. See what your neighbors say about their kitchen remodeling & bathroom renovation experience with us."
            : '5-star rated kitchen & bathroom remodeling in Chicago suburbs. Read reviews from Arlington Heights, Palatine, Buffalo Grove & more homeowners.';

        self::setTags($title, $description);
    }

    /**
     * Set SEO tags for individual testimonial page.
     */
    public static function testimonial(Testimonial $testimonial): void
    {
        $name = $testimonial->reviewer_name;
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

        self::setTags($title, $description);
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
        
        $description = $city
            ? "Meet GS Construction - family-owned kitchen & bathroom remodeling contractors serving {$city}, IL. 40+ years combined experience. Licensed & insured."
            : 'Meet Greg & Patryk - family-owned kitchen & bathroom remodeling contractors in Chicago. 40+ years combined experience. Licensed & insured.';

        self::setTags($title, $description);
        
        OpenGraph::addImage(asset('images/greg-patryk.jpg'));
        TwitterCard::setImage(asset('images/greg-patryk.jpg'));
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
            ? "Request a free kitchen or bathroom remodeling estimate in {$city}, IL. Call (847) 430-4439 or schedule online. Same-week consultations available!"
            : 'Get a free kitchen or bathroom remodeling estimate in Chicago suburbs. Call (847) 430-4439 or schedule online. Same-week consultations available!';

        self::setTags($title, $description);
    }

    /**
     * Set SEO tags for areas served index page.
     */
    public static function areasServed(): void
    {
        // Keep under 52 chars (suffix adds ~18 chars)
        $title = 'Service Areas - Chicago Suburbs';
        $description = 'Kitchen & bathroom remodeling in Arlington Heights, Palatine, Buffalo Grove, Barrington, Lake Zurich & 50+ Chicago suburbs. Local contractors, free estimates.';

        self::setTags($title, $description);
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
            ? "Remodeling Services in {$city}, IL"
            : 'Kitchen, Bathroom & Home Remodeling Services';
        
        $description = $city
            ? "Kitchen remodeling, bathroom renovations & home remodeling in {$city}, IL. Top-rated local contractors. Free in-home estimates. Call today!"
            : 'Kitchen remodeling, bathroom renovations & home remodeling in Chicago suburbs. Top-rated local contractors serving Palatine, Arlington Heights & more.';

        // Get a kitchen image as the default for services overview
        $image = self::getCoverImageForType('kitchen');

        self::setTags($title, $description, $image);
        
        SEOMeta::addKeyword([
            'remodeling services',
            'kitchen remodeling',
            'bathroom remodeling',
            'home renovation',
            'basement finishing',
            'Chicago contractors',
            'home improvement'
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
        
        SEOMeta::addKeyword([
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
        
        $services = [
            'kitchen-remodeling' => [
                'label' => 'Kitchen Remodeling',
                'title' => 'Kitchen Remodeling Contractors Near Chicago',
                'description' => 'Expert kitchen remodeling in Chicago suburbs. Custom cabinets, granite countertops & complete kitchen renovations. Family-owned, 40+ years experience. Free estimates!',
                'keywords' => ['kitchen remodel', 'kitchen renovation', 'kitchen cabinets', 'kitchen countertops', 'kitchen remodeling near me', 'kitchen contractors chicago'],
            ],
            'bathroom-remodeling' => [
                'label' => 'Bathroom Remodeling',
                'title' => 'Bathroom Remodeling Contractors Near Chicago',
                'description' => 'Expert bathroom remodeling in Chicago suburbs. Walk-in showers, tub-to-shower conversions & complete bathroom renovations. Family-owned, 40+ years experience. Free estimates!',
                'keywords' => ['bathroom remodel', 'bathroom renovation', 'shower remodel', 'bathroom tile', 'bathroom remodeling near me', 'bathroom contractors chicago'],
            ],
            'home-remodeling' => [
                'label' => 'Home Remodeling',
                'title' => 'Home Remodeling Contractors Near Chicago',
                'description' => 'Complete home remodeling in Chicago suburbs. Room additions, open floor plans & whole-home renovations. Family-owned, 40+ years experience. Free estimates!',
                'keywords' => ['home renovation', 'whole home remodel', 'house renovation', 'interior remodeling', 'home remodeling near me', 'general contractors chicago'],
            ],
            'basement-remodeling' => [
                'label' => 'Basement Remodeling',
                'title' => 'Basement Finishing Contractors Near Chicago',
                'description' => 'Expert basement finishing & remodeling in Chicago suburbs. Home theaters, guest suites & recreation rooms. Family-owned, 40+ years experience. Free estimates!',
                'keywords' => ['basement finishing', 'basement renovation', 'finished basement', 'basement remodel', 'basement remodeling near me'],
            ],
        ];

        $service = $services[$serviceType] ?? ['label' => 'Remodeling', 'title' => 'Remodeling Services', 'description' => 'Expert remodeling services.', 'keywords' => []];
        
        $title = $service['title'];
        $description = $service['description'];

        // Get a relevant cover image for this service type
        $projectType = match ($serviceType) {
            'kitchen-remodeling' => 'kitchen',
            'bathroom-remodeling' => 'bathroom',
            'home-remodeling' => 'home-remodel',
            'basement-remodeling' => 'basement',
            default => null,
        };
        $image = $projectType ? self::getCoverImageForType($projectType) : null;

        self::setTags($title, $description, $image);
        
        // Add service-specific keywords
        $keywords = $service['keywords'];
        SEOMeta::addKeyword($keywords);
    }

    /**
     * Set SEO tags for an area-specific service page.
     * Example: /areas-served/palatine/services/bathrooms
     */
    public static function areaService(AreaServed $area, string $serviceType): void
    {
        $city = $area->city;
        
        $services = [
            'kitchen-remodeling' => [
                'label' => 'Kitchen Remodeling',
                'shortLabel' => 'Kitchen',
                'titleTemplate' => 'Kitchen Remodeling in %s, IL',
                'descriptionTemplate' => 'Looking for kitchen remodeling in %s? Custom cabinets, countertops & complete kitchen renovations. Local contractors, 40+ years experience. Free estimate!',
                'keywords' => ['kitchen remodel', 'kitchen renovation', 'kitchen cabinets', 'kitchen countertops', 'kitchen contractors'],
            ],
            'bathroom-remodeling' => [
                'label' => 'Bathroom Remodeling',
                'shortLabel' => 'Bathroom',
                'titleTemplate' => 'Bathroom Remodeling in %s, IL',
                'descriptionTemplate' => 'Looking for bathroom remodeling in %s? Walk-in showers, tub conversions & complete bathroom renovations. Local contractors, 40+ years experience. Free estimate!',
                'keywords' => ['bathroom remodel', 'bathroom renovation', 'shower remodel', 'bathroom tile', 'bathroom contractors'],
            ],
            'home-remodeling' => [
                'label' => 'Home Remodeling',
                'shortLabel' => 'Home',
                'titleTemplate' => 'Home Remodeling in %s, IL',
                'descriptionTemplate' => 'Looking for home remodeling in %s? Room additions, open floor plans & complete home renovations. Local contractors, 40+ years experience. Free estimate!',
                'keywords' => ['home renovation', 'whole home remodel', 'house renovation', 'interior remodeling', 'general contractors'],
            ],
        ];

        $service = $services[$serviceType] ?? [
            'label' => 'Remodeling',
            'shortLabel' => 'Remodeling',
            'titleTemplate' => 'Remodeling in %s, IL',
            'descriptionTemplate' => 'Expert remodeling services in %s. Local contractors with 40+ years experience.',
            'keywords' => [],
        ];
        
        // Primary keyword targeting: "{Service} in {City}" e.g. "Kitchen Remodeling in Palatine, IL"
        $title = sprintf($service['titleTemplate'], $city);
        
        // Enhanced description with city-specific targeting
        $description = sprintf($service['descriptionTemplate'], $city);

        // Get a relevant cover image for this service type
        $projectType = match ($serviceType) {
            'kitchen-remodeling' => 'kitchen',
            'bathroom-remodeling' => 'bathroom',
            'home-remodeling' => 'home-remodel',
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
        SEOMeta::addKeyword($areaKeywords);
    }

    /**
     * Helper to set common meta tags.
     */
    protected static function setTags(string $title, string $description, ?string $image = null): void
    {
        SEOMeta::setTitle($title);
        SEOMeta::setDescription($description);
        
        OpenGraph::setTitle($title);
        OpenGraph::setDescription($description);
        OpenGraph::setUrl(url()->current());
        OpenGraph::addProperty('locale', 'en_US');
        
        // Set OG image for social sharing (iMessage, Facebook, etc.)
        $ogImage = $image ?? asset('images/greg-patryk.jpg');
        OpenGraph::addImage($ogImage, ['width' => 1200, 'height' => 630]);
        
        TwitterCard::setTitle($title);
        TwitterCard::setDescription($description);
        TwitterCard::setImage($ogImage);
        
        // Apply domain-specific SEO enhancements (keywords, canonical)
        self::applyDomainEnhancements();
    }

    /**
     * Get a random cover image URL for a project type.
     */
    protected static function getCoverImageForType(string $projectType): ?string
    {
        $image = \App\Models\ProjectImage::query()
            ->where('is_cover', true)
            ->whereHas('project', fn ($q) => $q->where('is_published', true)->where('project_type', $projectType))
            ->inRandomOrder()
            ->first();

        return $image?->url;
    }
}
