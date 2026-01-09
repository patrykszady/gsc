<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\Testimonial;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SeoAudit extends Command
{
    protected $signature = 'seo:audit 
                            {--fix : Auto-fix issues where possible}
                            {--detailed : Show detailed output}
                            {--crawl : Crawl live URLs from sitemap}
                            {--url= : Audit a specific URL}';

    protected $description = 'Audit SEO elements across the site (alt text, content length, meta data, broken links)';

    protected int $issueCount = 0;
    protected int $fixedCount = 0;
    protected int $warningCount = 0;
    protected array $crawledUrls = [];
    protected array $brokenLinks = [];

    public function handle(): int
    {
        $this->info('🔍 Running SEO Audit...');
        $this->newLine();

        // Single URL audit mode
        if ($url = $this->option('url')) {
            return $this->auditSingleUrl($url);
        }

        // Database audits (always run)
        $this->auditProjectImages();
        $this->auditProjects();
        $this->auditTestimonials();
        $this->auditViewFiles();
        $this->auditSitemap();

        // Live URL crawling (optional)
        if ($this->option('crawl')) {
            $this->crawlSitemap();
        }

        $this->newLine();
        
        if ($this->issueCount === 0 && $this->warningCount === 0) {
            $this->info('✅ No SEO issues found!');
            return Command::SUCCESS;
        }

        if ($this->issueCount > 0) {
            $this->error("❌ Found {$this->issueCount} error(s)");
        }
        if ($this->warningCount > 0) {
            $this->warn("⚠️  Found {$this->warningCount} warning(s)");
        }
        
        if ($this->option('fix')) {
            $this->info("✅ Auto-fixed {$this->fixedCount} issue(s)");
        } else {
            $this->line('Run with --fix to auto-fix issues where possible');
        }

        if (!$this->option('crawl')) {
            $this->line('Run with --crawl to check live URLs for broken links');
        }

        return $this->issueCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    protected function auditProjectImages(): void
    {
        $this->info('📷 Checking project images...');
        
        $imagesWithoutAlt = ProjectImage::whereNull('alt_text')
            ->orWhere('alt_text', '')
            ->get();

        foreach ($imagesWithoutAlt as $image) {
            $this->issueCount++;
            $this->warn("  Missing alt text: Image #{$image->id} ({$image->filename})");
            
            if ($this->option('fix') && $image->project) {
                $altText = $this->generateAltText($image);
                $image->update(['alt_text' => $altText]);
                $this->fixedCount++;
                $this->line("    → Fixed: \"{$altText}\"");
            }
        }

        // Check for duplicate alt text
        $duplicates = ProjectImage::selectRaw('alt_text, COUNT(*) as count')
            ->whereNotNull('alt_text')
            ->where('alt_text', '!=', '')
            ->groupBy('alt_text')
            ->having('count', '>', 3)
            ->get();

        foreach ($duplicates as $dup) {
            $this->issueCount++;
            $this->warn("  Overused alt text ({$dup->count}x): \"{$dup->alt_text}\"");
        }

        $total = ProjectImage::count();
        $withAlt = ProjectImage::whereNotNull('alt_text')->where('alt_text', '!=', '')->count();
        $this->line("  {$withAlt}/{$total} images have alt text");
    }

    protected function auditProjects(): void
    {
        $this->newLine();
        $this->info('📁 Checking projects...');

        // Projects without descriptions
        $noDescription = Project::whereNull('description')
            ->orWhere('description', '')
            ->where('is_published', true)
            ->get();

        foreach ($noDescription as $project) {
            $this->issueCount++;
            $this->warn("  Missing description: {$project->title}");
        }

        // Projects with short descriptions (< 100 chars)
        $shortDescription = Project::where('is_published', true)
            ->whereNotNull('description')
            ->whereRaw('LENGTH(description) < 100')
            ->get();

        foreach ($shortDescription as $project) {
            $this->issueCount++;
            $this->warn("  Short description (" . strlen($project->description) . " chars): {$project->title}");
        }

        // Projects without cover images
        $noCover = Project::where('is_published', true)
            ->whereDoesntHave('images', fn($q) => $q->where('is_cover', true))
            ->get();

        foreach ($noCover as $project) {
            $this->issueCount++;
            $this->warn("  No cover image: {$project->title}");
            
            if ($this->option('fix')) {
                $firstImage = $project->images()->first();
                if ($firstImage) {
                    $firstImage->update(['is_cover' => true]);
                    $this->fixedCount++;
                    $this->line("    → Fixed: Set first image as cover");
                }
            }
        }

        $total = Project::where('is_published', true)->count();
        $this->line("  {$total} published projects audited");
    }

    protected function auditTestimonials(): void
    {
        $this->newLine();
        $this->info('⭐ Checking testimonials...');

        // Short testimonials
        $short = Testimonial::whereRaw('LENGTH(review_description) < 50')->get();
        
        foreach ($short as $testimonial) {
            $this->issueCount++;
            $this->warn("  Very short review (" . strlen($testimonial->review_description) . " chars): {$testimonial->reviewer_name}");
        }

        // Missing project type
        $noType = Testimonial::whereNull('project_type')
            ->orWhere('project_type', '')
            ->get();

        foreach ($noType as $testimonial) {
            $this->issueCount++;
            $this->warn("  Missing project type: {$testimonial->reviewer_name}");
        }

        $total = Testimonial::count();
        $this->line("  {$total} testimonials audited");
    }

    protected function auditViewFiles(): void
    {
        $this->newLine();
        $this->info('📄 Checking view files...');

        $viewPath = resource_path('views');
        $files = File::allFiles($viewPath);
        
        $imgWithoutAlt = 0;
        $imgWithoutLazy = 0;

        foreach ($files as $file) {
            if (!str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $relativePath = str_replace($viewPath . '/', '', $file->getPathname());
            
            // Skip admin views (not public-facing)
            if (str_contains($relativePath, 'admin/')) {
                continue;
            }

            $content = File::get($file->getPathname());

            // Check for <img> tags without alt
            // Match img tags that may span multiple lines
            if (preg_match_all('/<img\s+(?:[^>]*?\s+)*?>/is', $content, $matches)) {
                foreach ($matches[0] as $imgTag) {
                    // Skip images with aria-hidden (decorative)
                    if (preg_match('/aria-hidden\s*=\s*["\']true["\']/i', $imgTag)) {
                        continue;
                    }
                    
                    if (!preg_match('/\balt\s*=/i', $imgTag)) {
                        $imgWithoutAlt++;
                        if ($this->option('detailed')) {
                            $this->warn("  Missing alt in {$relativePath}");
                        }
                    }
                    
                    // Check lazy loading (skip if it has loading="eager" for above-fold)
                    if (!preg_match('/\bloading\s*=/i', $imgTag) && 
                        !preg_match('/\bx-bind:loading/i', $imgTag) &&
                        !preg_match('/\:loading/i', $imgTag)) {
                        $imgWithoutLazy++;
                    }
                }
            }
        }

        if ($imgWithoutAlt > 0) {
            $this->issueCount++;
            $this->warn("  {$imgWithoutAlt} <img> tag(s) without alt attribute");
        }

        if ($imgWithoutLazy > 0) {
            $this->warn("  {$imgWithoutLazy} <img> tag(s) without loading attribute (consider lazy loading)");
        }

        $this->line("  " . count($files) . " view files scanned");
    }

    protected function auditSitemap(): void
    {
        $this->newLine();
        $this->info('🗺️  Checking sitemap...');

        $sitemapPath = public_path('sitemap.xml');
        
        if (!File::exists($sitemapPath)) {
            $this->issueCount++;
            $this->warn("  Sitemap not found at public/sitemap.xml");
            $this->line("  Run: php artisan sitemap:generate");
            return;
        }

        $content = File::get($sitemapPath);
        $urlCount = substr_count($content, '<url>');
        $lastModified = File::lastModified($sitemapPath);
        $daysOld = (time() - $lastModified) / 86400;

        $this->line("  {$urlCount} URLs in sitemap");
        $this->line("  Last updated: " . date('Y-m-d H:i', $lastModified) . " (" . round($daysOld, 1) . " days ago)");

        if ($daysOld > 7) {
            $this->issueCount++;
            $this->warn("  Sitemap is more than 7 days old - consider regenerating");
        }
    }

    protected function generateAltText(ProjectImage $image): string
    {
        $project = $image->project;
        
        if (!$project) {
            return 'Remodeling project image';
        }

        $type = ucfirst(str_replace('-', ' ', $project->project_type ?? 'home'));
        $location = $project->location;
        
        // Build descriptive alt text
        $parts = ["{$type} remodeling"];
        
        if ($location) {
            $parts[] = "in {$location}";
        }
        
        $parts[] = 'by GS Construction';

        return implode(' ', $parts);
    }

    /**
     * Audit a single URL
     */
    protected function auditSingleUrl(string $url): int
    {
        $this->info("🔍 Auditing: {$url}");
        $this->newLine();

        try {
            $response = Http::timeout(30)->get($url);
            
            if (!$response->successful()) {
                $this->error("  ❌ HTTP {$response->status()}");
                return Command::FAILURE;
            }

            $html = $response->body();
            $this->analyzePageHtml($url, $html);

        } catch (\Exception $e) {
            $this->error("  ❌ Failed to fetch: {$e->getMessage()}");
            return Command::FAILURE;
        }

        $this->newLine();
        $this->printSummary();

        return $this->issueCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Crawl all URLs from the sitemap
     */
    protected function crawlSitemap(): void
    {
        $this->newLine();
        $this->info('🌐 Crawling live URLs from sitemap...');

        $sitemapPath = public_path('sitemap.xml');
        
        if (!File::exists($sitemapPath)) {
            $this->warn('  Sitemap not found, skipping URL crawl');
            return;
        }

        $content = File::get($sitemapPath);
        preg_match_all('/<loc>(.+?)<\/loc>/i', $content, $matches);
        $urls = $matches[1] ?? [];

        if (empty($urls)) {
            $this->warn('  No URLs found in sitemap');
            return;
        }

        $this->line("  Found " . count($urls) . " URLs to crawl");
        $progress = $this->output->createProgressBar(count($urls));
        $progress->start();

        foreach ($urls as $url) {
            $this->crawlUrl($url);
            $progress->advance();
        }

        $progress->finish();
        $this->newLine(2);

        // Report broken links
        if (!empty($this->brokenLinks)) {
            $this->error('  Broken links found:');
            foreach ($this->brokenLinks as $link) {
                $this->line("    ❌ [{$link['status']}] {$link['url']}");
                $this->line("       Found on: {$link['source']}");
            }
        } else {
            $this->info('  ✅ No broken links found');
        }
    }

    /**
     * Crawl a single URL and check for issues
     */
    protected function crawlUrl(string $url): void
    {
        if (in_array($url, $this->crawledUrls)) {
            return;
        }
        $this->crawledUrls[] = $url;

        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'GS-SEO-Audit/1.0'])
                ->get($url);

            if (!$response->successful()) {
                $this->brokenLinks[] = [
                    'url' => $url,
                    'status' => $response->status(),
                    'source' => 'sitemap',
                ];
                $this->issueCount++;
                return;
            }

            $html = $response->body();
            $this->analyzePageHtml($url, $html, false);

        } catch (\Exception $e) {
            $this->brokenLinks[] = [
                'url' => $url,
                'status' => 'timeout/error',
                'source' => 'sitemap',
            ];
            $this->issueCount++;
        }
    }

    /**
     * Analyze HTML content for SEO issues
     */
    protected function analyzePageHtml(string $url, string $html, bool $verbose = true): void
    {
        $issues = [];

        // Check title
        if (!preg_match('/<title>(.+?)<\/title>/is', $html, $titleMatch)) {
            $issues[] = '❌ Missing <title> tag';
            $this->issueCount++;
        } else {
            $title = trim($titleMatch[1]);
            $titleLen = strlen($title);
            if ($titleLen < 30) {
                $issues[] = "⚠️  Title too short ({$titleLen} chars): \"{$title}\"";
                $this->warningCount++;
            } elseif ($titleLen > 60) {
                $issues[] = "⚠️  Title too long ({$titleLen} chars): \"" . substr($title, 0, 50) . "...\"";
                $this->warningCount++;
            } elseif ($verbose) {
                $issues[] = "✅ Title ({$titleLen} chars): \"{$title}\"";
            }
        }

        // Check meta description
        if (!preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/', $html, $descMatch) &&
            !preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']description["\']/', $html, $descMatch)) {
            $issues[] = '❌ Missing meta description';
            $this->issueCount++;
        } else {
            $desc = trim($descMatch[1]);
            $descLen = strlen($desc);
            if ($descLen < 70) {
                $issues[] = "⚠️  Meta description too short ({$descLen} chars)";
                $this->warningCount++;
            } elseif ($descLen > 160) {
                $issues[] = "⚠️  Meta description too long ({$descLen} chars)";
                $this->warningCount++;
            } elseif ($verbose) {
                $issues[] = "✅ Meta description ({$descLen} chars)";
            }
        }

        // Check H1
        preg_match_all('/<h1[^>]*>(.+?)<\/h1>/is', $html, $h1Matches);
        $h1Count = count($h1Matches[1]);
        if ($h1Count === 0) {
            $issues[] = '❌ Missing H1 heading';
            $this->issueCount++;
        } elseif ($h1Count > 1) {
            $issues[] = "⚠️  Multiple H1 tags ({$h1Count} found)";
            $this->warningCount++;
        } elseif ($verbose) {
            $issues[] = "✅ H1: \"" . strip_tags(trim($h1Matches[1][0])) . "\"";
        }

        // Check canonical
        if (!preg_match('/<link[^>]+rel=["\']canonical["\']/', $html)) {
            $issues[] = '⚠️  Missing canonical URL';
            $this->warningCount++;
        } elseif ($verbose) {
            $issues[] = '✅ Canonical URL present';
        }

        // Check Open Graph
        if (!preg_match('/<meta[^>]+property=["\']og:/', $html)) {
            $issues[] = '⚠️  Missing Open Graph meta tags';
            $this->warningCount++;
        } elseif ($verbose) {
            $issues[] = '✅ Open Graph meta tags present';
        }

        // Check images
        preg_match_all('/<img[^>]+>/i', $html, $imgMatches);
        $imagesWithoutAlt = 0;
        foreach ($imgMatches[0] as $img) {
            // Skip images that are explicitly decorative (aria-hidden)
            if (preg_match('/aria-hidden\s*=\s*["\']true["\']/i', $img)) {
                continue;
            }
            if (!preg_match('/\balt\s*=/i', $img)) {
                $imagesWithoutAlt++;
            }
        }
        if ($imagesWithoutAlt > 0) {
            $issues[] = "⚠️  {$imagesWithoutAlt} image(s) without alt text";
            $this->warningCount++;
        } elseif ($verbose && count($imgMatches[0]) > 0) {
            $issues[] = "✅ All " . count($imgMatches[0]) . " images have alt text";
        }

        // Check internal links for broken links
        if ($this->option('crawl') || $this->option('url')) {
            preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $linkMatches);
            $baseUrl = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);
            
            foreach ($linkMatches[1] as $href) {
                // Skip external links, anchors, javascript, mailto, tel
                if (str_starts_with($href, '#') || 
                    str_starts_with($href, 'javascript:') ||
                    str_starts_with($href, 'mailto:') ||
                    str_starts_with($href, 'tel:') ||
                    (str_starts_with($href, 'http') && !str_starts_with($href, $baseUrl))) {
                    continue;
                }

                // Build full URL
                $fullUrl = str_starts_with($href, 'http') ? $href : $baseUrl . $href;
                
                // Check link (only if not already crawled)
                if (!in_array($fullUrl, $this->crawledUrls)) {
                    $this->checkLink($fullUrl, $url);
                }
            }
        }

        // Print issues for single URL mode
        if ($verbose && !empty($issues)) {
            foreach ($issues as $issue) {
                $this->line("  {$issue}");
            }
        }
    }

    /**
     * Check if a link is broken
     */
    protected function checkLink(string $url, string $sourceUrl): void
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'GS-SEO-Audit/1.0'])
                ->head($url);

            if ($response->status() >= 400) {
                $this->brokenLinks[] = [
                    'url' => $url,
                    'status' => $response->status(),
                    'source' => $sourceUrl,
                ];
                $this->issueCount++;
            }
        } catch (\Exception $e) {
            // Don't count timeouts on head requests as errors
            Log::debug("SEO Audit: Could not check link {$url}: {$e->getMessage()}");
        }
    }

    /**
     * Print summary
     */
    protected function printSummary(): void
    {
        if ($this->issueCount === 0 && $this->warningCount === 0) {
            $this->info('✅ All checks passed!');
        } else {
            if ($this->issueCount > 0) {
                $this->error("❌ {$this->issueCount} error(s)");
            }
            if ($this->warningCount > 0) {
                $this->warn("⚠️  {$this->warningCount} warning(s)");
            }
        }
    }
}
