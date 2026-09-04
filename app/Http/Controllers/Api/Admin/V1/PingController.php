<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Capability probe. ss-systems' HttpSiteApiClient::capabilities() reads
 * data.domains to decide which admin screens to show for this site — so
 * "domains" is the actual content, not decoration.
 */
class PingController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'site' => config('brand.display_name', config('app.name')),
                'domains' => [
                    'dashboard-stats', 'projects', 'tags', 'testimonials', 'areas', 'leads',
                    // Ops domains — the central admin shows these screens
                    // only for sites that declare them (jpeterson doesn't).
                    'landing-pages', 'social-media', 'analytics', 'js-errors', 'seo', 'platforms',
                    // Legacy-parity extras, all gsc-only:
                    // timelapses/before-afters, the areas coverage map,
                    // multi-platform review URLs, testimonial↔project links.
                    'timelapses', 'before-afters', 'image-tags', 'areas-map', 'review-platforms', 'testimonial-projects',
                    // Partner credits on the project form (designer / architect / trade), used by the blog writer.
                    'collaborators',
                    // Citation builder: directory listings driven from the remote browser.
                    'citations',
                ],
                // This site's identity inside the central admin: GS blue is
                // Tailwind's stock sky ramp (accent null = leave it alone,
                // exactly like the legacy admin's config/admin.php), plus the
                // real logo marks. asset() is APP_URL-rooted, so the admin
                // loads the SVGs cross-origin from this site directly.
                'brand' => [
                    'name' => config('brand.name', config('app.name')),
                    'logo' => config('admin.logo') ? asset(config('admin.logo')) : null,
                    'logo_dark' => config('admin.logo_dark') ? asset(config('admin.logo_dark')) : null,
                    'accent' => config('admin.accent'),
                ],
            ],
        ]);
    }
}
