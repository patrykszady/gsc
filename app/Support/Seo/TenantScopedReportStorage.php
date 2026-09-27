<?php

namespace App\Support\Seo;

use App\Support\SeoStorage;
use SsSystems\Platform\Reports\Contracts\ReportStorage;

/**
 * Adapts this app's own tenant-scoped SeoStorage::path() (static, reads
 * Site::current() fresh on every call — see that class's own docblock for
 * the tenants/{slug}/ prefixing rule and why the default site is left on
 * its legacy, unprefixed paths) to the kit's ReportStorage contract.
 *
 * Bound to SsSystems\Platform\Reports\Contracts\ReportStorage in
 * AppServiceProvider so SsSystems\Platform\Reports\Console\ReportRun::run()
 * resolves generated report paths under the SAME per-tenant prefix every
 * other consumer of SeoStorage already uses — the kit class itself never
 * references Site, Tenancy, or this app's config at all.
 *
 * Holds no state itself, so it is safe to bind as a singleton: every call
 * to path() re-reads Site::current() through SeoStorage at call time, never
 * a value captured when the container resolved this adapter.
 */
final class TenantScopedReportStorage implements ReportStorage
{
    public function path(string $relative): string
    {
        return SeoStorage::path($relative);
    }
}
