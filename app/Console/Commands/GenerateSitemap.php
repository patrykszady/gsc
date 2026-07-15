<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use App\Models\Project;
use App\Models\Testimonial;
use App\Services\GoogleBusinessProfileService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;

class GenerateSitemap extends Command
{
    protected $signature = 'sitemap:generate {--url= : Base URL for the sitemap (defaults to APP_URL)}';

    protected $description = 'Generate the sitemap for the website';

    public function handle(GoogleBusinessProfileService $googleBusinessProfileService): int
    {
        $baseUrl = rtrim($this->option('url') ?: config('app.url'), '/');
        $isLocalBase = str_contains($baseUrl, '127.0.0.1') || str_contains($baseUrl, 'localhost');
        if (app()->environment('production') && $isLocalBase) {
            $this->error("Invalid base URL for production sitemap: {$baseUrl}");
            $this->line('Set APP_URL to your live domain or pass --url=https://gs.construction');
            return Command::FAILURE;
        }
        
        $this->info("Generating sitemap with base URL: {$baseUrl}");

        // Prepare URL rewriting for images (Storage::url() uses APP_URL which may be localhost)
        $appUrl = rtrim(config('app.url'), '/');
        $needsImageRewrite = $appUrl !== $baseUrl;

        $sitemap = Sitemap::create();
        $urlCount = 0;
        $imageCount = 0;

        $resolveImageUrl = static function ($image) use ($googleBusinessProfileService): ?string {
            // Prefer canonical full-size originals for image indexing quality.
            // Thumbnail URLs can still be crawled, but originals are a stronger
            // signal for Google Image metadata/indexing.
            $preferred = [
                $image->webp_url ?? null,
                $image->url ?? null,
            ];
            foreach ($preferred as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    return $candidate;
                }
            }

            $googleUrl = $image->google_places_media_url;
            if (is_string($googleUrl) && trim($googleUrl) !== '') {
                return $googleUrl;
            }

            $imageUrl = $image->getAnyUrl('large');
            if (is_string($imageUrl) && trim($imageUrl) !== '') {
                return $imageUrl;
            }

            $mediaName = $image->google_places_media_name;
            if (! is_string($mediaName) || trim($mediaName) === '') {
                return null;
            }

            $googleUrl = $googleBusinessProfileService->getMediaUrlCached($mediaName);

            return is_string($googleUrl) && trim($googleUrl) !== ''
                ? $googleUrl
                : null;
        };

        // Get all registered routes
        $routes = collect(Route::getRoutes()->getRoutes());

        // Define patterns to exclude from sitemap
        // NOTE: matched as substrings against the route URI; only use here for asset/path
        // shapes that we want broadly killed (e.g. livewire/, .map, .json). Specific
        // redirect URIs go in $excludeExact below to avoid accidentally killing
        // canonical pages that share a substring (e.g. 'kitchen-remodeling' would
        // otherwise also kill '/services/kitchen-remodeling').
        $excludePatterns = [
            'admin',
            'login',
            'logout',
            'api',
            'robots.txt',
            'log-viewer',
            'flux/',        // internal flux assets
            'livewire/',    // internal livewire assets
            'livewire-',    // livewire asset routes (e.g. /livewire-xxxx/livewire.js)
            '.map',         // sourcemaps
            'sanctum',      // sanctum routes
            // Non-HTML resources — must not appear in sitemap (Google indexes HTML pages,
            // not JSON/TXT/XML feeds; their inclusion previously caused canonical mismatches
            // and contributed to GSC sitemap reporting 'indexed=0').
            '.txt',
            '.json',
            '.xml',
            '.webmanifest',
            '.ico',
        ];

        // Exact URIs to exclude (redirects and noindex aliases)
        $excludeExact = [
            'areas',        // alias of /areas-served (noindex)
            'locations',    // alias of /areas-served (noindex)
            's/{code}',     // short link redirects
            'up',           // health check
            'testimonials', // redirect to /reviews
            'contact-us',   // redirect to /contact
            'review',       // 302 shortlink → Google write-a-review (no HTML/schema)
            // Root-level legacy redirects → /services/*
            'bathroom-remodeling',
            'kitchen-remodeling',
            'home-remodeling',
            'basement-remodeling',
            'basement-finishing',
            'home-additions',
            'additions',
            // /services/* legacy redirects to canonical -remodeling slugs
            'services/kitchens',
            'services/bathrooms',
            'services/basements',
            'services/basement-finishing',
            'services/additions',
            'services/room-additions',
            // GEO admin dashboard (auth-gated; redirects to /admin/login for crawlers)
            'geo',
            'geo/feed',
            'geo/llms',
            'geo/models',
            'geo/schema',
            'geo/settings',
        ];

        // Static pages from routes (non-parameterized GET routes)
        $staticRoutes = $routes->filter(function ($route) use ($excludePatterns, $excludeExact) {
            $uri = $route->uri();
            
            // Skip routes with parameters
            if (str_contains($uri, '{')) {
                return false;
            }

            // Skip ALL 301 redirect routes (Route::redirect uses RedirectController).
            // Listing redirect sources in the sitemap triggers Google "Page with
            // redirect" coverage errors. This auto-excludes every current and
            // future redirect without maintaining a manual list.
            if (str_contains($route->getActionName(), 'RedirectController')) {
                return false;
            }

            // Skip intentionally-gone (410) routes, flagged by a `gone.*` name.
            // Submitting 410 URLs causes "Submitted URL not found" coverage errors.
            $routeName = (string) $route->getName();
            if ($routeName !== '' && str_starts_with($routeName, 'gone.')) {
                return false;
            }
            
            // Skip exact matches (redirects)
            if (in_array($uri, $excludeExact)) {
                return false;
            }
            
            // Skip excluded patterns
            foreach ($excludePatterns as $pattern) {
                if (str_contains($uri, $pattern)) {
                    return false;
                }
            }
            
            // Only GET routes
            return in_array('GET', $route->methods());
        });

        // Priority mapping for static routes
        $priorities = [
            '/' => 1.0,
            'reviews' => 0.8,
            'projects' => 0.8,
            'contact' => 0.8,
            'about' => 0.7,
            'areas-served' => 0.8,
            'services' => 0.8,
            'services/kitchen-remodeling' => 0.9,
            'services/bathroom-remodeling' => 0.9,
            'services/home-remodeling' => 0.9,
            'services/basement-remodeling' => 0.9,
            'services/home-additions' => 0.9,
        ];

        $changeFrequencies = [
            '/' => Url::CHANGE_FREQUENCY_WEEKLY,
            'reviews' => Url::CHANGE_FREQUENCY_WEEKLY,
            'projects' => Url::CHANGE_FREQUENCY_WEEKLY,
            'contact' => Url::CHANGE_FREQUENCY_MONTHLY,
            'about' => Url::CHANGE_FREQUENCY_MONTHLY,
            'services/kitchen-remodeling' => Url::CHANGE_FREQUENCY_WEEKLY,
            'services/bathroom-remodeling' => Url::CHANGE_FREQUENCY_WEEKLY,
            'services/home-remodeling' => Url::CHANGE_FREQUENCY_WEEKLY,
            'services/basement-remodeling' => Url::CHANGE_FREQUENCY_WEEKLY,
            'services/home-additions' => Url::CHANGE_FREQUENCY_WEEKLY,
        ];

        foreach ($staticRoutes as $route) {
            $isHome = $route->uri() === '/';
            $uri = $isHome ? '' : $route->uri();
            $priority = $priorities[$route->uri()] ?? 0.5;
            $changeFreq = $changeFrequencies[$route->uri()] ?? Url::CHANGE_FREQUENCY_MONTHLY;

            // Homepage canonical is rendered without trailing slash; sitemap entry must match
            // exactly or Google flags the sitemap URL as not-indexed (canonical mismatch).
            $fullUrl = $isHome ? $baseUrl : "{$baseUrl}/{$uri}";

            $sitemap->add(
                Url::create($fullUrl)
                    ->setLastModificationDate(now())
                    ->setChangeFrequency($changeFreq)
                    ->setPriority($priority)
            );
            $urlCount++;
            $this->line("  Added static: /{$uri}");
        }

        // Add competitor comparison pages
        $competitors = (array) config('competitors.competitors', []);
        if ((bool) config('competitors.enabled', true) && ! empty($competitors)) {
            $this->info('Adding competitor comparison pages to sitemap...');
            foreach ($competitors as $competitor) {
                $slug = (string) ($competitor['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                // Skip entries explicitly held out of the index (safety valve).
                if (! empty($competitor['noindex'])) {
                    $this->line("  Skipped compare (noindex): /compare/{$slug}");
                    continue;
                }
                $sitemap->add(
                    Url::create("{$baseUrl}/compare/{$slug}")
                        ->setLastModificationDate(now())
                        ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
                        ->setPriority(0.6)
                );
                $urlCount++;
                $this->line("  Added compare: /compare/{$slug}");
            }
        }

        // Add cost-guide pages (the /costs hub is a parameterless route and is
        // picked up with the other static routes above).
        $costGuides = (array) config('remodel-costs.guides', []);
        if ((bool) config('remodel-costs.enabled', true) && ! empty($costGuides)) {
            $this->info('Adding cost-guide pages to sitemap...');
            foreach ($costGuides as $guide) {
                $slug = (string) ($guide['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $sitemap->add(
                    Url::create("{$baseUrl}/costs/{$slug}")
                        ->setLastModificationDate(now())
                        ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
                        ->setPriority(0.7)
                );
                $urlCount++;
                $this->line("  Added cost guide: /costs/{$slug}");
            }
        }

        // Add insurance-claim repair pages (the /insurance-claims hub is a
        // parameterless route and is picked up with the static routes above).
        $claimPages = (array) config('insurance-claims.claims', []);
        if ((bool) config('insurance-claims.enabled', true) && ! empty($claimPages)) {
            $this->info('Adding insurance-claim pages to sitemap...');
            foreach ($claimPages as $claimPage) {
                $slug = (string) ($claimPage['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $sitemap->add(
                    Url::create("{$baseUrl}/insurance-claims/{$slug}")
                        ->setLastModificationDate(now())
                        ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
                        ->setPriority(0.7)
                );
                $urlCount++;
                $this->line("  Added insurance claim: /insurance-claims/{$slug}");
            }
        }

        // Add building-permit guide pages (the /permits hub is a parameterless
        // route and is picked up with the static routes above).
        $permitGuides = \App\Support\PermitGuideInfo::all();
        if (! empty($permitGuides)) {
            $this->info('Adding permit-guide pages to sitemap...');
            foreach ($permitGuides as $slug => $permitGuide) {
                $slug = (string) $slug;
                if ($slug === '') {
                    continue;
                }
                $researchedAt = (string) ($permitGuide['researched_at'] ?? '');
                $lastMod = $researchedAt !== ''
                    ? \Illuminate\Support\Carbon::parse($researchedAt)
                    : now();
                $sitemap->add(
                    Url::create("{$baseUrl}/permits/{$slug}")
                        ->setLastModificationDate($lastMod)
                        ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
                        ->setPriority(0.7)
                );
                $urlCount++;
                $this->line("  Added permit guide: /permits/{$slug}");
            }
        }

        // Add trade-partner pages (the /trades hub is a parameterless route and
        // is picked up with the other static routes above).
        $trades = (array) config('trades.trades', []);
        if ((bool) config('trades.enabled', true) && ! empty($trades)) {
            $this->info('Adding trade-partner pages to sitemap...');
            foreach ($trades as $trade) {
                $slug = (string) ($trade['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $sitemap->add(
                    Url::create("{$baseUrl}/trades/{$slug}")
                        ->setLastModificationDate(now())
                        ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
                        ->setPriority(0.6)
                );
                $urlCount++;
                $this->line("  Added trade: /trades/{$slug}");
            }
        }

        // Add demand-driven landing pages — only published pages that clear the
        // proof gate (shouldIndex). Draft or thin pages are never sitemapped.
        $landingPages = \App\Models\LandingPage::published()->get();
        if ($landingPages->isNotEmpty()) {
            $this->info('Adding landing pages to sitemap...');
            foreach ($landingPages as $lp) {
                if (! $lp->shouldIndex()) {
                    $this->line("  Skipped landing (not indexable): /remodeling/{$lp->slug}");
                    continue;
                }
                $sitemap->add(
                    Url::create("{$baseUrl}/remodeling/{$lp->slug}")
                        ->setLastModificationDate($lp->updated_at ?? now())
                        ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                        ->setPriority(0.7)
                );
                $urlCount++;
                $this->line("  Added landing: /remodeling/{$lp->slug}");
            }
        }

        // Add area-served pages
        $this->info("Adding area-served pages to sitemap...");
        $areas = AreaServed::orderBy('city')->get();
        $areaPages = ['', 'contact', 'testimonials', 'projects', 'about', 'services'];
        $areaServicePages = ['kitchen-remodeling', 'bathroom-remodeling', 'home-remodeling', 'basement-remodeling', 'home-additions'];
        $includeAreaServicePages = (bool) config('seo.sitemap_generation.include_area_service_pages', true);
        $areaCount = 0;

        // Get latest project updated_at for area lastmod dates
        $latestProjectDate = Project::where('is_published', true)->max('updated_at');
        $areaLastmod = $latestProjectDate ? \Carbon\Carbon::parse($latestProjectDate) : now();

        foreach ($areas as $area) {
            // Honest per-city lastmod: most recent of the area row itself and any
            // project completed in this city (falls back to the global date).
            $thisAreaLastmod = $area->lastmod() ?? $areaLastmod;

            // Standard area pages — only sitemap the variants the index policy
            // keeps (never advertise a URL we noindex; see AreaSeoPolicy).
            foreach ($areaPages as $page) {
                $policyPage = $page === '' ? 'home' : $page;
                if (! \App\Support\SEO\AreaSeoPolicy::shouldIndex($area, $policyPage)) {
                    continue;
                }

                $uri = $page ? "areas-served/{$area->slug}/{$page}" : "areas-served/{$area->slug}";
                $priority = $page === '' ? 0.7 : 0.6; // Area home pages slightly higher

                $sitemap->add(
                    Url::create("{$baseUrl}/{$uri}")
                        ->setLastModificationDate($thisAreaLastmod)
                        ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
                        ->setPriority($priority)
                );
                $urlCount++;
                $areaCount++;
            }

            // Lead service line replacement guide — only when official municipal
            // info was verified for this town (otherwise the page is noindexed).
            if (\App\Support\LeadLineInfo::hasOfficialInfo($area->slug)) {
                $sitemap->add(
                    Url::create("{$baseUrl}/areas-served/{$area->slug}/lead-pipe-replacement")
                        ->setLastModificationDate($thisAreaLastmod)
                        ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
                        ->setPriority(0.6)
                );
                $urlCount++;
            }

            // Area-specific service pages — only for cities with real local proof.
            if ($includeAreaServicePages) {
                foreach ($areaServicePages as $servicePage) {
                    if (! \App\Support\SEO\AreaSeoPolicy::shouldIndex($area, 'service', $servicePage)) {
                        continue;
                    }

                    $uri = "areas-served/{$area->slug}/services/{$servicePage}";

                    $sitemap->add(
                        Url::create("{$baseUrl}/{$uri}")
                            ->setLastModificationDate($thisAreaLastmod)
                            ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                            ->setPriority(0.8) // High priority for local service keywords
                    );
                    $urlCount++;
                    $areaCount++;
                }
            }
        }
        $totalPageTypes = count($areaPages) + ($includeAreaServicePages ? count($areaServicePages) : 0);
        $this->line("  Added {$areaCount} area pages ({$areas->count()} areas × {$totalPageTypes} page types)");
        if (! $includeAreaServicePages) {
            $this->comment('  Skipped area-service URLs (SITEMAP_INCLUDE_AREA_SERVICE_PAGES=false)');
        }

        // Add ZIP-code service-area landing pages
        $this->info("Adding ZIP-code service-area pages to sitemap...");
        $zipMap = app(\App\Services\ZipCodeService::class)->getZipMap();
        $sitemap->add(
            Url::create("{$baseUrl}/service-area")
                ->setLastModificationDate(now())
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                ->setPriority(0.6)
        );
        $urlCount++;
        $zipCount = 0;
        $includeZipPages = (bool) config('seo.sitemap_generation.include_zip_pages', true);
        if ($includeZipPages) {
            foreach ($zipMap as $zip => $info) {
                $sitemap->add(
                    Url::create("{$baseUrl}/service-area/{$zip}")
                        ->setLastModificationDate($areaLastmod)
                        ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
                        ->setPriority(0.65)
                );
                $urlCount++;
                $zipCount++;
            }
        }
        $this->line("  Added {$zipCount} ZIP service-area pages");
        if (! $includeZipPages) {
            $this->comment('  Skipped ZIP URLs (SITEMAP_INCLUDE_ZIP_PAGES=false)');
        }

        // Add individual review pages
        $this->info("Adding review pages to sitemap...");
        $testimonials = Testimonial::visible()->orderBy('review_date', 'desc')->get();
        $testimonialCount = 0;

        foreach ($testimonials as $testimonial) {
            $sitemap->add(
                Url::create("{$baseUrl}/reviews/{$testimonial->slug}")
                    ->setLastModificationDate($testimonial->updated_at ?? $testimonial->review_date ?? now())
                    ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
                    ->setPriority(0.6)
            );
            $urlCount++;
            $testimonialCount++;
        }
        $this->line("  Added {$testimonialCount} review pages");

        // Add project type filter pages (e.g., /projects/kitchens)
        $this->info("Adding project type filter pages to sitemap...");
        $projectTypePages = [
            'projects/kitchens' => 0.8,
            'projects/bathrooms' => 0.8,
            'projects/home-remodeling' => 0.8,
        ];
        foreach ($projectTypePages as $uri => $priority) {
            $sitemap->add(
                Url::create("{$baseUrl}/{$uri}")
                    ->setLastModificationDate(now())
                    ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                    ->setPriority($priority)
            );
            $urlCount++;
        }
        $this->line("  Added " . count($projectTypePages) . " project type filter pages");

        // Add individual project pages with images
        $this->info("Adding project pages to sitemap...");
        $projects = Project::where('is_published', true)->with('images')->get();
        $projectCount = 0;
        $imageCount = 0;
        $photoPageCount = 0;
        

        foreach ($projects as $project) {
            $url = Url::create("{$baseUrl}/projects/{$project->slug}")
                ->setLastModificationDate($project->updated_at ?? now())
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
                ->setPriority(0.7);
            
            // Add project images to sitemap for Google Image Search
            foreach ($project->images as $image) {
                $imageUrl = $resolveImageUrl($image);
                if (is_string($imageUrl) && trim($imageUrl) !== '') {
                    if ($needsImageRewrite) {
                        $imageUrl = str_replace($appUrl, $baseUrl, $imageUrl);
                    }
                    $url->addImage(
                        url: $imageUrl,
                        caption: $image->alt_text ?? '',
                        title: $project->title . ($image->is_cover ? ' - Featured Image' : ''),
                    );
                    $imageCount++;
                }
            }
            
            $sitemap->add($url);
            $urlCount++;
            $projectCount++;

            // Add individual photo pages for each project image (using slugs)
            foreach ($project->images as $image) {
                $imageSlug = $image->slug ?: $image->id; // Fallback to ID if no slug
                
                // Base photo page (canonical)
                $photoUrl = Url::create("{$baseUrl}/projects/{$project->slug}/photos/{$imageSlug}")
                    ->setLastModificationDate($image->updated_at ?? $project->updated_at ?? now())
                    ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
                    ->setPriority(0.5);

                $photoImageUrl = $resolveImageUrl($image);
                if (is_string($photoImageUrl) && trim($photoImageUrl) !== '') {
                    if ($needsImageRewrite) {
                        $photoImageUrl = str_replace($appUrl, $baseUrl, $photoImageUrl);
                    }
                    $photoUrl->addImage(
                        url: $photoImageUrl,
                        caption: $image->alt_text ?? '',
                        title: $project->title . ' - Photo',
                    );
                }

                $sitemap->add($photoUrl);
                $urlCount++;
                $photoPageCount++;
                
            }
        }
        $this->line("  Added {$projectCount} project pages ({$imageCount} images in sitemap)");
        $this->line("  Added {$photoPageCount} individual photo pages");

        // Write to storage first, then copy to public (for Forge zero-downtime deployments)
        $storagePath = storage_path('app/sitemap.xml');
        $publicPath = public_path('sitemap.xml');

        $sitemap->writeToFile($storagePath);

        // Copy to public directory
        if (copy($storagePath, $publicPath)) {
            $this->info('Sitemap generated successfully at public/sitemap.xml');
        } else {
            $this->warn('Sitemap saved to storage/app/sitemap.xml but could not copy to public/');
            $this->warn('You may need to manually symlink or copy it.');
        }

        $this->newLine();
        $this->info("=== Summary ===");
        $this->info("Total URLs: {$urlCount}");
        $this->info("  - Static pages: " . $staticRoutes->count());
        $this->info("  - Area pages: {$areaCount}");
        $this->info("  - Testimonial pages: {$testimonialCount}");
        $this->info("  - Project pages: {$projectCount} ({$imageCount} images)");
        $this->info("  - Photo pages: {$photoPageCount}");

        // Keep public/image-sitemap.xml in sync so GSC image-search tracking has a stable feed.
        $this->call('seo:image-sitemap-build');

        return Command::SUCCESS;
    }
}
