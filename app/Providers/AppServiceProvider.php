<?php

namespace App\Providers;

use App\Http\Controllers\Api\Admin\V1\SeoReportController;
use App\Http\Middleware\ResolveAdminSite;
use App\Http\Middleware\ResolveSite;
use App\Jobs\RunCitationsBatch;
use App\Models\AreaServed;
use App\Models\BlogPost;
use App\Models\Citation;
use App\Models\ClientError;
use App\Models\GscCoverageState;
use App\Models\GscCoverageStateHistory;
use App\Models\GscRichResultIssue;
use App\Models\LandingPage;
use App\Models\OAuthToken;
use App\Models\PlatformSetting;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\Service;
use App\Models\Site;
use App\Models\Testimonial;
use App\Models\TrackedEvent;
use App\Observers\AreaServedObserver;
use App\Observers\BlogPostObserver;
use App\Observers\ProjectImageObserver;
use App\Observers\ProjectObserver;
use App\Observers\TestimonialObserver;
use App\Services\BingWebmasterService;
use App\Services\Citations\CitationBatchRunner;
use App\Services\GoogleBusinessProfileService;
use App\Services\GoogleSearchConsoleService;
use App\Services\Social\ImageSocialPostStats;
use App\Services\Social\SocialAutomationSettingsStore;
use App\Services\Social\SocialPlatformAvailability;
use App\Support\Areas\RetiredAreaRedirect;
use App\Support\Citations\SiteKnownListingsSource;
use App\Support\Citations\SiteMailboxFactory;
use App\Support\Citations\SitePendingCitationRepository;
use App\Support\PublicFeeds;
use App\Support\Seo\BingCoverageUrls;
use App\Support\Seo\BingCoverageWriter;
use App\Support\Seo\BingSettings;
use App\Support\Seo\BingWriter;
use App\Support\Seo\ClaritySettings;
use App\Support\Seo\DataForSeoSettings;
use App\Support\Seo\Faq\ConfigFaqCatalog;
use App\Support\Seo\FaqBank\GscFaqBankSource;
use App\Support\Seo\GscClaritySiteHosts;
use App\Support\Seo\GscMapPackContext;
use App\Support\Seo\GscOwnReviews;
use App\Support\Seo\GscPageSpeedUrls;
use App\Support\Seo\GscSeoProfile;
use App\Support\Seo\IndexNowSiteKey;
use App\Support\Seo\Inspection\FileSitemapSource;
use App\Support\Seo\Inspection\TrackedPathsFromModel;
use App\Support\Seo\PsiSettings;
use App\Support\SEO\RecrawlNudger;
use App\Support\Seo\Reports\ConfigSiteIdentity;
use App\Support\Seo\Reports\EloquentAreaCatalog;
use App\Support\Seo\Reports\EloquentHealthDataReader;
use App\Support\Seo\Reports\EloquentQueryMetricsReader;
use App\Support\Seo\Reports\HttpPageFetcher;
use App\Support\Seo\Reports\HttpSiteCatalog;
use App\Support\Seo\Reports\LaravelSimpleCache;
use App\Support\Seo\SearchConsoleWriter as SiteSearchConsoleWriter;
use App\Support\SEO\SEOBuilder;
use App\Support\Seo\SeoReportSink;
use App\Support\Seo\TenantScopedReportStorage;
use App\Support\Social\EloquentRankedTowns;
use App\Support\Social\SeoIntelTrendsIntel;
use App\Support\Tenancy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Blaze\Blaze;
use Livewire\Livewire;
use Opcodes\LogViewer\Facades\LogViewer;
use Psr\SimpleCache\CacheInterface;
use SsSystems\Platform\Citations\CitationsAdminActions;
use SsSystems\Platform\Citations\Contracts\CitationSession;
use SsSystems\Platform\Citations\Contracts\Mailbox as CitationMailbox;
use SsSystems\Platform\Citations\Contracts\PendingCitationRepository;
use SsSystems\Platform\Citations\KnownListingsReconciler;
use SsSystems\Platform\Citations\RemoteBrowserSession;
use SsSystems\Platform\Citations\VerificationInbox;
use SsSystems\Platform\Google\Adapters\EloquentTokenStore;
use SsSystems\Platform\Google\Adapters\PlatformSettingSharedClient;
use SsSystems\Platform\Google\BusinessProfile\Adapters\PlatformSettingListingStore;
use SsSystems\Platform\Google\BusinessProfile\Client as GbpClient;
use SsSystems\Platform\Google\BusinessProfile\Contracts\ListingStore;
use SsSystems\Platform\Google\Contracts\TokenStore;
use SsSystems\Platform\Google\OAuthClient;
use SsSystems\Platform\Pulse\BeaconController;
use SsSystems\Platform\Pulse\Recorder;
use SsSystems\Platform\Pulse\SnapshotBuilder;
use SsSystems\Platform\Pulse\Storage\DatabaseTableStorage;
use SsSystems\Platform\Reports\Contracts\AreaCatalog;
use SsSystems\Platform\Reports\Contracts\ClarityMetricsReader;
use SsSystems\Platform\Reports\Contracts\HealthDataReader;
use SsSystems\Platform\Reports\Contracts\PageFetcher;
use SsSystems\Platform\Reports\Contracts\PsiSnapshotReader;
use SsSystems\Platform\Reports\Contracts\QueryMetricsReader;
use SsSystems\Platform\Reports\Contracts\ReportStorage;
use SsSystems\Platform\Reports\Contracts\SiteCatalog;
use SsSystems\Platform\Reports\Contracts\SiteIdentity;
use SsSystems\Platform\Seo\AiTraffic\AiTrafficStore;
use SsSystems\Platform\Seo\Bing\BingWebmasterApi;
use SsSystems\Platform\Seo\Bing\BingWebmasterClient;
use SsSystems\Platform\Seo\Bing\BingWriter as KitBingWriter;
use SsSystems\Platform\Seo\Bing\Contracts\BingCoverageClient;
use SsSystems\Platform\Seo\Bing\Contracts\BingCoverageUrls as KitBingCoverageUrls;
use SsSystems\Platform\Seo\Bing\Contracts\BingCoverageWriter as KitBingCoverageWriter;
use SsSystems\Platform\Seo\Clarity\ClarityClient;
use SsSystems\Platform\Seo\Clarity\ClarityMetricsStore;
use SsSystems\Platform\Seo\Clarity\Contracts\ClarityCredentials;
use SsSystems\Platform\Seo\Clarity\Contracts\ClaritySiteHosts;
use SsSystems\Platform\Seo\Clarity\StoreClarityMetricsReader;
use SsSystems\Platform\Seo\Contracts\SeoSiteProfile;
use SsSystems\Platform\Seo\DataForSeo\Contracts\DataForSeoApi;
use SsSystems\Platform\Seo\DataForSeo\Contracts\DataForSeoCredentials;
use SsSystems\Platform\Seo\DataForSeo\DataForSeoClient;
use SsSystems\Platform\Seo\Faq\Bank\Contracts\FaqBankSource;
use SsSystems\Platform\Seo\Faq\Bank\Contracts\TextGenerator;
use SsSystems\Platform\Seo\Faq\Bank\FaqBankStore;
use SsSystems\Platform\Seo\Faq\Bank\Generators\FirstConfiguredTextGenerator;
use SsSystems\Platform\Seo\Faq\Bank\Generators\GeminiTextGenerator;
use SsSystems\Platform\Seo\Faq\Bank\Generators\OpenAiTextGenerator;
use SsSystems\Platform\Seo\Faq\Contracts\FaqCatalog;
use SsSystems\Platform\Seo\IndexNow\Contracts\IndexNowKey;
use SsSystems\Platform\Seo\IndexNow\IndexNowLedger;
use SsSystems\Platform\Seo\Inspection\Contracts\CoverageStore as CoverageStoreContract;
use SsSystems\Platform\Seo\Inspection\Contracts\SitemapSource as SitemapSourceContract;
use SsSystems\Platform\Seo\Inspection\Contracts\TrackedPaths as TrackedPathsContract;
use SsSystems\Platform\Seo\Inspection\Contracts\UrlInspector as UrlInspectorContract;
use SsSystems\Platform\Seo\Inspection\EloquentCoverageStore;
use SsSystems\Platform\Seo\Inspection\SearchConsoleUrlInspector;
use SsSystems\Platform\Seo\Inspection\UrlInspectionQuota as KitUrlInspectionQuota;
use SsSystems\Platform\Seo\Intel\Contracts\OwnReviewsSource;
use SsSystems\Platform\Seo\Intel\IntelRunner;
use SsSystems\Platform\Seo\Intel\IntelSettings;
use SsSystems\Platform\Seo\Intel\IntelStore;
use SsSystems\Platform\Seo\Intel\Sources\AiOptimizationSource;
use SsSystems\Platform\Seo\Intel\Sources\BacklinksSource;
use SsSystems\Platform\Seo\Intel\Sources\BusinessDataSource;
use SsSystems\Platform\Seo\Intel\Sources\ContentAnalysisSource;
use SsSystems\Platform\Seo\Intel\Sources\DomainAnalyticsSource;
use SsSystems\Platform\Seo\Intel\Sources\LabsSource;
use SsSystems\Platform\Seo\Intel\Sources\OnPageSource;
use SsSystems\Platform\Seo\Intel\Sources\SerpSource;
use SsSystems\Platform\Seo\Intel\Sources\TrendsSource;
use SsSystems\Platform\Seo\KeywordResearch\Console\KeywordResearchCommand;
use SsSystems\Platform\Seo\KeywordResearch\KeywordResearchSnapshot;
use SsSystems\Platform\Seo\MapPack\Contracts\MapPackContext;
use SsSystems\Platform\Seo\MapPack\MapPackStore;
use SsSystems\Platform\Seo\PageSpeed\Contracts\PageSpeedCredentials;
use SsSystems\Platform\Seo\PageSpeed\Contracts\PageSpeedUrlSource;
use SsSystems\Platform\Seo\PageSpeed\PsiSnapshotStore;
use SsSystems\Platform\Seo\PageSpeed\SitemapUrlSource;
use SsSystems\Platform\Seo\Rankings\RankingsSnapshot;
use SsSystems\Platform\Seo\Rankings\RankSnapshotStore;
use SsSystems\Platform\Seo\Rankings\TrackedQueries;
use SsSystems\Platform\Seo\SearchConsoleClient;
use SsSystems\Platform\Seo\SearchConsoleSyncClient;
use SsSystems\Platform\Seo\SearchConsoleWriter as SearchConsoleWriterContract;
use SsSystems\Platform\Seo\Snapshot\Contracts\SeoSnapshotSource;
use SsSystems\Platform\Seo\Snapshot\SeoSnapshotStore;
use SsSystems\Platform\Seo\Visibility\AiMentionStore;
use SsSystems\Platform\Seo\Visibility\BacklinkProspectStore;
use SsSystems\Platform\Seo\Visibility\Console\CompetitorBrandTrackCommand;
use SsSystems\Platform\Seo\Visibility\Contracts\ReportSink;
use SsSystems\Platform\Seo\Visibility\DomainOverviewStore;
use SsSystems\Platform\Social\Adapters\OAuthTokenCredentialStore;
use SsSystems\Platform\Social\Contracts\MetaCredentialStore;
use SsSystems\Platform\Social\Contracts\PlatformAvailability as SocialPlatformAvailabilityContract;
use SsSystems\Platform\Social\Contracts\PublishedPostStats as SocialPublishedPostStats;
use SsSystems\Platform\Social\Contracts\RankedTowns;
use SsSystems\Platform\Social\Contracts\SettingsStore as SocialSettingsStore;
use SsSystems\Platform\Social\Contracts\TrendsIntel;
use SsSystems\Platform\Telemetry\Adapters\EloquentClientErrorWriter;
use SsSystems\Platform\Telemetry\Adapters\EloquentTrackedEventWriter;
use SsSystems\Platform\Telemetry\Contracts\ClientErrorWriter;
use SsSystems\Platform\Telemetry\Contracts\TrackedEventWriter;
use SsSystems\Platform\Traffic\Collectors\AccessLogCollector;
use SsSystems\Platform\Traffic\Collectors\BingTotalsCollector;
use SsSystems\Platform\Traffic\Collectors\BusinessProfileCollector;
use SsSystems\Platform\Traffic\Collectors\ClarityCollector;
use SsSystems\Platform\Traffic\Collectors\LeadsCollector;
use SsSystems\Platform\Traffic\Collectors\PulseEventsCollector;
use SsSystems\Platform\Traffic\Collectors\SearchConsoleCollector;
use SsSystems\Platform\Traffic\Collectors\TrackedEventsCollector;
use SsSystems\Platform\Traffic\TrafficCollectors;
use SsSystems\Platform\Traffic\TrafficDays;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Per-request SEO state accumulator (consumed by app layout).
        $this->app->singleton(SEOBuilder::class);

        // The shared kit's Search Console interfaces, bound to this site's
        // own implementations: GoogleSearchConsoleService already carries
        // everything SearchConsoleClient needs (SitemapStatus's resolution
        // of it keeps working unchanged) and now everything the fuller
        // SearchConsoleSyncClient needs too, and
        // SsSystems\Platform\Seo\SearchConsoleSync::run() writes through
        // App\Support\Seo\SearchConsoleWriter — see
        // App\Console\Commands\SyncGoogleSearchConsole for the command that
        // drives them.
        $this->app->bind(SearchConsoleClient::class, GoogleSearchConsoleService::class);
        $this->app->bind(SearchConsoleSyncClient::class, GoogleSearchConsoleService::class);
        $this->app->bind(SearchConsoleWriterContract::class, SiteSearchConsoleWriter::class);
        // Bing Webmaster Tools, the same way (kit 0.8.0): the kit's sync, this
        // site's writer, the client built from this site's key.
        $this->app->bind(KitBingWriter::class, BingWriter::class);
        $this->app->bind(BingWebmasterClient::class, BingWebmasterService::class);
        // seo:bing-submit-sitemaps (kit 0.19.4) type-hints the concrete
        // BingWebmasterApi class directly rather than the BingWebmasterClient
        // interface above (BingWebmasterService wraps one internally for
        // seo:bing-sync) — built from this site's own BingSettings the same
        // way jpeterson-design's AppServiceProvider binds it.
        $this->app->bind(BingWebmasterApi::class, fn ($app) => new BingWebmasterApi(
            $app->make(BingSettings::class)->apiKey(),
            $app->make(BingSettings::class)->siteUrl(),
            $app->make(HttpFactory::class),
        ));
        // Bing coverage (kit 0.22.0, 2026-11-10): what Bing's index holds of
        // the site, for the hub's Bing card. BingCoverageClient resolves
        // through the SAME BingWebmasterApi factory bound just above — same
        // key, same site URL — rather than a second construction of it, so
        // the submission and coverage sides can never drift onto different
        // properties. This site's own writer and sitemap URL list are bound
        // the same way the Search Console ones are above.
        $this->app->bind(BingCoverageClient::class, fn ($app) => $app->make(BingWebmasterApi::class));
        $this->app->bind(KitBingCoverageWriter::class, BingCoverageWriter::class);
        $this->app->bind(KitBingCoverageUrls::class, BingCoverageUrls::class);
        // The citation builder's remote-browser session (kit 0.11.0, ported
        // verbatim from this file's own former App\Services\Citations\
        // CitationSessionService — see vendor/ss-systems/platform-kit's
        // Citations\RemoteBrowserSession docblock). gsc has the real Xvfb/
        // Chromium/x11vnc pipeline, unlike hive2025's UnavailableSession.
        $this->app->bind(CitationSession::class, RemoteBrowserSession::class);
        // The citations verification inbox and link-check sweep (kit 0.13.0,
        // ported from this file's own former App\Services\Citations\
        // VerificationInbox and CitationsControl's `check` sub-action — see
        // vendor/ss-systems/platform-kit's Citations\VerificationInbox
        // docblock). SiteMailboxFactory reads the CITATIONS_M365_*/IMAP
        // config the site's own class used to read directly; the pending-
        // citation and link-check repositories are where the site_id scope
        // every other citations query already carries now lives.
        $this->app->bind(CitationMailbox::class, fn () => SiteMailboxFactory::current());
        $this->app->bind(PendingCitationRepository::class, SitePendingCitationRepository::class);
        // The Citations admin API's own service (kit 0.13.0, ported from
        // this file's own former Api/Admin/V1/CitationsController — see
        // vendor/ss-systems/platform-kit's Citations\CitationsAdminActions
        // docblock). Six seams: the same $baseQuery scoping as
        // CitationBatchRunner's own binding, the signed-viewer-route's
        // extra 'site' param (gsc's route has a {site} segment; see
        // routes/platforms-viewer.php), and this app's own
        // RunCitationsBatch job for the one thing the service cannot do
        // itself (queueing a site-owned Job class). NOT a singleton, on
        // purpose: freezing this would freeze the CitationSession/
        // CitationBatchRunner/VerificationInbox instances it resolves
        // ONCE too, and a queue worker or the parallel test suite (see
        // CitationsBatchSyncIsolationTest's own note) can resolve this for
        // more than one tenant inside one process — every other citations
        // binding above is `bind`, not `singleton`, for the same reason.
        $this->app->bind(CitationsAdminActions::class, fn ($app) => new CitationsAdminActions(
            $app->make(CitationSession::class),
            $app->make(CitationBatchRunner::class),
            $app->make(VerificationInbox::class),
            new KnownListingsReconciler(new SiteKnownListingsSource, fn () => Citation::query()->where('site_id', Site::current()?->id), 'Platforms'),
            fn () => Citation::query()->where('site_id', Site::current()?->id),
            fn () => ['site' => Site::current()->primary_host, 'provider' => 'citations'],
            fn (array $slugs) => RunCitationsBatch::dispatch($slugs),
        ));

        // The shared kit's AutomationSettingsService (kit 0.13.0, ported
        // verbatim from this file's own former App\Services\Social\
        // AutomationSettingsService — see App\Services\Social\
        // AutomationSettingsService's own docblock for the thin subclass
        // that re-derives Site::current()->slug on every resolution).
        // Plain binds, not singletons: each adapter reads the current
        // tenant's rows/services fresh, same as the class they replace.
        $this->app->bind(SocialSettingsStore::class, SocialAutomationSettingsStore::class);
        $this->app->bind(SocialPlatformAvailabilityContract::class, SocialPlatformAvailability::class);
        $this->app->bind(SocialPublishedPostStats::class, ImageSocialPostStats::class);

        // Meta (Facebook Page + Instagram Business) credential storage (kit
        // 0.13.0): gsc's own oauth_tokens table, wrapped by class-string so
        // OAuthToken's own `use BelongsToSite;` tenant scope keeps applying
        // to every read/write MetaGraphClient makes through it. Not a
        // singleton: each resolution re-reads services.meta.* fresh, same
        // as the pre-port class did on every call.
        $this->app->bind(MetaCredentialStore::class, fn () => new OAuthTokenCredentialStore(
            tokenModel: OAuthToken::class,
            provider: 'meta',
            envFallback: [
                'token' => trim((string) config('services.meta.page_access_token', '')) ?: null,
                'page_id' => trim((string) config('services.meta.facebook_page_id', '')) ?: null,
                'ig_id' => trim((string) config('services.meta.instagram_account_id', '')) ?: null,
                'page_name' => null,
                'ig_username' => null,
            ],
        ));

        // The shared kit's ten SEO report generators (SsSystems\Platform\
        // Reports\*, ported verbatim from this app's own app/Console/
        // Commands/Seo*.php — see vendor/ss-systems/platform-kit/docs/
        // REPORTS-PORTING.md) read only these nine small interfaces, never
        // Eloquent/Http/Storage/config() directly. This site binds every
        // one of them (App\Support\Seo\Reports\ReportCapabilities::
        // provided() names the same nine keys), so all ten reports are
        // available here; a site that cannot provide one (e.g.
        // jpeterson-design's AreaCatalog) simply never binds it and that
        // report reads as "not available" instead of throwing.
        $this->app->bind(QueryMetricsReader::class, EloquentQueryMetricsReader::class);
        // PsiSnapshotReader is bound in registerSeoTools() instead, to the
        // kit's own tenant-scoped PsiSnapshotStore (kit 0.19.3) — see that
        // method's docblock; App\Support\Seo\Reports\EloquentPsiSnapshotReader
        // is gone.
        $this->app->bind(PageFetcher::class, HttpPageFetcher::class);
        $this->app->bind(SiteCatalog::class, HttpSiteCatalog::class);
        $this->app->bind(SiteIdentity::class, ConfigSiteIdentity::class);
        $this->app->bind(AreaCatalog::class, EloquentAreaCatalog::class);
        $this->app->bind(HealthDataReader::class, EloquentHealthDataReader::class);
        // ClarityMetricsReader is bound in registerSeoTools() instead, to the
        // kit's own tenant-scoped Clarity module (kit 0.20.0) — see that
        // method's docblock; App\Support\Seo\Reports\EloquentClarityMetricsReader
        // is gone.
        $this->app->bind(CacheInterface::class, LaravelSimpleCache::class);
        // /geo/answers.json's Q&A list (kit 0.13.0) — the fixed
        // config/geo-answers.php list first, no Service/Area FAQ merge; since
        // kit 0.24.0 the FAQ bank below follows it (FaqBankCatalog::merge).
        $this->app->bind(FaqCatalog::class, ConfigFaqCatalog::class);

        // The FAQ bank (kit 0.24.0, 2026-11-10): seo:faq-bank derives many
        // more answers from this site's own pages for /geo/answers.json and
        // llms-full.txt. The source is this site's sitemap URL set labelled
        // by path (GscFaqBankSource); the generator is Gemini — the key the
        // area descriptions already use — with OpenAI behind it, both over
        // the Http facade's factory so a test's Http::fake() applies; the
        // store is tenant-scoped like every store in registerSeoTools(),
        // built with an explicit `new` in a singleton: the kit types
        // $tenantId ?\Closure, a class, so when()->needs('$tenantId') would
        // be skipped and null passed through (the same trap that crashed
        // competitor-brand-track nightly in November).
        $this->app->bind(FaqBankSource::class, GscFaqBankSource::class);
        $this->app->bind(TextGenerator::class, fn ($app) => new FirstConfiguredTextGenerator([
            new GeminiTextGenerator(
                config('services.google.gemini_api_key'),
                (string) config('services.google.gemini_model', 'gemini-2.5-flash'),
                $app->make(HttpFactory::class),
            ),
            new OpenAiTextGenerator(
                config('services.openai.api_key'),
                (string) config('services.openai.content_model', 'gpt-4.1-mini'),
                $app->make(HttpFactory::class),
            ),
        ]));
        $this->app->singleton(FaqBankStore::class, fn () => new FaqBankStore(
            DB::connection(), tenantColumn: 'site_id', tenantId: fn () => Tenancy::currentId(),
        ));

        // GbpPostTheme (kit 0.13.0, moved verbatim from this file's own former
        // App\Services\Social\GbpPostTheme — jpeterson's byte-identical copy
        // was dead code and was deleted rather than ported): a NEW pair of
        // contracts, deliberately not the AreaCatalog above (different shape
        // and purpose — see RankedTowns' own docblock).
        $this->app->bind(RankedTowns::class, EloquentRankedTowns::class);
        $this->app->bind(TrendsIntel::class, SeoIntelTrendsIntel::class);

        // SsSystems\Platform\Reports\Console\ReportRun (kit 0.12.0) — the
        // only site of the four with tenants to keep apart, so the only one
        // that MUST bind this itself rather than fall back to the kit's
        // IdentityReportStorage default (see that class's own docblock).
        $this->app->bind(ReportStorage::class, TenantScopedReportStorage::class);

        // The kit's URL-Inspection sweep (SsSystems\Platform\Seo\Inspection\
        // UrlInspectionSweep — see seo:gsc-inspect-bulk, now a thin wrapper
        // over it, and docs/INSPECTION-SWEEP.md in the kit's source repo)
        // reads only these four small interfaces, never Eloquent/the
        // filesystem/config() directly.
        $this->app->bind(UrlInspectorContract::class, SearchConsoleUrlInspector::class);
        $this->app->bind(SitemapSourceContract::class, FileSitemapSource::class);
        // This site is the one adopter with a real gsc_coverage_state_history
        // table, so it is the only one to pass a $historyModel — every write
        // through it stays wrapped in a try/catch inside the kit class itself
        // (bookkeeping must never fail the sweep), same as this file's own
        // former local adapter.
        $this->app->bind(CoverageStoreContract::class, fn () => new EloquentCoverageStore(
            GscCoverageState::class,
            GscRichResultIssue::class,
            GscCoverageStateHistory::class,
        ));
        $this->app->bind(TrackedPathsContract::class, TrackedPathsFromModel::class);

        // Kit 0.14.0 Telemetry: TrackEventController/ClientErrorController
        // now delegate to the kit's TrackEventIngest/ClientErrorIngest,
        // which only ever call these two contracts — this app's own
        // TrackedEvent/ClientError models (and their `use BelongsToSite;`
        // tenant scope) stay exactly as they were, just wrapped.
        $this->app->bind(TrackedEventWriter::class, fn () => new EloquentTrackedEventWriter(TrackedEvent::class));
        $this->app->bind(ClientErrorWriter::class, fn () => new EloquentClientErrorWriter(ClientError::class));

        // A factory, not a singleton: the key prefix is baked in at
        // resolution time from the CURRENT tenant (Tenancy::cacheKey()), and
        // a queue worker or the parallel test suite can resolve this for more
        // than one site inside one process — a singleton would freeze the
        // first tenant's prefix for every one after it. Same daily/per-minute
        // limits and the same per-tenant key shape
        // (Tenancy::cacheKey('gsc.url-inspection'), bare for the default
        // site) as the retired App\Support\Seo\UrlInspectionQuota static
        // class this replaces — GscErrorController now reads this instance
        // instead of that class's static methods.
        $this->app->bind(KitUrlInspectionQuota::class, fn ($app) => new KitUrlInspectionQuota(
            cache: $app->make(CacheInterface::class),
            keyPrefix: Tenancy::cacheKey('gsc.url-inspection'),
            dailyLimitValue: (int) config('services.google.search_console.inspection_daily_quota', 2000),
            perMinuteLimitValue: (int) config('services.google.search_console.inspection_per_minute_quota', 600),
        ));

        // The stored SEO snapshot (kit 0.26.0, 2026-11-11): seo:snapshot-store
        // builds it through SeoReportController, and the store's keys carry
        // the CURRENT tenant's prefix — a factory, for the same reason as
        // the quota above. Only the gsc tenant's is ever built (the admin API
        // is pinned to it, App\Http\Middleware\PinAdminApiTenant).
        $this->app->bind(SeoSnapshotSource::class, SeoReportController::class);
        $this->app->bind(SeoSnapshotStore::class, fn ($app) => new SeoSnapshotStore(
            $app->make('cache.store'),
            Tenancy::cacheKey('admin.seo-snapshot'),
        ));

        // Site Pulse (SsSystems\Platform\Pulse, kit 0.10.0) — first-party
        // usage telemetry feeding ss-systems' seo/snapshot 'pulse' card
        // (App\Http\Controllers\Api\Admin\V1\SeoReportController::
        // pulseSnapshot()). Both the Recorder (writes, from the /t beacon)
        // and the SnapshotBuilder (reads, per admin request) get their OWN
        // DatabaseTableStorage over the same 'site_events' table, scoped by
        // a CLOSURE reading Tenancy::currentId() at call time — not a
        // captured value — since these are singletons a queue worker or the
        // parallel test suite can resolve once and then use across more
        // than one tenant via Tenancy::for()/::each().
        $this->app->singleton(Recorder::class, function () {
            return new Recorder(
                storage: new DatabaseTableStorage(
                    DB::connection(), tenantColumn: 'site_id', tenantId: fn () => Tenancy::currentId(),
                ),
                events: ['page', 'gallery', 'before_after', 'call', 'email', 'jserr'],
            );
        });

        $this->app->singleton(SnapshotBuilder::class, function () {
            return new SnapshotBuilder(
                storage: new DatabaseTableStorage(
                    DB::connection(), tenantColumn: 'site_id', tenantId: fn () => Tenancy::currentId(),
                ),
                events: ['page', 'gallery', 'before_after', 'call', 'email', 'jserr'],
                options: [
                    'timezone' => 'America/Chicago',
                    // No 'search_event': neither gsc nor jpeterson has a
                    // search feature, so searches/searched_cities/filters
                    // are correctly OMITTED from the snapshot, not zeroed.
                    'feature_labels' => [
                        'gallery' => 'Galleries opened',
                        'before_after' => 'Before/after used',
                    ],
                ],
            );
        });

        // BeaconController itself needs the app key at construction time —
        // built here rather than left to autowiring, which has no way to
        // supply the plain string $appKey constructor argument.
        $this->app->bind(BeaconController::class, fn ($app) => new BeaconController(
            $app->make(Recorder::class),
            (string) config('app.key'),
        ));

        $this->registerGoogle();
        $this->registerTraffic();
        $this->registerSeoTools();
    }

    /**
     * One Google (kit 0.14.0, owner's call 2026-09-28; the kit's
     * docs/GOOGLE.md): every tenant signs in through the ONE shared OAuth
     * client and runs the kit's one Business Profile client. All binds, never
     * singletons — a queue worker or Tenancy::for() resolves these for more
     * than one tenant in one process, and each resolution reads config and
     * the current tenant fresh:
     *
     * - OAuthClient: THE shared client, from services.google.oauth
     *   (GOOGLE_OAUTH_CLIENT_ID/_SECRET), with the kit's read-only 0.14.x
     *   fallback to the old business_profile pair. No platform_settings
     *   overlay: App\Support\GoogleOAuthApp is gone.
     * - TokenStore: oauth_tokens through OAuthToken, whose BelongsToSite
     *   scope keeps every read and write to Site::current(); each grant now
     *   records the client that issued it in metadata.oauth_client_id.
     * - ListingStore: the gbp.* platform_settings keys (PlatformSetting is
     *   BelongsToSite too), so the listing already chosen reads back as is.
     * - GoogleBusinessProfileService (and the kit's Client, which resolves to
     *   it): gs.construction's business on top of the kit's client.
     *
     * The env refresh token and listing/place ids are gs.construction's own
     * and are passed for the default site only — another tenant must never
     * post to GS's listing with GS's grant (the old service fell back to both
     * for every tenant).
     */
    protected function registerGoogle(): void
    {
        // The one Google client as ss.systems provisions it (kit 0.15.0):
        // stored in platform_settings and preferred over any .env value.
        $this->app->bind(PlatformSettingSharedClient::class, fn () => new PlatformSettingSharedClient(PlatformSetting::class));
        $this->app->bind(OAuthClient::class, fn ($app) => OAuthClient::fromConfig(
            (array) config('services.google'),
            $app->make(HttpFactory::class),
            $app->make(PlatformSettingSharedClient::class)->get(),
        ));

        $this->app->bind(TokenStore::class, fn () => new EloquentTokenStore(OAuthToken::class));

        $this->app->bind(ListingStore::class, function () {
            $own = self::isDefaultSite();

            return new PlatformSettingListingStore(
                PlatformSetting::class,
                $own ? config('services.google.business_profile.account_id') : null,
                $own ? config('services.google.business_profile.location_id') : null,
                $own ? config('services.google.business_profile.place_id') : null,
            );
        });

        $this->app->bind(GoogleBusinessProfileService::class, fn ($app) => new GoogleBusinessProfileService(
            $app->make(OAuthClient::class),
            $app->make(TokenStore::class),
            $app->make(CacheInterface::class),
            $app->make(HttpFactory::class),
            Log::channel('gbp'),
            self::isDefaultSite() ? config('services.google.business_profile.refresh_token') : null,
            $app->make(ListingStore::class),
        ));

        $this->app->bind(GbpClient::class, fn ($app) => $app->make(GoogleBusinessProfileService::class));
    }

    /** Whether the current tenant is the deployment's own (gs.construction) — the owner of every GOOGLE_BUSINESS_PROFILE_* env value. */
    protected static function isDefaultSite(): bool
    {
        return Site::current()->slug === (string) config('sites.default', 'gsc');
    }

    /**
     * The kit's SEO tools (0.19.1, 2026-11-01, phase 3 of the port — see
     * J. Peterson Design's identical wiring, done the same day): this
     * site's own DataForSEO credential/client, site profile (read straight
     * from config/seo.php — the same keys the retired TrackRankings/
     * SeoKeywordResearch/SeoDomainOverview/SeoMapPackGrid/SeoAiMentions
     * commands read directly), map-pack context, and report sink — plus
     * every tenant-scoped store/snapshot the tools need. gs.construction is
     * multi-tenant (site_id on every row), so every store below passes
     * `tenantColumn: 'site_id'` and a CLOSURE reading Tenancy::currentId()
     * at call time — never a captured value — the same convention as
     * registerTraffic()'s collectors and the Pulse Recorder/SnapshotBuilder
     * above. KeywordResearchCommand and CompetitorBrandTrackCommand build
     * their own query inline from constructor arguments rather than taking
     * an injected store, so they are built here with the same tenant
     * column/closure. Not through when()->needs('$tenantId'): the kit types
     * that argument ?\Closure, a class, so the container skips primitive
     * contextual bindings for it and quietly passed null — both commands
     * crashed with "Value of type null is not callable" on every run
     * (competitor-brand-track daily from 2026-11-02 until 2026-11-08).
     *
     * Change intel (kit 0.19.2, 2026-11-01): the nine DataForSEO families
     * this site runs, each built below with this site's own DataForSeoApi/
     * IntelStore/SeoSiteProfile/IntelSettings plus the tenant scope
     * (`competitorDomains()`'s map_pack_competitors/seo_rank_snapshots reads
     * must stay site-scoped, same as every store above) — never resolved
     * generically through the container, since BusinessDataSource alone
     * takes two extra optional dependencies (the ListingStore bound above
     * and GscOwnReviews). BuildsSeoToolBlocks's 'intel' entry now serves the
     * kit's own IntelSnapshot (SeoReportController no longer overrides it).
     *
     * PageSpeed Insights (kit 0.19.3, 2026-11-01): the kit's own
     * seo:psi-sync replaces this site's former App\Console\Commands\
     * SyncPageSpeedInsights — PsiSettings now gates it (PageSpeedCredentials)
     * and PsiSnapshotStore is the one `psi_snapshots` table this site has
     * ever written (16,985+ existing rows, already site_id-scoped by the
     * 2026-07-30 add_site_id_to_seo_and_analytics_tables migration — no new
     * migration needed, its columns already match the kit's stub exactly).
     * GscPageSpeedUrls serves the pinned config('seo.psi_urls') list first,
     * topped up by the kit's own SitemapUrlSource reading this site's
     * sitemap.xml — gsc's own top-Search-Console-pages expansion and
     * dead-URL probe/circuit-breaker have no kit equivalent and are gone
     * with the retired command (see GscPageSpeedUrls's own docblock).
     *
     * Microsoft Clarity (kit 0.20.0, 2026-11-02): the kit's own
     * SsSystems\Platform\Seo\Clarity module — a port of this site's own
     * App\Services\MicrosoftClarityService, the most complete of the five
     * copies the sites carried — replaces it, App\Console\Commands\
     * SyncMicrosoftClarity and App\Support\Seo\Reports\
     * EloquentClarityMetricsReader, all three now gone. ClarityMetricsStore
     * writes the same clarity_daily_metrics/clarity_page_metrics tables this
     * site always wrote (already site_id-scoped; no new migration needed,
     * their columns already match the kit's stubs exactly); ClarityClient
     * keeps the exact parsing (dailySnapshot()/pageMetrics()/
     * normalisePath()) App\Support\Seo\FrustratedPages now delegates to.
     * GscClaritySiteHosts rebuilds Site::current()'s hosts exactly as the
     * retired service's siteHosts() did.
     *
     * seo:map-pack-competitors is now the kit's own MapPackCompetitorsCommand
     * (kit 0.19.3), registered directly in bootstrap/app.php — it reads a
     * competitor's homepage through the kit's own CompetitorSiteReader
     * (SeoSiteProfile::services()/towns()/competitorExclusions(), already
     * bound above), so this site's former App\Console\Commands\
     * SeoMapPackCompetitors and App\Services\Seo\CompetitorSiteFetcher are
     * gone; MapPackStore below now serves all three of seo:map-pack-grid,
     * seo:map-pack-competitors and the report snapshot.
     */
    protected function registerSeoTools(): void
    {
        $tenant = fn () => Tenancy::currentId();

        $this->app->bind(DataForSeoCredentials::class, DataForSeoSettings::class);
        $this->app->singleton(DataForSeoApi::class, fn ($app) => new DataForSeoClient(
            $app->make(DataForSeoCredentials::class),
            $app->make(HttpFactory::class),
            (float) config('seo.dataforseo.min_balance', 10.0),
        ));
        $this->app->bind(SeoSiteProfile::class, GscSeoProfile::class);
        $this->app->bind(ReportSink::class, SeoReportSink::class);
        $this->app->bind(MapPackContext::class, GscMapPackContext::class);

        $this->app->singleton(RankSnapshotStore::class, fn () => new RankSnapshotStore(
            DB::connection(), tenantColumn: 'site_id', tenantId: $tenant,
        ));
        $this->app->singleton(TrackedQueries::class, fn () => new TrackedQueries(
            DB::connection(), tenantColumn: 'site_id', tenantId: $tenant,
        ));
        $this->app->singleton(RankingsSnapshot::class, fn ($app) => new RankingsSnapshot(
            $app->make(RankSnapshotStore::class), DB::connection(), tenantColumn: 'site_id', tenantId: $tenant,
        ));

        $this->app->singleton(KeywordResearchSnapshot::class, fn () => new KeywordResearchSnapshot(
            DB::connection(), tenantColumn: 'site_id', tenantId: $tenant,
        ));
        $this->app->bind(KeywordResearchCommand::class, fn ($app) => new KeywordResearchCommand(
            $app->make(DataForSeoApi::class), $app->make(SeoSiteProfile::class), DB::connection(),
            tenantColumn: 'site_id', tenantId: $tenant,
        ));

        $this->app->singleton(DomainOverviewStore::class, fn () => new DomainOverviewStore(
            DB::connection(), tenantColumn: 'site_id', tenantId: $tenant,
        ));
        $this->app->singleton(BacklinkProspectStore::class, fn () => new BacklinkProspectStore(
            DB::connection(), tenantColumn: 'site_id', tenantId: $tenant,
        ));
        $this->app->singleton(AiMentionStore::class, fn () => new AiMentionStore(
            DB::connection(), tenantColumn: 'site_id', tenantId: $tenant,
        ));
        $this->app->bind(CompetitorBrandTrackCommand::class, fn ($app) => new CompetitorBrandTrackCommand(
            DB::connection(), $app->make(SeoSiteProfile::class), reportSink: $app->make(ReportSink::class),
            tenantColumn: 'site_id', tenantId: $tenant,
        ));

        $this->app->singleton(AiTrafficStore::class, fn () => new AiTrafficStore(
            DB::connection(), tenantColumn: 'site_id', tenantId: $tenant,
        ));

        $this->app->singleton(MapPackStore::class, fn () => new MapPackStore(
            DB::connection(), tenantColumn: 'site_id', tenantId: $tenant,
        ));

        // PageSpeed Insights (kit 0.19.3) — see this method's own docblock.
        $this->app->bind(PageSpeedCredentials::class, PsiSettings::class);
        $this->app->bind(SitemapUrlSource::class, fn ($app) => new SitemapUrlSource(
            rtrim((string) config('app.url'), '/').'/sitemap.xml',
            $app->make(HttpFactory::class),
        ));
        $this->app->bind(PageSpeedUrlSource::class, GscPageSpeedUrls::class);
        $this->app->singleton(PsiSnapshotStore::class, fn () => new PsiSnapshotStore(
            DB::connection(), tenantColumn: 'site_id', tenantId: $tenant,
        ));
        $this->app->bind(PsiSnapshotReader::class, fn ($app) => $app->make(PsiSnapshotStore::class));

        // Microsoft Clarity (kit 0.20.0, 2026-11-02): the kit's own
        // SsSystems\Platform\Seo\Clarity module replaces this site's former
        // App\Services\MicrosoftClarityService, App\Console\Commands\
        // SyncMicrosoftClarity and App\Support\Seo\Reports\
        // EloquentClarityMetricsReader — all three are gone. ClaritySettings
        // (unchanged) now implements the kit's ClarityCredentials directly;
        // GscClaritySiteHosts rebuilds Site::current()'s hosts exactly as
        // MicrosoftClarityService::siteHosts() did; ClarityMetricsStore is
        // tenant-scoped like every other store in this method, over the same
        // clarity_daily_metrics/clarity_page_metrics tables this site always
        // wrote. StoreClarityMetricsReader replaces EloquentClarityMetricsReader
        // for the `clarity_metrics` capability (ClarityHealthReport), with the
        // same ClientError beacon count the original reader used.
        $this->app->bind(ClarityCredentials::class, ClaritySettings::class);
        $this->app->bind(ClaritySiteHosts::class, GscClaritySiteHosts::class);
        $this->app->singleton(ClarityMetricsStore::class, fn () => new ClarityMetricsStore(
            DB::connection(), tenantColumn: 'site_id', tenantId: $tenant,
        ));
        $this->app->bind(ClarityClient::class, fn ($app) => new ClarityClient(
            $app->make(ClarityCredentials::class), $app->make(HttpFactory::class),
        ));
        $this->app->bind(ClarityMetricsReader::class, fn ($app) => new StoreClarityMetricsReader(
            $app->make(ClarityClient::class),
            $app->make(ClarityMetricsStore::class),
            $app->make(ClarityCredentials::class),
            fn (\DateTimeInterface $since) => ClientError::query()->where('last_seen_at', '>=', $since)->count(),
        ));

        // IndexNow (kit 0.19.4): seo:indexnow's key/key-file (IndexNowSiteKey —
        // config('services.indexnow.key')/env INDEXNOW_KEY, else a platform
        // setting generated on first use, so no hand-made key is ever needed)
        // and its ledger of what has already been submitted, tenant-scoped the
        // same way every other store in this method is. App\Support\Seo\
        // IndexNowSubmitter (this site's ad hoc single/batch pings — observers,
        // the autopilot appliers, the dead-404 sweep) resolves both of these
        // too, replacing the kit's retired SsSystems\Platform\Indexing\
        // IndexNowService it used to call directly.
        $this->app->bind(IndexNowKey::class, IndexNowSiteKey::class);
        $this->app->singleton(IndexNowLedger::class, fn () => new IndexNowLedger(
            DB::connection(), tenantColumn: 'site_id', tenantId: $tenant,
        ));

        // Change intel: config/seo-intel.php's 'families' array, read through
        // IntelSettings (the kit has no config() dependency of its own) —
        // IntelSettings::withFamily() per family so a config change here
        // takes effect without touching this provider.
        $this->app->singleton(IntelSettings::class, function (): IntelSettings {
            $settings = new IntelSettings;
            foreach ((array) config('seo-intel.families', []) as $family => $values) {
                $settings = $settings->withFamily((string) $family, (array) $values);
            }

            return $settings;
        });
        $this->app->bind(OwnReviewsSource::class, GscOwnReviews::class);
        $this->app->singleton(IntelStore::class, fn () => new IntelStore(
            DB::connection(), tenantColumn: 'site_id', tenantId: $tenant,
        ));
        $this->app->singleton(IntelRunner::class, function ($app) use ($tenant): IntelRunner {
            $dfs = $app->make(DataForSeoApi::class);
            $store = $app->make(IntelStore::class);
            // Every source's common constructor args, positional:
            // dfs, store, profile, settings, connection, tenantColumn, tenantId.
            $base = [$dfs, $store, $app->make(SeoSiteProfile::class), $app->make(IntelSettings::class), DB::connection(), 'site_id', $tenant];

            return new IntelRunner($dfs, $store, [
                new SerpSource(...$base),
                new TrendsSource(...$base),
                new OnPageSource(...$base),
                new LabsSource(...$base),
                new BacklinksSource(...$base),
                new DomainAnalyticsSource(...$base),
                new BusinessDataSource(...$base, listings: $app->make(ListingStore::class), ownReviews: $app->make(OwnReviewsSource::class)),
                new ContentAnalysisSource(...$base),
                new AiOptimizationSource(...$base),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // /areas-served/{area}: a served town binds as usual; a town the site
        // no longer serves 301s to its nearest served neighbour (same spoke)
        // or the areas index — from the binding itself, since a failed
        // implicit binding 404s before any middleware could redirect, and
        // Google keeps re-crawling those old pages as "Not found (404)".
        Route::bind('area', function (string $value, $route) {
            $path = '/'.ltrim((string) request()->path(), '/');
            $public = (bool) preg_match('#^/(?:areas-served|areas|locations)/#', $path);

            // Anything but the public area pages — the admin API's
            // `areas/{area}` takes the raw id (int $area), nothing binds —
            // gets the parameter untouched, as before this binding existed.
            if (! $public) {
                return $value;
            }

            if ($area = AreaServed::query()->where('slug', $value)->first()) {
                return $area;
            }
            $suffix = preg_match('#^/(?:areas-served|areas|locations)/[^/]+(/.*)$#', $path, $m) ? $m[1] : '';
            $target = RetiredAreaRedirect::target($value, $suffix);
            if ($target !== null) {
                throw new HttpResponseException(redirect($target, 301));
            }
            abort(404);
        });

        // Dev guardrail (same as hive2025): surface N+1 lazy loads in the log
        // during development without ever breaking a page — and never in
        // production, where an unexpected lazy load must degrade, not throw.
        if (! app()->isProduction()) {
            Model::preventLazyLoading();
            Model::handleLazyLoadingViolationUsing(
                function ($model, string $relation): void {
                    logger()->warning('Lazy load: '.$model::class.'::'.$relation);
                }
            );
        }

        // Record when the AI feeds were last rebuilt, in the DATABASE rather
        // than leaving the dashboard to read file mtimes.
        //
        // The GEO card used to stat public/llms.txt on whichever machine
        // rendered the page — so browsing the admin locally reported "STALE —
        // 1 month ago" while production had regenerated it that morning. A
        // monitor that cries wolf in dev is a monitor people learn to ignore.
        // The stamp travels with the DB (dev pulls production), so the card
        // reports whether the JOB ran, which is the actual question.
        Event::listen(
            CommandFinished::class,
            function ($event): void {
                if ($event->command !== 'geo:llms-txt' || $event->exitCode !== 0) {
                    return;
                }

                try {
                    Tenancy::table('platform_settings')->updateOrInsert(
                        ['site_id' => Site::current()?->id, 'key' => 'geo.llms_txt_generated_at'],
                        ['value' => now()->toIso8601String(), 'updated_at' => now(), 'created_at' => now()],
                    );
                } catch (\Throwable) {
                    // Never let bookkeeping fail a generation run.
                }
            }
        );

        // Livewire update requests POST to /livewire/update and do NOT re-run
        // the original route's middleware. Without this, ResolveAdminSite never
        // fires on interaction, so every admin action after first paint would
        // run against the DEFAULT site instead of the one in the URL.
        Livewire::addPersistentMiddleware([
            ResolveSite::class,
            ResolveAdminSite::class,
        ]);

        // Any save/delete of public content schedules a debounced sitemap
        // regeneration + WebSub ping, so honest lastmod values reach crawlers
        // in minutes instead of waiting for the nightly cycle.
        $recrawlNudge = function ($model): void {
            RecrawlNudger::nudge();
        };
        foreach ([
            AreaServed::class,
            Project::class,
            ProjectImage::class,
            Testimonial::class,
            LandingPage::class,
        ] as $model) {
            $model::saved($recrawlNudge);
            $model::deleted($recrawlNudge);
        }

        RateLimiter::for('gemini-ai-content', function (): array {
            $rpmLimit = max(1, (int) env('GOOGLE_GEMINI_RPM_LIMIT', 10));

            return [Limit::perMinute($rpmLimit)->by('gemini-global')];
        });

        if (app()->environment('production') && str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        $this->applySocialUrlOverrides();

        // Register IndexNow observers for automatic URL submission
        // Anything a crawler reads from a generated file (sitemaps, llms.txt)
        // refreshes itself after the response whenever listed content
        // changes — one run per request, however many saves. The observers
        // below still rebuild the sitemap synchronously for the few events
        // they always did; this covers everything else (areas, services,
        // posts, landing pages) and the llms files.
        foreach ([
            Project::class, ProjectImage::class, AreaServed::class, Testimonial::class,
            Service::class, BlogPost::class, LandingPage::class,
        ] as $model) {
            $model::saved(fn () => PublicFeeds::refreshSoon());
            $model::deleted(fn () => PublicFeeds::refreshSoon());
        }

        Testimonial::observe(TestimonialObserver::class);
        AreaServed::observe(AreaServedObserver::class);
        Project::observe(ProjectObserver::class);
        BlogPost::observe(BlogPostObserver::class);
        ProjectImage::observe(ProjectImageObserver::class);

        // Restrict Log Viewer access to specific admin emails only.
        // Read via config() (not env()) so it keeps working under config:cache.
        $allowedEmails = array_filter(array_map('trim', explode(',', (string) config('log-viewer.allowed_emails', 'patryk@gs.construction'))));

        LogViewer::auth(function (Request $request) use ($allowedEmails) {
            if (app()->environment(['local', 'testing'])) {
                return true;
            }

            $productionToken = trim((string) config('log-viewer.production_token', ''));

            if ($productionToken !== '' && hash_equals($productionToken, (string) $request->bearerToken())) {
                return true;
            }

            // The central admin (ss.systems) reads this site's logs
            // server-to-server with its own dedicated token, separate from
            // production_token so either can be rotated alone.
            $hubToken = trim((string) config('log-viewer.hub_token', ''));

            if ($hubToken !== '' && hash_equals($hubToken, (string) $request->bearerToken())) {
                return true;
            }

            $user = $request->user();

            return $user && in_array($user->email, $allowedEmails, true);
        });

        Gate::define('viewLogViewer', function ($user) {
            if (app()->environment(['local', 'testing'])) {
                return true;
            }

            return $user && in_array($user->email, $allowedEmails, true);
        });

        // Optimize anonymous Blade components with Livewire Blaze
        // (register general path first, then specific overrides — Blaze uses most-specific match)
        Blaze::optimize()
            ->in(resource_path('views/components'))
            ->in(resource_path('views/components/layouts'), compile: false);
    }

    private function applySocialUrlOverrides(): void
    {
        try {
            if (! Schema::hasTable('platform_settings')) {
                return;
            }

            foreach (['instagram', 'google', 'facebook', 'yelp', 'houzz', 'angi'] as $platform) {
                $override = PlatformSetting::get('socials.url.'.$platform);
                if (is_string($override) && $override !== '') {
                    config()->set('socials.'.$platform.'.url', $override);
                }
            }
        } catch (\Throwable) {
            // During install/migrate, settings table may be unavailable.
        }
    }

    /**
     * Where visitors come from (kit 0.17): every provider's per-source numbers
     * into traffic_source_days, scoped to the current tenant like the rest of
     * the app. Contacts come from tracked_events (the /track tracker), so the
     * beacon collector counts visits only and nothing is counted twice.
     */
    protected function registerTraffic(): void
    {
        $tenant = fn () => Tenancy::currentId();
        $own = ['gs.construction'];

        $this->app->bind(TrafficDays::class, fn () => new TrafficDays(DB::connection(), 'traffic_source_days', 'site_id', $tenant));

        $this->app->bind(TrafficCollectors::class, fn ($app) => new TrafficCollectors($app->make(TrafficDays::class), [
            new PulseEventsCollector(DB::connection(), 'site_events', [], 'site_id', $tenant),
            new TrackedEventsCollector(DB::connection(), 'tracked_events', ownHosts: $own, tenantColumn: 'site_id', tenantId: $tenant),
            new LeadsCollector(DB::connection(), 'contact_submissions', $own, 'site_id', $tenant),
            new ClarityCollector(fn () => $app->make(ClaritySettings::class)->apiToken(), ownHosts: $own),
            new SearchConsoleCollector(fn (string $type, string $date) => $app->make(GoogleSearchConsoleService::class)->searchTypeTotals($date, $type)),
            new BingTotalsCollector(DB::connection(), 'bing_daily_totals'),
            new BusinessProfileCollector(fn (string $date) => self::businessProfileDay($date)),
            new AccessLogCollector((array) config('services.traffic.access_logs', []), $own),
        ]));
    }

    /**
     * One day of Business Profile actions from gbp_daily_metrics (filled by
     * gbp:metrics-sync), or null when that day has not synced yet.
     *
     * @return array{views: int, website_clicks: int, calls: int, directions: int}|null
     */
    public static function businessProfileDay(string $date): ?array
    {
        $metrics = DB::table('gbp_daily_metrics')
            ->where('site_id', Tenancy::currentId())
            ->whereDate('date', $date)
            ->groupBy('metric')
            ->selectRaw('metric, SUM(value) as value')
            ->pluck('value', 'metric');

        if ($metrics->isEmpty()) {
            return null;
        }

        return [
            'views' => (int) $metrics->filter(fn ($v, $m) => str_starts_with((string) $m, 'BUSINESS_IMPRESSIONS_'))->sum(),
            'website_clicks' => (int) ($metrics['WEBSITE_CLICKS'] ?? 0),
            'calls' => (int) ($metrics['CALL_CLICKS'] ?? 0),
            'directions' => (int) ($metrics['BUSINESS_DIRECTION_REQUESTS'] ?? 0),
        ];
    }
}
