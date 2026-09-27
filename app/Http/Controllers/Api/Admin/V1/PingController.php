<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use SsSystems\Platform\Http\Admin\CapabilityRegistry;
use SsSystems\Platform\Kit;

/**
 * Capability probe. ss-systems' HttpSiteApiClient::capabilities() reads
 * data.domains to decide which admin screens to show for this site — so
 * "domains" is the actual content, not decoration.
 *
 * The list itself is no longer hand-maintained here: every
 * routes/api-admin/*.php file (and, for a domain still registered inline
 * in routes/api.php, the block that defines its routes) calls
 * SsSystems\Platform\Http\Admin\CapabilityRegistry::declare() for the
 * domain(s) it serves, so this always reflects exactly what this site's
 * routes actually back — see that class's docblock and
 * ss-platform-kit's docs/ADMIN-API.md.
 */
class PingController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'site' => config('brand.display_name', config('app.name')),
                // The version of the shared backend package this site runs.
                // The central admin compares it across sites and says so when
                // they disagree — the check that replaces "remember to copy
                // the file to the other repo". class_exists keeps this honest
                // before the package is installed: null reads as "not
                // reporting", never as drift.
                'platform_kit' => class_exists(Kit::class) ? Kit::VERSION : null,
                // Declared by each routes/api-admin/*.php file (or, for a
                // domain still registered inline in routes/api.php, the
                // block that defines its routes) via CapabilityRegistry::
                // declare() — see that class's docblock for the full list
                // this always reproduces (dashboard-stats/projects/tags/
                // testimonials/blog/areas/leads, the ops domains, gsc's
                // legacy-parity extras, area-content/service-content,
                // citations/services/pages).
                'domains' => CapabilityRegistry::domains(),
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
