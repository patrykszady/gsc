<?php

namespace App\Support\Seo\Reports;

use App\Models\ImagePlatformUpload;
use App\Models\ImageSocialPost;
use App\Models\ProjectImage;
use App\Models\Site;
use App\Support\Seo\CrawlFiles;
use App\Support\SeoStorage;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use SsSystems\Platform\Reports\Contracts\HealthDataReader;

/**
 * Every read HealthReport makes beyond AreaCatalog and QueryMetricsReader —
 * the kit's `health_data` capability. Reproduces SeoHealth's own reads
 * verbatim: image alt coverage, the internal-link audit's last run,
 * GBP activity, the rank tracker, freshness signals, and the health-score
 * ledger at the SAME storage path SeoHealth wrote (SeoReportController's
 * healthSnapshot() and the admin's prior-score chevron read it back).
 */
final class EloquentHealthDataReader implements HealthDataReader
{
    public function imageAltCoverage(): array
    {
        $total = ProjectImage::query()
            ->whereHas('project', fn ($q) => $q->where('is_published', true))
            ->count();

        $withAlt = ProjectImage::query()
            ->whereHas('project', fn ($q) => $q->where('is_published', true))
            ->whereNotNull('alt_text')
            ->where('alt_text', '!=', '')
            ->count();

        return ['total' => $total, 'with_alt' => $withAlt];
    }

    public function internalLinkAuditLastRunAt(): ?\DateTimeInterface
    {
        // Global path — the crawl log is not tenant-prefixed, exactly like
        // the original SeoHealth::lastLogModified('seo-internal-links.log').
        $path = storage_path('logs/seo-internal-links.log');

        return is_file($path) ? Carbon::createFromTimestamp(filemtime($path)) : null;
    }

    public function gbpActivity(): array
    {
        $postsLast30 = ImageSocialPost::query()
            ->where('platform', 'google_business')
            ->where('status', 'published')
            ->where('published_at', '>=', now()->subDays(30))
            ->count();

        $postsLast7 = ImageSocialPost::query()
            ->where('platform', 'google_business')
            ->where('status', 'published')
            ->where('published_at', '>=', now()->subDays(7))
            ->count();

        $everPosted = ImageSocialPost::query()->where('platform', 'google_business')->exists();

        $photosLast90 = null;
        $everUploaded = false;
        if (Schema::hasTable('image_platform_uploads')) {
            $photosLast90 = ImagePlatformUpload::query()
                ->where('platform', 'google_places')
                ->where('uploaded_at', '>=', now()->subDays(90))
                ->count();
            $everUploaded = ImagePlatformUpload::query()->where('platform', 'google_places')->exists();
        }

        return [
            'posts_last_7' => $postsLast7,
            'posts_last_30' => $postsLast30,
            'ever_posted' => $everPosted,
            'photos_last_90' => $photosLast90,
            'ever_uploaded' => $everUploaded,
        ];
    }

    public function latestRankSnapshots(): array
    {
        if (! Schema::hasTable('seo_rank_snapshots')) {
            return [];
        }

        return Tenancy::table('seo_rank_snapshots as r1')
            ->select('r1.engine', 'r1.gsc_position as position')
            ->whereRaw(
                'r1.id = (SELECT MAX(r2.id) FROM seo_rank_snapshots r2 WHERE r2.query = r1.query AND r2.engine = r1.engine'
                .' AND COALESCE(r2.location, "") = COALESCE(r1.location, "") AND (r2.site_id = ? OR r2.site_id IS NULL))',
                [Tenancy::currentId()],
            )
            ->get()
            ->map(fn ($row): array => [
                'engine' => (string) $row->engine,
                'position' => $row->position !== null ? (float) $row->position : null,
            ])
            ->all();
    }

    public function freshnessSignals(): array
    {
        // These are GLOBAL paths for the default site — public/sitemap.xml
        // (via CrawlFiles, already slug-aware) and the shared log files.
        // Non-default tenants read under their own prefix, exactly like the
        // original SeoHealth::scoreFreshness().
        $slug = Site::current()->slug;
        $isDefault = $slug === (string) config('sites.default', 'gsc');
        $prefix = $isDefault ? '' : "tenants/{$slug}/";

        $checks = [
            'sitemap.xml' => CrawlFiles::sitemapPath(),
            'gsc-sync log' => storage_path("logs/{$prefix}seo-gsc-sync.log"),
            'gbp-metrics-sync log' => storage_path("logs/{$prefix}gbp-metrics-sync.log"),
        ];

        $out = [];
        foreach ($checks as $label => $path) {
            $out[$label] = is_file($path) ? Carbon::createFromTimestamp(filemtime($path)) : null;
        }

        return $out;
    }

    public function healthLedger(): array
    {
        $disk = Storage::disk('local');
        $path = SeoStorage::path('reports/health-history.json');

        if (! $disk->exists($path)) {
            return [];
        }

        $decoded = json_decode((string) $disk->get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function putHealthLedger(array $ledger): void
    {
        Storage::disk('local')->put(
            SeoStorage::path('reports/health-history.json'),
            json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }
}
