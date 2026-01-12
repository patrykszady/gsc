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
            $title = "{$city} Remodeling Contractors";
            $description = "Kitchen, bathroom & home remodeling in {$city}, IL. Family-owned with 40+ years experience.";
        } else {
            $title = 'Remodeling Contractors Chicago';
            $description = 'Kitchen, bathroom & home remodeling in Chicagoland. Family-owned with 40+ years experience.';
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
            $title = "{$typeLabel} Remodeling Projects in {$city}";
            $description = "Browse our {$typeLabel} remodeling projects in {$city}. See the quality craftsmanship from GS Construction.";
        } elseif ($typeLabel) {
            $title = "{$typeLabel} Remodeling Projects | GS Construction";
            $description = "Browse our {$typeLabel} remodeling portfolio. See kitchen, bathroom, and home renovation projects completed by GS Construction.";
        } elseif ($city) {
            $title = "Remodeling Projects in {$city}";
            $description = "Browse our remodeling projects in {$city}. Kitchen, bathroom, and whole-home renovations by GS Construction.";
        } else {
            $title = 'Our Remodeling Projects | GS Construction';
            $description = 'Browse our portfolio of kitchen, bathroom, and home remodeling projects. See the quality craftsmanship from GS Construction.';
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
            ? "{$city} Remodeling Reviews"
            : 'Customer Reviews & Testimonials';
        
        $description = $city
            ? "Read reviews from {$city} homeowners about their remodeling experience with GS Construction. 5-star rated kitchen and bathroom renovations."
            : 'Read what our customers say about GS Construction. 5-star reviews from satisfied homeowners throughout Chicagoland.';

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
            ? "About Us | {$city} Contractors"
            : 'About Us | Meet Greg & Patryk';
        
        $description = $city
            ? "Learn about GS Construction, a family-owned remodeling company serving {$city}. Meet Greg & Patryk and discover our 40+ years of combined experience."
            : 'Learn about GS Construction, a family-owned Chicago remodeling company. Meet Greg & Patryk and discover our 40+ years of combined experience.';

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
            ? "Contact Us | Free {$city} Quote"
            : 'Contact Us | Get a Free Quote';
        
        $description = $city
            ? "Contact GS Construction for a free remodeling quote in {$city}. Call (847) 430-4439 or fill out our form for kitchen, bathroom, and home renovation estimates."
            : 'Contact GS Construction for a free remodeling quote. Call (847) 430-4439 or fill out our form for kitchen, bathroom, and home renovation estimates.';

        self::setTags($title, $description);
    }

    /**
     * Set SEO tags for areas served index page.
     */
    public static function areasServed(): void
    {
        // Keep under 52 chars (suffix adds ~18 chars)
        $title = 'Areas We Serve';
        $description = 'GS Construction serves the Chicago Northwest Suburbs including Arlington Heights, Palatine, Lake Zurich, Barrington, and more. Expert kitchen, bathroom, and home remodeling.';

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
            ? "{$city} Kitchen & Bathroom Remodeling"
            : 'Kitchen, Bathroom & Home Remodeling';
        
        $description = $city
            ? "Expert remodeling services in {$city}, IL. Kitchen renovations, bathroom remodels, and complete home renovations. Free consultations available."
            : 'Expert remodeling services in Chicago. Kitchen renovations, bathroom remodels, basement finishing, and complete home renovations. Free consultations available.';

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
                'keywords' => ['kitchen remodel', 'kitchen renovation', 'kitchen cabinets', 'kitchen countertops'],
            ],
            'bathroom-remodeling' => [
                'label' => 'Bathroom Remodeling',
                'keywords' => ['bathroom remodel', 'bathroom renovation', 'shower remodel', 'bathroom tile'],
            ],
            'home-remodeling' => [
                'label' => 'Home Remodeling',
                'keywords' => ['home renovation', 'whole home remodel', 'house renovation', 'interior remodeling'],
            ],
            'basement-remodeling' => [
                'label' => 'Basement Remodeling',
                'keywords' => ['basement finishing', 'basement renovation', 'finished basement', 'basement remodel'],
            ],
        ];

        $service = $services[$serviceType] ?? ['label' => 'Remodeling', 'keywords' => []];
        
        // Keep under 52 chars - suffix adds ~18 chars
        $title = "{$service['label']} Chicago";
        
        $description = "Expert {$service['label']} services in the Chicagoland area. GS Construction delivers quality kitchen, bathroom, and home renovations.";

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
     * Example: /areas-served/palatine/bathroom-remodeling
     */
    public static function areaService(AreaServed $area, string $serviceType): void
    {
        $city = $area->city;
        
        $services = [
            'kitchen-remodeling' => [
                'label' => 'Kitchen Remodeling',
                'shortLabel' => 'Kitchen',
                'keywords' => ['kitchen remodel', 'kitchen renovation', 'kitchen cabinets', 'kitchen countertops', 'kitchen contractors'],
            ],
            'bathroom-remodeling' => [
                'label' => 'Bathroom Remodeling',
                'shortLabel' => 'Bathroom',
                'keywords' => ['bathroom remodel', 'bathroom renovation', 'shower remodel', 'bathroom tile', 'bathroom contractors'],
            ],
            'home-remodeling' => [
                'label' => 'Home Remodeling',
                'shortLabel' => 'Home',
                'keywords' => ['home renovation', 'whole home remodel', 'house renovation', 'interior remodeling', 'general contractors'],
            ],
        ];

        $service = $services[$serviceType] ?? ['label' => 'Remodeling', 'shortLabel' => 'Remodeling', 'keywords' => []];
        
        // Primary keyword targeting: "{City} {Service}" e.g. "Palatine Bathroom Remodeling"
        // Keep under 52 chars total - for long city names, use short label
        $fullTitle = "{$city} {$service['label']}";
        $title = strlen($fullTitle) > 45 
            ? "{$city} {$service['shortLabel']} Remodel"
            : $fullTitle;
        
        $description = "Expert {$service['label']} in {$city}, IL. Family-owned with 40+ years experience. Free estimates.";

        // Get a relevant cover image for this service type
        $projectType = match ($serviceType) {
            'kitchen-remodeling' => 'kitchen',
            'bathroom-remodeling' => 'bathroom',
            'home-remodeling' => 'home-remodel',
            default => null,
        };
        $image = $projectType ? self::getCoverImageForType($projectType) : null;

        self::setTags($title, $description, $image);
        
        // Add area + service specific keywords
        $cityLower = strtolower($city);
        $areaKeywords = array_merge(
            $service['keywords'],
            [
                "{$cityLower} {$service['shortLabel']} remodeling",
                "{$cityLower} {$service['shortLabel']} contractors",
                "{$cityLower} remodeling company",
                "{$city} IL contractors",
                "{$service['shortLabel']} remodel {$cityLower}",
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
