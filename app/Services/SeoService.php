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
            $meta = self::buildAreaHomeMeta($area);
            $title = $meta['title'];
            $description = $meta['description'];
        } else {
            // Lead with brand on the homepage so Google adopts "GS Construction"
            // as the site name (https://developers.google.com/search/docs/appearance/site-names)
            $title = 'GS Construction — Kitchen & Bathroom Remodeling, Chicagoland';
            $description = 'Family-owned kitchen, bathroom & whole-home remodeling in Chicago\'s suburbs. 40+ yrs combined experience, 5-star rated. Free estimates.';
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
            : 'Get a Free Chicagoland Remodeling Estimate';
        
        $description = $city
            ? "Request a free kitchen or bathroom remodeling estimate in {$city}, IL. Call (224) 735-4200 or schedule online. Same-week consultations available!"
            : 'Get a free kitchen or bathroom remodeling estimate in Chicago suburbs. Call (224) 735-4200 or schedule online. Same-week consultations available!';

        // Use the team photo for contact page — builds trust
        self::setTags($title, $description, asset('images/greg-patryk.jpg'));
    }

    /**
     * Set SEO tags for the careers / partnerships (jobs) page.
     */
    public static function jobs(): void
    {
        // Title 30–60 chars; description 70–160 chars (avoid '&' so the
        // HTML-encoded '&amp;' does not inflate the rendered length).
        $title = 'Careers & Trade Partnerships in Chicagoland';
        $description = 'Join GS Construction or partner with us. We hire bilingual tradesmen and work with subcontractors, designers, architects and suppliers across Chicagoland.';

        self::setTags($title, $description, asset('images/greg-patryk.jpg'));

        self::seo()->keywords([
            'construction jobs Chicago suburbs',
            'remodeling careers',
            'bilingual tradesmen jobs',
            'subcontractor opportunities',
            'trade partners',
            'countertop supplier partnership',
            'cabinet supplier partnership',
            'interior designer collaboration',
            'architect collaboration',
        ]);
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
        
        // Keep the rendered <title> within 30–60 chars even for the longest
        // city names ("Arlington Heights" = 17) and the description within
        // 70–160 chars. Use "and" instead of "&" so the HTML-encoded "&amp;"
        // does not push the measured length over budget.
        $title = $city
            ? "{$city} Remodeling Services — Kitchen & Bath"
            : 'Kitchen, Bathroom & Home Remodeling Services';
        
        $reviewCount = self::getReviewCountLabel();
        $description = $city
            ? "Kitchen, bath and whole-home remodeling in {$city}, IL. {$reviewCount} 5-star reviews, licensed and insured. Free in-home estimate — call (224) 735-4200."
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
        // "72★" reads as a 72-star rating in SERPs — spell out "Reviews" instead.
        $reviewBadge = $reviewNum ? "{$reviewNum} Reviews" : 'Top-Rated';
        // Short badge for titles that also carry the experience hook (keeps <= 60 chars).
        $reviewShort = $reviewNum ? "{$reviewNum} Reviews" : 'Top-Rated';

        $services = [
            'kitchen-remodeling' => [
                'label' => 'Kitchen Remodeling',
                'title' => "Chicago Suburbs Kitchen Remodeling — {$reviewShort} · 40+ Yrs",
                'description' => 'Custom kitchen remodeling in Chicago\'s NW suburbs: cabinets, quartz & granite countertops, IKEA installs, full gut renovations. %s 5-star reviews, licensed & insured. Free in-home estimate — (224) 735-4200.',
                'keywords' => ['kitchen remodel', 'kitchen renovation', 'kitchen cabinets', 'kitchen countertops', 'kitchen remodeling near me', 'kitchen contractors chicago', 'kitchen remodelers'],
            ],
            'bathroom-remodeling' => [
                'label' => 'Bathroom Remodeling',
                'title' => "Chicago Suburbs Bathroom Remodeling — {$reviewShort} · 40+ Yrs",
                'description' => 'Bathroom remodeling in Chicago\'s NW suburbs: walk-in showers, tub-to-shower conversions, tile & vanities, full gut renovations. %s 5-star reviews, licensed & insured. Free in-home estimate — (224) 735-4200.',
                'keywords' => ['bathroom remodel', 'bathroom renovation', 'shower remodel', 'bathroom tile', 'bathroom remodeling near me', 'bathroom contractors chicago', 'bathroom remodelers'],
            ],
            'home-remodeling' => [
                'label' => 'Home Remodeling',
                'title' => "Chicago Suburbs Whole-Home Remodeling — {$reviewShort} · 40+ Yrs",
                'description' => 'Whole-home remodeling in Chicago\'s NW suburbs: room additions, open floor plans, kitchens, baths & basements. %s 5-star reviews, licensed & insured. Free in-home estimate — (224) 735-4200.',
                'keywords' => ['home renovation', 'whole home remodel', 'house renovation', 'interior remodeling', 'home remodeling near me', 'general contractors chicago'],
            ],
            'basement-remodeling' => [
                'label' => 'Basement Remodeling',
                'title' => "Chicago Suburbs Basement Finishing — {$reviewShort} · 40+ Yrs",
                'description' => 'Basement finishing & remodeling in Chicago\'s NW suburbs: home theaters, guest suites, rec rooms, wet bars. %s 5-star reviews, licensed & insured. Free in-home estimate — (224) 735-4200.',
                'keywords' => ['basement finishing', 'basement renovation', 'finished basement', 'basement remodel', 'basement remodeling near me'],
            ],
            'home-additions' => [
                'label' => 'Home Additions',
                'title' => "Chicago Suburbs Home Additions — {$reviewShort} · 40+ Yrs",
                'description' => 'Home additions in Chicago\'s NW suburbs: room additions, master suite additions, sunrooms, second-story expansions. %s 5-star reviews, licensed & insured. Free in-home estimate — (224) 735-4200.',
                'keywords' => ['home addition', 'room addition', 'master suite addition', 'sunroom addition', 'second story addition', 'home addition contractors', 'addition builders near me', 'general contractor additions'],
            ],
            'mudroom-remodeling' => [
                'label' => 'Mudroom & Laundry Remodeling',
                'title' => "Chicago Suburbs Mudroom & Laundry Remodeling — {$reviewBadge}",
                'description' => 'Custom mudroom & laundry room remodeling in Chicago\'s NW suburbs: built-in lockers, benches, cubbies, drop zones & laundry combos. %s 5-star reviews, licensed & insured. Free in-home estimate — (224) 735-4200.',
                'keywords' => ['mudroom remodel', 'mudroom builders', 'custom mudroom', 'laundry room remodel', 'mudroom lockers', 'laundry mudroom combo', 'mudroom remodeling near me'],
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
            'mudroom-remodeling' => 'mudroom',
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
        $meta = self::buildAreaServiceMeta($area, $serviceType);
        $service = $meta['service'];
        $title = $meta['title'];
        $description = $meta['description'];
        $city = $area->city;

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
     * Build deterministic title/description for area home pages.
     *
     * @return array{title:string, description:string}
     */
    public static function buildAreaHomeMeta(AreaServed $area): array
    {
        $city = $area->city;
        $reviewCount = self::getReviewCountLabel();
        $seed = abs(crc32((string) ($area->slug ?: $city)));
        $variant = $seed % 8;
        $geoSnippet = self::buildAreaGeoSnippet($area, false);
        $context = self::buildAreaTitleContext($area, $seed);

        $intentPhrases = [
            'Kitchen, Bath & Whole-Home',
            'Design-Build Remodeling Team',
            'Kitchen, Bathroom & Basement',
            'Remodeling with Permit-Ready Planning',
            'Scope, Schedule & Build Coordination',
            'From Planning Through Final Walkthrough',
            'Family-Led Remodeling Project Delivery',
            'Kitchens, Baths, Basements & Additions',
            'Licensed Remodeling for Older Homes',
            'Practical Renovation Planning & Build',
            'Full-Service Home Renovation Crew',
            'Kitchen, Bath & Basement Specialists',
            'Whole-Home Upgrades Done Right',
            'Remodeling Built Around Your Routine',
        ];
        $trustPhrases = [
            "{$reviewCount} 5-Star Reviews",
            'Licensed & Insured',
            'Local Family Team',
            'Transparent Scope & Timeline',
            'Detailed Estimate Planning',
            '40+ Years Combined Experience',
            'Consistent Project Communication',
            'Clear Proposal and Scope Review',
            'On-Time, On-Budget Delivery',
            'No-Pressure Free Estimates',
            'One Dedicated Project Lead',
            'Trusted by Local Homeowners',
        ];

        $intent = $intentPhrases[($seed >> 3) % count($intentPhrases)];
        $trust = $trustPhrases[($seed >> 5) % count($trustPhrases)];

        $baseVariants = [
            "Home Remodeling in {$city}, IL",
            "{$city}, IL Home Remodeling",
            "Kitchen & Bathroom Remodeling in {$city}",
            "{$city} Remodeling Contractor",
            "{$city} Home Renovation Company",
            "Remodeling Services in {$city}, IL",
            "{$city} Kitchen, Bath & Basement Remodeling",
            "Top-Rated Remodeler in {$city}, IL",
        ];
        $modifierPool = array_values(array_unique(array_merge(
            [$context, $intent, $trust],
            $intentPhrases,
            $trustPhrases,
            self::compactTitleModifiers(),
        )));
        $title = self::composeTitleWithinBudget($baseVariants[$variant], $modifierPool, $seed);

        $descriptionVariants = [
            "Top-rated home remodeling in {$city}, IL for kitchens, bathrooms, basements and additions. {$reviewCount} 5-star reviews. Licensed and insured. Free in-home estimate.",
            "Looking for a {$city}, IL remodeler? We handle kitchen, bathroom and whole-home renovations with clear pricing, timelines and one dedicated project lead.",
            "{$city} homeowners choose us for kitchen, bath and full-home remodeling. {$reviewCount} 5-star reviews, permit-aware planning and free in-home consultations.",
            "Kitchen, bathroom and basement remodeling in {$city}, IL with transparent scope, clean job sites and reliable schedules. Free estimate: (224) 735-4200.",
            "Need remodeling in {$city}, IL? Our family-led team delivers design-build kitchen, bath and home renovation with weekly updates and no-pressure quotes.",
            "{$city}, IL remodeling contractor for kitchens, bathrooms, basements and additions. {$reviewCount} 5-star reviews and free in-home estimate appointments.",
            "From quick kitchen updates to full-home renovations, {$city}, IL homeowners trust our licensed, insured remodeling team for quality craftsmanship.",
            "Plan your {$city}, IL renovation with a local team focused on scope clarity, schedule confidence and beautiful kitchen, bath and basement results.",
        ];

        $description = $descriptionVariants[$seed % count($descriptionVariants)];
        if ($geoSnippet !== '') {
            $description .= ' ' . $geoSnippet;
        }

        return [
            'title' => $title,
            'description' => \Illuminate\Support\Str::limit($description, 160, ''),
        ];
    }

    /**
     * Build deterministic title/description + service config for area service pages.
     *
     * @return array{title:string, description:string, service:array{label:string, shortLabel:string, titleTemplate:string, descriptionTemplate:string, keywords:array<int,string>}}
     */
    public static function buildAreaServiceMeta(AreaServed $area, string $serviceType): array
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

        $service = $services[$serviceType] ?? [
            'label' => 'Remodeling',
            'shortLabel' => 'Remodeling',
            'titleTemplate' => 'Remodeling in %s, IL',
            'descriptionTemplate' => 'Expert remodeling services in %s. Local contractors with 40+ years experience.',
            'keywords' => [],
        ];

        $seed = abs(crc32($area->slug . '|' . $serviceType));
        $reviewNum = self::getReviewCountNumeric();
        $reviewBadge = $reviewNum ? "{$reviewNum}★ Reviews" : 'Top-Rated Local';
        $reviewCount = self::getReviewCountLabel();
        $geoSnippet = self::buildAreaGeoSnippet($area, true);
        $context = self::buildAreaTitleContext($area, $seed);
        $trustPhrases = [
            $reviewBadge,
            'Licensed & Insured',
            'Family-Led Project Team',
            'Detailed Scope & Timeline',
            'Permit-Aware Planning',
            'Transparent Proposal Process',
            'Clear Weekly Communication',
            'Local Design-Build Crew',
        ];
        $serviceIntents = [
            'kitchen-remodeling' => [
                'Custom Layout & Cabinet Planning',
                'Cabinets, Counters & Lighting Upgrades',
                'Functional Storage and Workflow Design',
                'Design-Led Kitchen Renovation',
                'Open-Concept Kitchen Updates',
                'Permit-Ready Kitchen Construction',
            ],
            'bathroom-remodeling' => [
                'Shower, Tile & Vanity Renovation',
                'Layout, Waterproofing & Fixture Planning',
                'Accessible Bathroom Upgrade Options',
                'Design-Led Bathroom Renovation',
                'Tub-to-Shower Conversion Planning',
                'Permit-Ready Bathroom Construction',
            ],
            'home-remodeling' => [
                'Whole-Home Planning and Phased Delivery',
                'Open Layout, Kitchen and Bath Integration',
                'Older-Home Renovation Expertise',
                'End-to-End Design and Build Coordination',
                'Permit-Aware Whole-Home Upgrades',
                'Renovation Planning with Clear Milestones',
            ],
            'basement-remodeling' => [
                'Layout, Egress and Utility Coordination',
                'Basement Finishing with Code-Aware Planning',
                'Rec Room, Guest Suite and Wet Bar Builds',
                'Storage and Living Space Optimization',
                'Moisture-Aware Basement Renovation Planning',
                'Permit-Ready Basement Buildout',
            ],
            'home-additions' => [
                'Room Additions with Structural Planning',
                'Master Suite and Expansion Buildouts',
                'Second-Story and Rear Addition Planning',
                'Permit-Ready Addition Construction',
                'Layout, Structural and Utility Coordination',
                'Space Expansion with Design-Build Delivery',
            ],
        ];

        $serviceIntents['kitchen-remodeling'] = array_merge($serviceIntents['kitchen-remodeling'], [
            'Two-Tone Cabinet and Island Builds',
            'Pantry, Lighting and Appliance Planning',
            'Quartz and Granite Countertop Upgrades',
        ]);
        $serviceIntents['bathroom-remodeling'] = array_merge($serviceIntents['bathroom-remodeling'], [
            'Curbless Shower and Niche Detailing',
            'Double-Vanity and Storage Planning',
            'Heated Floor and Lighting Upgrades',
        ]);
        $serviceIntents['home-remodeling'] = array_merge($serviceIntents['home-remodeling'], [
            'Load-Bearing Wall and Layout Changes',
            'Multi-Room Renovation Sequencing',
            'Whole-Home Finish and Fixture Planning',
        ]);
        $serviceIntents['basement-remodeling'] = array_merge($serviceIntents['basement-remodeling'], [
            'Home Theater and Wet Bar Builds',
            'Egress Window and Code Compliance',
            'Guest Suite and Bathroom Additions',
        ]);
        $serviceIntents['home-additions'] = array_merge($serviceIntents['home-additions'], [
            'Foundation, Framing and Roof Tie-Ins',
            'Sunroom and Four-Season Room Builds',
            'Bump-Out and Garage Conversion Planning',
        ]);

        $intentPool = $serviceIntents[$serviceType] ?? ['Local Remodeling Planning and Build'];
        $intent = $intentPool[($seed >> 3) % count($intentPool)];
        $trust = $trustPhrases[($seed >> 5) % count($trustPhrases)];
        $shortLabel = $service['shortLabel'];
        $label = $service['label'];

        $baseVariants = [
            "{$label} in {$city}, IL",
            "{$city}, IL {$label}",
            "{$city} {$shortLabel} Remodeler",
            "{$shortLabel} Renovation in {$city}",
            "{$city} {$label} Contractor",
            "Top-Rated {$shortLabel} Remodeling in {$city}",
        ];
        $modifierPool = array_values(array_unique(array_merge(
            [$context, $intent, $trust],
            $intentPool,
            $trustPhrases,
            self::compactTitleModifiers(),
        )));
        $title = self::composeTitleWithinBudget($baseVariants[$seed % count($baseVariants)], $modifierPool, $seed >> 3);

        $descriptionOpeners = [
            'kitchen-remodeling' => [
                "Kitchen remodeling in %s, IL with custom cabinets, quartz counters, islands and full layout redesigns.",
                "Need a %s, IL kitchen remodeler? We plan cabinets, counters, lighting and workflow for real everyday use.",
                "%s, IL kitchen renovations from refresh projects to full open-concept rebuilds with design-build coordination.",
            ],
            'bathroom-remodeling' => [
                "Bathroom remodeling in %s, IL with walk-in showers, tile, vanities and full renovation options.",
                "Planning a %s, IL bathroom renovation? We handle waterproofing, fixtures, layout and finish details end to end.",
                "%s, IL bathroom remodels from quick refreshes to full gut projects with shower and storage upgrades.",
            ],
            'home-remodeling' => [
                "Whole-home remodeling in %s, IL including layout changes, kitchens, baths, basements and additions.",
                "Renovating a %s, IL home? We sequence multi-room projects with one dedicated project lead and clear milestones.",
                "%s, IL full-home renovations planned for budget, timeline and cohesive design across every room.",
            ],
            'basement-remodeling' => [
                "Basement remodeling in %s, IL for rec rooms, guest suites, bathrooms, wet bars and egress-ready layouts.",
                "Finishing a %s, IL basement? We coordinate moisture control, utilities and code-aware layout planning.",
                "%s, IL basement renovations that convert unused space into durable, comfortable living areas.",
            ],
            'home-additions' => [
                "Home additions in %s, IL including room additions, suites, sunrooms and second-story expansions.",
                "Adding space to your %s, IL home? We handle structural, framing, utility and permit planning from day one.",
                "%s, IL additions from bump-outs to full expansions, designed and built with a single project team.",
            ],
        ];
        $openerPool = $descriptionOpeners[$serviceType] ?? [$service['descriptionTemplate']];
        $opener = sprintf($openerPool[($seed >> 7) % count($openerPool)], $city);
        // Price hints mirror config/remodel-costs.php — searchers click numbers,
        // so keep them early enough to survive the 160-char snippet cut.
        $costHints = [
            'kitchen-remodeling' => 'Most kitchens run $35k–$80k+.',
            'bathroom-remodeling' => 'Most baths run $15k–$60k+.',
            'basement-remodeling' => 'Most build-outs run $45k–$90k.',
            'home-additions' => 'Additions run $60k–$350k+.',
        ];
        $costHint = $costHints[$serviceType] ?? '';
        $closer = ($costHint !== '' ? " {$costHint}" : '')
            . " {$reviewCount} 5-star reviews. Licensed and insured. Free in-home estimate: (224) 735-4200.";
        $description = $opener . $closer;
        if ($geoSnippet !== '') {
            $description .= ' ' . $geoSnippet;
        }
        // Trim to the last full sentence within 160 chars — a snippet that ends
        // mid-phrase reads as broken in the SERP.
        if (mb_strlen($description) > 160) {
            $cut = mb_substr($description, 0, 160);
            $lastStop = mb_strrpos($cut, '.');
            $description = ($lastStop !== false && $lastStop > 80) ? mb_substr($cut, 0, $lastStop + 1) : $cut;
        }

        return [
            'title' => $title,
            'description' => $description,
            'service' => $service,
        ];
    }

    protected static function buildAreaGeoSnippet(AreaServed $area, bool $includeZip): string
    {
        $parts = [];

        if (filled($area->landmarks)) {
            $parts[] = 'Local coverage includes ' . \Illuminate\Support\Str::limit((string) $area->landmarks, 90);
        }

        if ($includeZip) {
            $zips = array_slice($area->postalCodes(), 0, 3);
            if (! empty($zips)) {
                $parts[] = 'Common ZIP coverage: ' . implode(', ', $zips) . '.';
            }
        }

        // Fallback: guarantee every snippet carries a unique local token by
        // naming the nearest served city when no landmarks/ZIPs are available.
        if (empty($parts)) {
            $nearby = $area->nearestCities(2)
                ->pluck('city')
                ->filter()
                ->take(2)
                ->implode(' and ');
            if ($nearby !== '') {
                $parts[] = "Also serving nearby {$nearby}.";
            }
        }

        return trim(implode(' ', $parts));
    }

    protected static function composeTitleWithinBudget(string $base, array $modifiers, int $seed, int $maxLen = 62): string
    {
        $base = trim($base);
        $modifiers = array_values(array_unique(array_filter(array_map('trim', $modifiers), fn ($m) => $m !== '')));

        // Keep only modifiers that fit the SERP display budget, then choose
        // deterministically across the full fitting set so uniqueness is spread
        // rather than clustering on the first short phrase that fits.
        $fitting = array_values(array_filter(
            $modifiers,
            fn ($m) => mb_strlen($base . ' | ' . $m) <= $maxLen
        ));

        if (empty($fitting)) {
            return $base;
        }

        return $base . ' | ' . $fitting[$seed % count($fitting)];
    }

    /**
     * Short, broadly-applicable title modifiers (<= ~20 chars) that fit even
     * long city + service bases, widening the deterministic choice set so
     * titles stay unique within the SERP length budget.
     *
     * @return array<int, string>
     */
    protected static function compactTitleModifiers(): array
    {
        return [
            'Licensed & Insured',
            'Family-Owned',
            '5-Star Rated',
            'Free Estimates',
            'Local Pros',
            'Insured Crew',
            'Warranty-Backed',
            'Fixed-Price Quotes',
            'Clean Job Sites',
            'On-Schedule Builds',
            'No-Pressure Quotes',
            '40+ Years Experience',
            'Trusted Local Team',
            'Free In-Home Quote',
        ];
    }

    protected static function buildAreaTitleContext(AreaServed $area, int $seed): string
    {
        $nearbyCity = $area->nearestCities(1)->first()?->city;
        if (is_string($nearbyCity) && $nearbyCity !== '') {
            return "Near {$nearbyCity}";
        }

        $landmark = self::buildLandmarkTitleToken((string) ($area->landmarks ?? ''));
        if ($landmark !== '') {
            return "Near {$landmark}";
        }

        $permitTokens = [
            'Permit-Aware Planning',
            'Code-Conscious Renovation',
            'Planning Through Final Walkthrough',
            'Detailed Scope and Milestone Planning',
            'Design-Build Coordination',
            'Schedule-Driven Project Delivery',
            'Transparent Project Planning',
            'Local Remodel Process Expertise',
        ];

        return $permitTokens[$seed % count($permitTokens)];
    }

    protected static function buildLandmarkTitleToken(string $landmarks): string
    {
        if ($landmarks === '') {
            return '';
        }

        $candidate = trim((string) preg_split('/[.;,|]/', $landmarks)[0]);
        if ($candidate === '') {
            return '';
        }

        $candidate = Str::of($candidate)
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->title()
            ->toString();

        return Str::limit($candidate, 28, '');
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
     * Set SEO tags for the trade-partners hub page (/trades).
     */
    public static function tradesIndex(): void
    {
        $title = 'Our Trade Partners | Licensed, Vetted Remodeling Trades';
        $description = 'Meet the skilled trades behind every GS Construction remodel — licensed electricians, plumbers, finish carpenters, tile setters and more. One contract, one project lead, vetted crews.';

        self::setTags($title, $description, asset('images/greg-patryk.jpg'));

        self::seo()->keywords([
            'remodeling trade partners chicago suburbs',
            'licensed electrician kitchen remodel',
            'licensed plumber bathroom remodel',
            'general contractor subcontractors vetted',
        ]);
    }

    /**
     * Set SEO tags for a single trade-partner page (/trades/{slug}).
     *
     * @param array<string,mixed> $trade One entry from config('trades.trades')
     */
    public static function trade(array $trade): void
    {
        $name = (string) ($trade['name'] ?? 'Trade Partners');

        $title = "{$name} for Home Remodels | GS Construction";
        $description = (string) ($trade['summary'] ?? '');
        if ($description === '') {
            $description = "How GS Construction works with {$name} on kitchen, bathroom, and whole-home remodels across the Chicago suburbs — vetted, insured, and supervised by one project lead.";
        } else {
            $description = "{$name} on GS Construction remodels: {$description}";
        }

        self::setTags($title, $description, asset('images/greg-patryk.jpg'));

        self::seo()->keywords(array_filter([
            strtolower($name) . ' remodeling chicago suburbs',
            strtolower((string) ($trade['short'] ?? '')) . ' contractor north shore',
        ]));
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

        // Format keeps the title within 30–60 chars for every competitor name
        // (brand is carried via siteName); "Chicagoland" reinforces local SEO.
        $title = "{$name} Alternatives in Chicagoland";

        // Prefer the competitor's unique comparison_note so each page has a
        // distinct meta description (avoids templated/duplicate snippets).
        $note = trim((string) ($competitor['comparison_note'] ?? ''));
        if ($note !== '') {
            $description = \Illuminate\Support\Str::limit($note, 155);
        } else {
            $description = "Comparing GS Construction with {$name}? See a factual side-by-side on service area, project types, communication and reviews — and request a free Chicagoland estimate.";
        }

        self::setTags($title, $description, asset('images/greg-patryk.jpg'));

        self::seo()->keywords([
            "alternative to {$name}",
            "{$name} vs",
            "{$name} reviews",
            'compare chicago remodeling contractors',
        ]);

        // Safety valve: keep entries without unique, index-ready copy out of
        // the index until they're ready (config 'noindex' => true).
        if (! empty($competitor['noindex'])) {
            self::seo()->markNoindex();
        }
    }

    /**
     * Helper to set common meta tags via the SEO builder.
     */
    protected static function setTags(string $title, string $description, ?string $image = null): void
    {
        $title = self::normalizeMetaText($title, 60);
        $description = self::normalizeMetaText($description, 150);

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
            ->siteName('GS Construction & Remodeling')
            ->locale('en_US')
            ->type('website');

        // Apply domain-specific SEO enhancements (keywords, canonical)
        self::applyDomainEnhancements();
    }

    protected static function normalizeMetaText(string $value, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $value));
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max);

        // Prefer ending on a full sentence — a snippet cut mid-phrase reads as
        // broken in the SERP. Fall back to a word boundary.
        $lastStop = mb_strrpos($cut, '.');
        if ($lastStop !== false && $lastStop > (int) ($max * 0.6)) {
            return rtrim(mb_substr($cut, 0, $lastStop + 1));
        }

        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > (int) ($max * 0.7)) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " \t\n\r\0\x0B,;:-&");
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
        // Prefer the cover image of a FEATURED project (kept deterministic via
        // orderBy id so the OG/share image is stable for caching/previews),
        // then fall back to any published project's cover of this type.
        $image = \App\Models\ProjectImage::query()
            ->where('is_cover', true)
            ->whereHas('project', fn ($q) => $q->where('is_published', true)->where('is_featured', true)->where('project_type', $projectType))
            ->orderBy('id')
            ->first()
            ?? \App\Models\ProjectImage::query()
                ->where('is_cover', true)
                ->whereHas('project', fn ($q) => $q->where('is_published', true)->where('project_type', $projectType))
                ->orderBy('id')
                ->first();

        if ($image?->url) {
            return $image->url;
        }

        if (in_array($projectType, ['basement', 'addition'], true)) {
            $curated = \App\Support\ServiceImages::firstUrl($projectType);
            if (is_string($curated) && $curated !== '') {
                return $curated;
            }
        }

        return null;
    }
}
