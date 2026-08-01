# Multi-tenant readiness audit — 2026-07-31

Six parallel auditors over every single-site assumption blocking a second tenant.
**94 findings**, synthesised into a prioritised remediation plan.

## Subsystem summaries

### Task scheduler (routes/console.php) — multi-tenant readiness
*17 findings*

routes/console.php is completely untouched by the multi-tenant conversion: all 63 schedule entries (61 `Schedule::command` + 2 `Schedule::call`) invoke bare commands with no site binding, so every one of them runs against `Site::default()` — 55 of them touch per-site data and will silently ingest/write gs.construction's row set forever while every other tenant gets nothing. The `Tenancy` primitive and a `tenants:run` runner already exist (both untracked/new) but no schedule entry uses them, and `Tenancy::bind()` still does not rebind `app.url`, so even once looped, ~20 crawler/audit commands would crawl gs.construction for every tenant. On top of the loop itself there are four shared-singleton output paths that make a naive loop actively destructive: `public/sitemap.xml`, `public/llms.txt`, `storage/app/private/reports/{key}.md`, and the shared `storage/logs/*.log` files that `seo:health` reads as freshness signals.

### External-data ingestion + credentials (Google Search Console, Google Business Profile, Bing Webmaster, Microsoft Clarity, PageSpeed Insights, IndexNow, Cloudflare)
*14 findings*

Every external-data integration resolves its identity (GSC property, GBP account/location, Bing site_url, Clarity project+token, IndexNow key+host, Cloudflare zone) from `env()`/`config()`, which is process-global — so a second tenant literally cannot have its own credentials today, and no ingestion command accepts a tenant argument, so all of them write into the default site's rows. Worse, both Google services memoise their OAuth access token under a fixed, non-site-keyed cache key, so once site A's token is cached, site B's sync silently authenticates as site A. The `oauth_tokens` table (site-scoped, unique(site_id,provider)) and `platform_settings` (site-scoped, `value` cast `encrypted`, unique(site_id,key)) are already the right storage primitives — what's missing is a resolver in front of them plus a `--for-site` entry point on every console command and a scheduler that loops over `Site::active()`.

### app/Services/SeoService.php, app/Support/SEO/*, app/Services/Seo/*
*19 findings*

This area has zero tenant awareness — there is not one reference to Site::current(), site_config(), or config('brand.*') anywhere in SeoService.php, app/Support/SEO/*, or app/Services/Seo/* (verified by grep). Two systemic problems dominate: (1) every read of GSC/analytics data goes through DB::table(), which bypasses the BelongsToSite global scope entirely, so all 17 of those queries pool every tenant's rows together; and (2) all shared state — the autopilot's BASE_URL, public/sitemap.xml, public/llms.txt, storage/app/seo/*.json, and every cache key — is single-slot and unkeyed by site. On top of that, GS Construction's name, phone, owner names and owner photo are baked as literals into the meta/title/body text generators, including the two that feed the FULL-AUTO apply path (TitleMetaGenerator, LandingPageContentGenerator), so a second tenant gets "GS Construction" written into its own pages with no human in the loop.

### Queued jobs and the publishing pipeline (sitemaps, image sitemap, llms.txt, Atom/WebSub feed, IndexNow, robots.txt)
*16 findings*

The publishing pipeline is entirely single-tenant: every generated artefact (public/sitemap.xml, public/image-sitemap.xml, public/llms.txt, public/robots.txt, storage/app/sitemap.xml, reports/*.md) is written to one shared path, so the last tenant to run overwrites the previous one and every host serves the same file. Separately, not one class in app/Jobs/ carries a site_id or uses the existing App\Support\Tenancy primitive — RegenSitemapsAndNotifyJob, SubmitUrlsToIndexNow, RunSeoChannelSyncJob, RunGscInspectBulkJob, GenerateAiContentJob and PublishToSocialMediaJob all run under the default tenant on the worker, and RecrawlNudger's single global debounce cache key means a second tenant's content change inside the 10-minute window is silently discarded and never regenerates anything. Tenancy::for() and tenants:run already exist and are unused outside TenantsRun; wiring them into the jobs plus per-site artefact paths is the whole fix.

### app/Livewire/Admin/* (19 components) + resources/views/livewire/admin/* + resources/views/components/layouts/admin.blade.php
*15 findings*

There are zero references to Site, site_id, or withoutSiteScope() anywhere in the 19 admin components or their views — the whole panel is implicitly single-tenant and relies entirely on ResolveAdminSite binding Site::current(). Two structural problems break that: (1) ResolveAdminSite is registered only on the /admin/{site} route group, so it never runs on POST /livewire/update, meaning every Livewire interaction (search, paginate, save, delete) silently re-resolves the tenant from the HTTP host — which on the admin hub ss.systems is the active `ss` tenant — and also loses URL::defaults(['site']); (2) SeoReports.php does ~25 raw DB::table() queries against tables that are site-scoped at the model level, so the entire SEO dashboard sums every tenant together. Beyond that, the panel's shared side-channels (storage/app/reports/*.md, seo/recommendations.json, public/sitemap.xml, yelp-cookies.json, and ~8 unprefixed cache keys) are all one-per-install, and content slug uniques were never rebuilt as (site_id, slug), so the second tenant literally cannot create an area or landing page whose slug gs.construction already owns.

### Public runtime correctness for a second tenant (routes/web.php + non-admin Livewire)
*13 findings*

The public route set is completely un-tenanted: routes/web.php registers one global table for every host, and only `home.blade.php` is themed for the two non-gsc tenants, so ss.systems (already `is_active = 1`, with 0 areas / 0 projects / 0 testimonials) currently serves GS Construction's /costs, /permits, /trades, /insurance-claims, /compare, /financing, /warranty, /process, /faq, /how-to-choose and /service-area pages verbatim on its own domain. Beyond the route set, three classes of leak remain: identity hardcoded in shared public code paths (AiFeedController, SeoService, ServicePage, the app layout, the atom feed, /review), lead capture that mails every tenant's leads to `config('mail.from.address')`, and ~10 `Cache::remember()` keys on public paths that wrap site-scoped queries without the site in the key. The cleanest fix for the route set is `routes/sites/{slug}.php` files glob-loaded in routes/web.php behind a `site:{slug}` middleware gate — filesystem-only so `route:cache` still works, and it composes with host-based local preview (`{slug}.localhost:8003`, `App\Models\Site::forDevHost`).

---

## Remediation plan

## MULTI-TENANT REMEDIATION PLAN — /home/patryk/web/gsc

94 raw findings dedupe to **21 work items**. Ordered by dependency first, severity second. Verified against the live tree: `Tenancy`/`TenantsRun` exist but `grep -c tenants:run routes/console.php` = **0** of 65 schedule entries; `addPersistentMiddleware` appears nowhere in `app/`, `config/`, `bootstrap/`; `sites` table is `gsc(active) / ss(active) / jpeterson(inactive)` — so **ss.systems is live today**, which is what makes Phase 0 urgent rather than theoretical.

---

## ⛔ ORDERING HAZARDS — ship out of order and you corrupt data

Read this before scheduling any work.

| If you do this… | …before this | Consequence |
|---|---|---|
| **Item 12** (loop the scheduler) | Items 6–11 | Every hazard below fires at once, nightly, silently, exit code 0. |
| Loop `seo:gsc-prune-retired` (`routes/console.php:426`) | Item 2 or 9 | **Unrecoverable hard delete.** `SeoGscPruneRetired.php:26` reads the *global* `public/sitemap.xml`; the delete at `:60` is *site-scoped*. Bind tenant B → 0% of B's URLs match → B's entire `gsc_coverage_states` history is deleted in one pass. Its only guard checks the file is non-empty, not whose it is. Same fuse in `RecommendationEngine.php:182-205` (auto-fires at ≥100 "retired") and `GscErrors.php:106` (admin button). |
| Loop any GBP / social / IndexNow / Cloudflare command | Item 8 | **Writes to the wrong third-party account** — photos posted to GS Construction's Google location, IndexNow pings and cache purges against the wrong zone. Not revertible from this codebase. |
| Loop any ingestion command (`seo:gsc-sync`, `bing`, `clarity`, `psi`, `gbp:metrics-sync`) | Items 7 + 8 | Site A's real numbers get stamped with site B's `site_id`. Because `dim_hash` folds `site_url` in, the unique index never complains — the poisoned rows are indistinguishable from legitimate ones and there is no way to unwind them later. |
| Loop `sitemap:generate` / `geo:llms-txt` | Item 9 | Last tenant to run owns `public/sitemap.xml`, `llms.txt`, `robots.txt` for **every** host. Cross-domain sitemaps → Search Console rejects them → both tenants lose sitemap-based discovery. |
| Enable a second tenant's ingestion at all | Item 7 | `GoogleSearchConsoleService.php:166` and `GoogleBusinessProfileService.php:1545` memoise the OAuth access token under fixed keys `gsc_access_token` / `google_business_profile_access_token`. Site B gets a cache hit on **site A's bearer token**. That is a credential leak, not a data mix-up. |

**Rule: nothing in `routes/console.php` gets looped until items 6–11 are merged.** Until then, pin the risky entries with an explicit `--site=gsc`.

---

## PHASE 0 — Zero dependencies, wrong *right now*

### 1. Register `ResolveAdminSite` as Livewire persistent middleware
**Severity: BLOCKER — active cross-tenant writes**
`ResolveAdminSite` is attached only to the `/admin/{site}` route group (`routes/web.php:423`). Livewire's update endpoint registers its own stack (`web` + `RequireLivewireHeaders`), and nothing calls `Livewire::addPersistentMiddleware()`. So only the initial GET binds the tenant from the URL; **every subsequent Livewire action re-resolves from the HTTP host** — and the admin hub host `ss.systems` (`config/sites.php:26`) is itself an active tenant. On `ss.systems/admin/jpeterson-design.com/tags`, the page renders jpeterson's tags but `TagList::create()` (`app/Livewire/Admin/TagList.php:26`) stamps `site_id=ss`. Same for `AreaList::delete():27`, `ClientErrors::resolveAll():56`, `ContactSubmissions::delete():68`, every `ProjectForm`/`TestimonialForm` save. Secondly `URL::defaults(['site'=>…])` (`ResolveAdminSite.php:46`) is never set, so `route('admin.*')` in any re-rendered view throws `Missing parameter [site]`.
- **Change:** `AppServiceProvider::boot()` → `Livewire::addPersistentMiddleware(\App\Http\Middleware\ResolveAdminSite::class);`
- **Files:** `app/Providers/AppServiceProvider.php`
- **Verify:** POST a Livewire update for an admin component and assert `Site::current()->slug` equals the `{site}` URL segment, not the host.

### 2. Host-guard the three sitemap→coverage prune paths (fuse only, not the fix)
**Severity: BLOCKER — unrecoverable delete**
Cheap fuse ahead of item 9: refuse to prune when the sitemap being read does not belong to the current site. Parse the first `<loc>` and compare its host to `Site::current()->primary_host`; abort otherwise.
- **Files:** `app/Console/Commands/SeoGscPruneRetired.php:26-40`, `app/Services/Seo/RecommendationEngine.php:182` (and bail before the `Artisan::call` at `:205`), `app/Livewire/Admin/GscErrors.php:106`
- **Verify:** `php artisan tenants:run "seo:gsc-prune-retired --dry-run" --site=ss` exits non-zero with a host-mismatch message and deletes nothing.

### 3. Gate the gsc-only public route clusters
**Severity: BLOCKER — live indexable exposure on ss.systems**
`routes/web.php:56-403` registers one global table for all hosts, and `resources/views/themes/ss/` has only `home.blade.php`. So `https://ss.systems/permits` renders `permits-index.blade.php` — title literally *"Building Permit Guides — Chicago Suburbs | GS Construction"*, body *"GS Construction pulls and manages the permits…"*. Same for `/costs`, `/trades`, `/insurance-claims`, `/compare`, `/financing`, `/warranty`, `/process`, `/how-to-choose…`, `/service-area`, the six `/services/*` pages and the whole `/areas-served` cluster. `config/competitors.php:14`, `trades.php:19`, `remodel-costs.php:14`, `insurance-claims.php:22` are the only kill switches and they are process-global, so they cannot be flipped per tenant. `config/insurance-claims.php:31` carries a legal disclaimer identifying GS Construction as the licensed GC — a false statement about who holds the license when served on another domain.
- **Change:** move the gsc-only clusters to `routes/sites/gsc.php`; glob-load at the bottom of `routes/web.php` behind a new `EnsureSite` middleware (`abort_unless(in_array(Site::current()->slug, $slugs, true), 404)`). Filesystem-only, so `route:cache` and console boot are unaffected, and route middleware runs after `web` so `ResolveSite` has already bound. Keep universal routes (`/`, `/contact`, `/about`, `/reviews*`, `/projects*`, `/remodeling/{slug}`, feeds, admin) in `routes/web.php`.
- **Same-day stop-gap if the split slips:** switch the eight read sites in `CompareIndexPage:17,21`, `CompareCompetitorPage:37,66,75,90`, `TradesIndexPage:24,28,29`, `TradePage:24,28` to `site_config(...)` and drop `'enabled' => false` stubs into `config/sites/ss/`.
- **Files:** `routes/web.php`, new `routes/sites/gsc.php`, new `app/Http/Middleware/EnsureSite.php`, `bootstrap/app.php`
- **Verify:** `curl -H 'Host: ss.systems' localhost/permits` → 404; `Host: gs.construction` → 200.

### 4. Send leads to the tenant that captured them
**Severity: BLOCKER — silent lead theft**
`app/Livewire/ContactSection.php:265` and `app/Livewire/JobsPage.php:114` both `Mail::to(config('mail.from.address'))`. Neither `config/sites/ss/` nor `config/sites/jpeterson/` ships a `mail.php`, so `applyRuntime()` never overrides it. The `ContactSubmission` row stores correctly (`BelongsToSite`), so the tenant sees the lead in their admin while GS receives the email — the leak is invisible from both ends.
- **Change:** add `'lead_email' => env('LEAD_EMAIL', env('MAIL_FROM_ADDRESS'))` to `config/brand.php`; send to `site_config('brand.lead_email', config('mail.from.address'))`. **Do not** override `mail.from.address` per site — it is the envelope sender and changing it breaks SPF/DKIM alignment on the auto-reply at `ContactSection.php:277`.
- **Files:** `config/brand.php`, `config/sites/*/brand.php`, `app/Livewire/ContactSection.php:265`, `app/Livewire/JobsPage.php:114`
- **Verify:** submit the form with `Host: ss.systems`; assert the recipient is ss's inbox.

### 5. Rebuild content slug uniques as `(site_id, slug)`
**Severity: BLOCKER — second tenant cannot create content**
`2026_07_30_200000` rebuilt 17 SEO indexes per-site; `2026_07_30_190000` added `site_id` to the content tables and **rebuilt nothing** (verified — lines 38-55 add the column only). So `areas_served.slug`, `projects.slug`, `landing_pages.slug`, `project_images.slug` are still globally unique. `AreaList::createFromMap()` does a *scoped* `exists()` pre-check at `:45-48`, passes, then `AreaServed::create()` at `:56` throws SQLSTATE[23000] — unhandled 500. `LandingPages::generate()` has the identical shape at `:52-61`. `AreaForm.php:48` uses the string rule `"unique:areas_served,slug,{$id}"`, which never applies a global scope, so it rejects with a misleading "has already been taken".
- **Change:** new migration mirroring `2026_07_30_200000`'s reindex pass over those four tables; `AreaForm.php:48` → `Rule::unique('areas_served','slug')->ignore($id)->where('site_id', Site::current()->id)`.
- **Files:** new `database/migrations/*_scope_content_slug_uniques.php`, `app/Livewire/Admin/AreaForm.php:48`
- **Verify:** create an "Arlington Heights" area under `ss` while `gsc` owns that slug — succeeds.

---

## PHASE 1 — Foundations. Nothing after this is correct without them.

### 6. Complete `Tenancy::bind()` — URL root and theme finder
**Severity: BLOCKER — prerequisite for every looped command**
Two gaps in `app/Support/Tenancy.php:90-96`:

**(a) `app.url` is never rebound.** `SiteConfig::applyRuntime()` only pushes files a site actually overrides (`SiteConfig.php:150-166` — verified: only `brand/geo/seo/socials` exist on disk), and no `config/sites/{slug}/app.php` exists. ~20 commands derive their crawl target from `config('app.url')`: `GenerateSitemap.php:22`, `SyncPageSpeedInsights.php:165,186`, `SeoHealthCheck.php:44,73`, `SeoAuditQuickwins.php:41`, `SeoSchemaAudit.php:155`, `SeoCloudflare403Audit.php:40`, `SeoGbpParity.php:29`, `SeoInternalLink{Suggest,Audit}.php:35,36`, `SeoAreaPagesAudit.php:60`, `SeoBacklinksMonitor.php:40`, `SeoCompetitorRankGap.php:75`, `SeoCompetitorSchemaGap.php:38`, `SeoImageSchemaAudit.php:23`, `SeoBreadcrumbAudit.php:20`, `SeoCompetitorGap.php:225`, `IndexNowService.php:19-20`. Plus everything using bare `route()`/`url()` in console: `IndexNowSubmit.php:100-149`, `SubmitDead404sToIndexNow.php:49-51`, `RecommendationEngine.php:671`. Looped without this, tenant B's audits crawl gs.construction and write the scores into B's rows — **worse than not running, because the data looks real.**

**(b) `Theme::apply()` is never undone.** (Not in the raw findings — found while verifying.) `Theme.php:32` only ever `prependLocation()`s. After `Tenancy::each()` over N sites the finder holds `[themeN, …, theme1, resources/views]`. A view tenant B does *not* theme falls through to **tenant A's** theme before reaching the shared views. `resources/views/themes/ss/` has exactly one file, so this is guaranteed to fire on the very first loop.

- **Change:** in `bind()` add `config(['app.url' => $site->url()]);` + `URL::forceRootUrl($site->url()); URL::forceScheme('https');`. Snapshot the finder's `getPaths()` before prepending and restore it in `for()`'s `finally` (alongside the existing `SiteConfig::restore()`), then `$finder->flush()`. Also change `config/seo.php:295-303` `psi_urls` from `env('APP_URL')`-baked absolutes to relative paths resolved at read time — a cached config would otherwise freeze gs.construction into the array.
- **Files:** `app/Support/Tenancy.php:90-96` and `:35-48`, `app/Support/Theme.php:23-41`, `config/seo.php:295-303`
- **Verify:** `Tenancy::each(fn($s) => [config('app.url'), url('/x'), View::getFinder()->getPaths()])` — each site sees only its own root and exactly one theme path.

### 7. Per-tenant OAuth access-token cache keys
**Severity: BLOCKER — credential leak**
Verified: `GoogleSearchConsoleService.php:166` (`$cacheKey = 'gsc_access_token'`, forget at `:103`) and `GoogleBusinessProfileService.php:1545` (forgets at `:169`, `:185`). The `oauth_tokens` row underneath is correctly site-scoped; the cache in front of it is not. A ~50-minute window in which tenant B authenticates as tenant A. The invalid-grant cooldown keys at `:182-183` hash the refresh token and are fine.
- **Change:** compose with `Site::current()->id` at all five call sites.
- **Files:** `app/Services/GoogleSearchConsoleService.php:103,166`, `app/Services/GoogleBusinessProfileService.php:169,185,1545`
- **Verify:** bind A, fetch a token, bind B, fetch — assert two distinct cache entries and two distinct bearer values.

### 8. `App\Support\SiteCredentials` — the per-tenant credential resolver
**Severity: BLOCKER — a second tenant cannot have its own credentials at all**
`platform_settings` is already the right store (`BelongsToSite`, `'value' => 'encrypted'`, `unique(site_id,key)` per `2026_07_30_200000:55`) and nothing routes through it. Every integration identity is process-global env: GSC property (`config/services.php:99-108` **and** a second independent copy at `config/seo.php:237` — `SyncGoogleSearchConsole.php:48` reads one, `UsesSearchConsoleApi::gscSiteUrl():62` reads the other, so a partial override leaves half the commands on the wrong property); Bing key + `site_url` (`services.php:117-119`, where `site_url` is literally `env('APP_URL')`); Clarity `project_id` + `api_token` (`services.php:126-128` — the token *is* the project selector, there is no project param on the request); GBP `account_id`/`location_id`/`place_id`/`production_url` (`services.php:82-85`, consumed at `GoogleBusinessProfileService.php:1067,1077,30-37,845,904`); Cloudflare zone + token (`services.php:326-331`); IndexNow key (`config/indexnow.php:29`).
- **Change:** `SiteCredentials::get($key, $default)` resolving (1) `PlatformSetting::get($key)` — site-scoped, encrypted; (2) `Site::current()->setting("credentials.$key")` for non-secret ids; (3) `config($map[$key])` **only when `Site::current()->slug === config('sites.default')`** so a new tenant never inherits gsc's key; (4) `$default`. Pair with `has()`, and make every `isConfigured()` use it so an unconfigured tenant **fails loudly instead of syncing as gsc** — today `GoogleBusinessProfileService::isConfigured()`'s `! empty($config['location_id'])` passes for every tenant. Drop the `config(...refresh_token)` fallback at `GoogleSearchConsoleService.php:53`. Collapse `seo.search_console.site_url` into the one resolver. Route `/{key}.txt` (`routes/web.php:38-46`) and `/review` (`:92-99`) through it and 404 when absent — today ss.systems serves GS's IndexNow key as a public file and sends ss's happy customers to GS's Google review form. Surface the set in `/admin/{site}/platforms`. **Stays global (correctly):** OAuth `client_id`/`client_secret`, `services.google.pagespeed.api_key`, `places_api_key`, `brave.api_key` — quota keys, not identities.
- **Files:** new `app/Support/SiteCredentials.php`; `app/Services/{GoogleSearchConsoleService,GoogleBusinessProfileService,BingWebmasterService,MicrosoftClarityService,IndexNowService}.php`; `app/Console/Commands/{SyncBingWebmaster,SyncMicrosoftClarity,SyncPageSpeedInsights,SyncGbpPerformance,CloudflarePurgeCache}.php`; `app/Console/Commands/Concerns/UsesSearchConsoleApi.php:62`; `routes/web.php:38-46,92-99`; `app/Livewire/Admin/PlatformsSettings.php`
- **Also:** `SearchConsoleAuth.php:82`, `GoogleBusinessProfileAuth.php:119`, `GoogleSearchConsoleService::exchangeCodeAndStore():95` all write the token under the ambient scope, so there is **no way to authorize a non-default tenant from the CLI** — consenting with jpeterson's Google account overwrites gsc's row and destroys the working connection. Add `--for-site=` to all three. Note the HTTP callback at `routes/web.php:468,476` makes `redirect_uri` differ per tenant: either register each `/admin/<host>/platforms/gbp/callback` in the Google Cloud OAuth client, or move to one site-agnostic path carrying the site in the OAuth `state`.
- **Verify:** `SiteCredentials::has('gsc.site_url')` is `false` under `ss`, and `seo:gsc-sync` for `ss` exits with "not configured" rather than syncing gsc's property.

### 9. Per-site artifact paths + a `--for-site` command trait
**Severity: BLOCKER — every generated artifact is a single global slot**
Four shared-singleton output families make a naive loop actively destructive:

- **Sitemap.** `GenerateSitemap.php:613-619` → `storage_path('app/sitemap.xml')` + `public_path('sitemap.xml')`. Nine readers: `SeoGscPruneRetired.php:26`, `SeoGscInspectBulk.php:42`, `SeoReindexProblemPages.php:210`, `SeoHealth.php:283`, `IndexNowSubmit.php:160`, `SeoAudit.php:261,345`, `SeoImageSitemapBuild.php:30`, `SeoImageIndexDiagnostics.php:19`, `GscErrors.php:106`. Writers beyond the schedule: `LandingPages.php:96-105` (publish button) and `GenerateAiContentJob.php:279` (**on the queue**).
- **Reports.** 25 scheduled `--markdown` entries write flat `reports/{key}.md` (`SeoAutopilot.php:157`, `SeoGscMonitor.php:345`, `SeoContentDecay.php:198`, `SeoContentGap.php:180`, `SeoCompetitorGap.php:114`, `SeoCompetitorRankGap.php:296`, `SeoBacklinksMonitor.php:201`, `SeoAreaPagesAudit.php:313`, `SeoCloudflare403Audit.php:162`, `SeoAuditQuickwins.php:251`, `SeoGscInspectBulk.php:349`, `SeoGscCrawlBudget.php:139`, `SeoBreadcrumbAudit.php:117`, `SeoReindexProblemPages.php:304`, …). Read at `SeoReports.php:83,233,832` and `GscErrors.php:249`. **Cross-tenant disclosure in an admin that claims to be site-scoped** — competitor queries, backlink hosts, revenue-adjacent GSC numbers.
- **JSON state.** `RecommendationEngine::STORAGE_PATH = 'seo/recommendations.json'` (`:28`, read by `SeoReports:541,969` + `Dashboard:76`) and `seo/priority-pages.json` (`:270`). The latter is read by **`resources/views/components/priority-area-links.blade.php:9-12`** — a public-page content leak, not just admin — and by `PublishSocialMediaPost.php:321-324`.
- **llms.txt / robots.txt / image-sitemap.** Vendor `GenerateLlmsTxt.php:21` hardcodes `public_path($filename)` with no `--path`. `public/robots.txt:118-119` hardcodes `Sitemap: https://gs.construction/…`. `SeoImageSitemapBuild.php:30-31,109`.

- **Change:** `Site::sitemapPath()` / `seo_report_path($key)` / `site_storage_path()` helpers resolving `…/{slug}/…`; add `--path=` to `sitemap:generate` and `--src/--out` defaults to `SeoImageSitemapBuild`; keep gsc on the legacy paths and fall back to them on read so existing reports keep rendering. Serve `/sitemap.xml`, `/robots.txt`, `/llms.txt` from host-aware routes (or `public/sites/{slug}/` + nginx `map $host`). **Do not loop the vendor `geo:llms-txt`** — serve it dynamically like `/ai-feed.json` already is (the note at `routes/console.php:56-60` explains why the static feed was abandoned) and delete entries 50/53. Add `Concerns\RunsForSite` (`--for-site=` → `Tenancy::for()`) for commands invoked directly rather than through `tenants:run`.
- **Verify:** `tenants:run "sitemap:generate"` produces N distinct files; `curl -H 'Host: ss.systems' /sitemap.xml` returns ss URLs only.

### 10. Site-aware jobs
**Severity: BLOCKER — the queue is 100% single-tenant**
Verified: **no file in `app/Jobs/` contains `siteId` or `site_id`.** Every dispatch is made from a correctly-bound context and the binding is dropped at serialization.
- `RegenSitemapsAndNotifyJob.php:22` — no payload; `handle():30-49` runs `sitemap:generate` + `seo:image-sitemap-build` and pings pubsubhubbub with `url('/feed/updates.atom')`. Saving a project on tenant B regenerates and re-announces **tenant A's** sitemap.
- `RecrawlNudger.php:15` — `GATE_KEY` is one global cache key. Tenant A's save at 10:00 claims it for 12 min; **tenant B's save at 10:01 hits `return` on `:23` and is silently discarded with no log line.** `RegenSitemapsAndNotifyJob:34` clears that same shared key. Upstream, `AppServiceProvider.php:43` receives `$model` and discards it — the site is thrown away at the one point where it is still known.
- `SubmitUrlsToIndexNow.php:26` — carries only `$urls`; `IndexNowService::__construct:18-23` snapshots key/host from process config, so the whole batch is rejected 422/403 against A's credentials and B never gets IndexNow at all. Dispatchers `ProjectObserver.php:117-122`, `ProjectImageObserver.php:265-271`, `AreaServedObserver.php:47-58`, `TestimonialObserver.php:58` also build URLs with `route()`.
- `RunSeoChannelSyncJob.php:29` / `RunGscInspectBulkJob.php:33` — bare `Artisan::call`. Dispatched from `SeoReports.php:87,543`, `GscErrors.php:63`, `AreaForm.php:80`, `RecommendationEngine.php:172,292,315`. **Clicking "sync" inside tenant B's admin pulls gs.construction's GSC property and writes it with gs.construction's `site_id`.** `AreaForm:80` is the sharpest: the UI promises "the gap closes itself within a minute" while `gbp:geocode-areas` runs over gsc's areas and B's row stays coordless forever.
- `PublishToSocialMediaJob.php:28` — no site; `getGbpImageUrl():75` reads `production_url` defaulted to `'https://gs.construction'`; all `MetaSocialService` links come from `config('app.url')` (`:474,493,526,546`). A post published for tenant B links to gs.construction and may publish to the wrong account entirely.
- **Change:** `InteractsWithSite` trait — capture `Site::current()->id` **at dispatch** (not as a constructor default), wrap `handle()` in `Tenancy::for()`. Apply to all six. `RecrawlNudger::gateKey(int $siteId)`; `nudge(int $siteId)`; `AppServiceProvider.php:43` → `RecrawlNudger::nudge((int) $model->site_id)`. Observers → `SubmitUrlsToIndexNow::dispatch($urls, (int) $model->site_id)` with URLs built from `$model->site->url(...)` not `route()`. `RunSeoChannelSyncJob` should merge `['--for-site' => $site->slug]` into the forwarded options.
- **Bonus cleanup:** delete `regenerateSitemap()` from `ProjectObserver.php:95`, `ProjectImageObserver.php:245`, `TestimonialObserver.php:38` and the `$regenerateSitemap` flag on `GenerateAiContentJob.php:31,279`. `AppServiceProvider.php:46-54` already registers the debounced nudger on the same models, so these are redundant synchronous full sitemap builds *and* the wrong-tenant queue path in one.
- **Verify:** dispatch each job under `ss`, assert the worker's `Site::current()->slug === 'ss'` and that A's artifacts are untouched.

### 11. Harden `tenants:run` — the `--site` name collision
**Severity: HIGH — silent cross-property poisoning**
`TenantsRun.php:15` uses `--site=*` for the *tenant*; seven commands already use `--site=` for the *GSC property URL*: `SyncGoogleSearchConsole.php:22`, `SeoTitleAudit.php:19`, `SeoGscSitemapStatus.php:22`, `SeoGscInspectBulk.php:31`, `SeoGscCriticalHealth.php:33`, `SeoReindexProblemPages.php:32`, `SearchConsoleAudit.php:14`. `tenants:run "seo:gsc-sync --site=sc-domain:x" --site=jpeterson` is legal and does two different things with one flag name — and `parse():88-110` splits the quoted string itself, so a mis-placed flag lands on whichever side the quoting put it. The resulting rows upsert on `dim_hash` and look legitimate.
- **Change:** rename the inner option to `--property=` across the seven (keep `--site` as a deprecated warning alias); reserve `--site` for tenant selection. Once item 8 lands, default the property from `SiteCredentials::get('gsc.site_url')` so the flag becomes a rare override.
- **Verify:** `tenants:run "seo:gsc-sync --site=x" --site=ss` errors on the ambiguous flag instead of running.

---

## PHASE 2 — Turn the loop on (only after 6–11)

### 12. Convert the 65 schedule entries
**Severity: BLOCKER — but strictly last of the enabling work**
Census: **55 per-site**, 2 genuinely global. Global: `gsc:cleanup-gbp-jpegs` (`:85`, defined `612-644` — pure filesystem sweep) and `yelp:check-session` (`:89` — one shared Chromium profile).
- **Change:** local helper at the top of `routes/console.php` — `$perSite = fn (string $cmd) => Schedule::command('tenants:run "'.$cmd.'" --continue-on-error');` — and convert the 55. **Do not build the loop at definition time** (`foreach (Site::active() as $s) Schedule::command(...)`): `routes/console.php` loads on every artisan invocation including `migrate` on a fresh DB and would fatal before `sites` exists. `tenants:run` defers the DB read to run time. Explicitly comment `:85` and `:89` as global so a later pass does not sweep them in.
- **Special cases:** the two `Schedule::call` closures (`:566-605` GBP safety net, `:716-725` lead safety net) must loop **inside** the closure via `Tenancy::each()` and keep their single `->name()`. If instead registered per site, `CallbackEvent::mutexName()` is `sha1($this->description)` — and the description is exactly what `->name()` sets — so N identical names share one mutex and `onOneServer`/`withoutOverlapping` let only **one tenant run per tick, silently**. (`Schedule::command` is safe: `Event::mutexName()` hashes expression+command, so a differing `--site=` yields distinct keys.) The lead net at `:717` is the one entry whose failure mode is a **lost sales lead** — `ContactSubmission where status=pending` under the default scope only; also confirm `SendLeadToHive::dispatch($id)` carries the site (item 10).
- **Verify:** `php artisan schedule:list | grep -c tenants:run` = 55; run `schedule:test` for `seo:gsc-sync` and confirm N site banners.

### 13. Move `when()` gates inside the tenant loop
**Severity: HIGH**
`->when()` is evaluated once, in the `schedule:run` process, before any binding. `routes/console.php:105-119` runs `ReviewUrl::query()->where('platform','google')…->exists()` — site-scoped, so if gsc has no unmatched reviews the task is skipped entirely and tenant B's 200 unmatched reviews never get deep links. Same globally-evaluated pattern at `:98, 370, 404, 421, 438, 454, 477, 485, 492, 507, 520, 531, 547-560, 610`. Capability gates (`config('services.google.business_profile.enabled')`, `services.bing.webmaster_api_key`) are single env flags: one tenant without GBP disables it for everyone.
- **Change:** drop data-driven gates and let the command no-op on its own scoped query; replace capability gates with `SiteCredentials::has(...)` evaluated inside the loop, so `tenants:run` skips an unconfigured *site* rather than the scheduler skipping the whole *entry*.

### 14. Per-site logs, or drop the filemtime heuristic
**Severity: HIGH — the monitoring signal is the thing that lies**
Every entry appends to one file (`seo-gsc-sync.log:475`, `gbp-metrics-sync.log:483,491`, `seo-autopilot.log:301`, `schedule.log` ×30). `SeoHealth.php:283-292,317-321` computes pipeline freshness from `filemtime()` of those files. Once N tenants share them, **a tenant whose GSC OAuth token expired scores green because gsc's sync touched the file 10 minutes ago.**
- **Change:** prefer deriving freshness from data (`GscQueryMetric::max('updated_at')` under the current scope) — site-correct *and* immune to log rotation. Namespace log paths as a secondary.

### 15. `SEO_ALERT_EMAIL` — a live bug independent of tenancy
**Severity: MEDIUM**
`routes/console.php:433` calls `env()` outside a config file. Under `php artisan config:cache` (standard on deploy) that returns null, so `emailOutputOnFailure` at `:440` and `:456` attaches **nothing today**. Plus one global address means tenant B's failures email gsc's operator.
- **Change:** move to `config/seo.php` so `config:cache` captures it; resolve per tenant inside the command's failure path, not in the schedule closure (which runs before any binding).

### 16. Social posting: parallel entries + per-site GBP day seed
**Severity: MEDIUM**
`tenants:run` is serial inside one process holding one mutex. Two tenants of Instagram (`:513`, `--random-delay=180`) = up to 6h against a `withoutOverlapping(60*4)` TTL — **the lock evaporates mid-run and the next tick starts a second concurrent posting process: duplicate posts.** Also `:556` seeds Mt19937 with `crc32($now->format('o-W'))` — no site component — so every tenant posts on the same two weekdays in the same 09:30 window.
- **Change:** register `:513, :524, :540` **per tenant** (`tenants:run "…" --site=$slug`) so each gets its own mutex and the delays run in parallel. Seed with `crc32($week.'|'.$site->slug)` inside the loop. For quota-bound syncs (`gsc-sync`, `gbp:metrics-sync`, `psi-sync`, `bing-sync`) keep one serial entry and multiply the TTL with headroom (`60` → `240`). Gate `:82`, `:540`, `:580` (the three queue entry points) on item 10 being merged — until then pin them `--site=gsc`.

---

## PHASE 3 — Read-path correctness (latent today, wrong the moment a 2nd tenant ingests)

### 17. `DB::table()` bypasses `BelongsToSite`
**Severity: HIGH (BLOCKER once two tenants have rows)**
The scope is a *model* scope; the query builder never sees it. ~45 sites across three layers:
- **Services:** `RecommendationEngine.php:222,253,308,399,404,405,411,465,509,567,572,640,641,650`; `SeoAutopilotService.php:130,201`; `MetricProbe.php:46`; `SyncPageSpeedInsights.php:207`; `TrackRankings.php:55`; `SeoHealth.php:188,217`.
- **Admin:** `SeoReports.php:331,335,340,359,367,371,376,409,414,419,443,451,455,461,469,473,497-511,523,620,879,888` — the entire SEO dashboard (clicks, impressions, CTR, position, the 14/30-day chart, coverage buckets, rank distribution, Top Queries/Pages) sums across **all** tenants with nothing on the page saying so.
- **Worst instance:** the correlated subquery at `SeoReports.php:523-526` matches `r2.query = r1.query AND r2.engine = r1.engine` and picks `MAX(id)` **across sites** — a tenant's rank row is replaced by whichever site last snapshotted that keyword. Needs `AND r2.site_id = r1.site_id` inside the subquery plus an outer `where('r1.site_id', …)`.
- Concretely elsewhere: `MetricProbe::forPage()` sums A+B clicks into one baseline, so `SeoAutopilotService::measure()` judges "worked/regressed" on pooled data and feeds it into `learnedWeight()` — the self-improving loop learns from the wrong tenant.
- **Change:** swap to the Eloquent models (`GscQueryMetric::query()`, `GscDailyTotal`, `BingDailyTotal`, `BingTrafficStat`, `GbpDailyMetric`, `GscCoverageState`, `SeoRankSnapshot`, `Testimonial`, `Project`); where a raw builder must stay for `selectRaw`/`havingRaw`, append `->where('<table>.site_id', Site::current()->id)`.
- **Verify:** `grep -rn "DB::table('\(gsc_\|bing_\|clarity_\|psi_\|gbp_\|seo_\|tracked_\)"` returns only lines carrying an explicit `site_id` predicate.

### 18. Namespace every cache key by tenant
**Severity: HIGH — defeats the scoping work already done**
Whichever tenant warms the entry wins for all tenants until TTL, with **no DB query to give it away**.
- **Public (worst):** `SeoPathOverride.php:45` `'seo:path_overrides'` cached 6h over a `BelongsToSite` model — a GS `<title>` override for path `services` is served on jpeterson-design.com. `resources/views/components/product-service-schema.blade.php:112,120` — GS's `aggregateRating` and review snippets emitted inside another tenant's schema.org markup (**fabricated reviews on the wrong brand — structured-data manual-action risk**). `AreaSeoPolicy.php:38` `'seo.area.priority_cities'` — drives `shouldIndex()`, so noindex decisions *and* sitemap membership follow another business's portfolio. Also `GeoAnswersController.php:19`, `AiFeedController.php:21`, `TestimonialsSection.php:255`, `TestimonialsGrid.php:185`, `priority-area-links.blade.php:7`, `city-reviews-badge.blade.php:12`, `SeoService.php:908,918` (`'testimonial_count'` → every tenant claims the first tenant's review count in its meta).
- **Admin:** `SeoReports.php:814,643,856,86,542`; `PlatformsSettings.php:145,155,267,304,492,534,815` (`yelp.last_auth`, `yelp.session_dead`, `instagram.last_session_check` — one dead Yelp session paints the banner on every tenant).
- **Change:** a `site_cache_key(string $key)` helper; route every `remember/get/put/forget/add` through it — **including the busters** (`SeoPathOverride.php:24`), or a bust clears only one tenant. Keys already containing a globally-unique model id (`TestimonialsSection.php:186,202`) are safe.
- **Verify:** load `/admin/gs.construction/seo-reports` then `/admin/ss.systems/seo-reports` within 15 min — different numbers.

---

## PHASE 4 — Identity de-hardcoding

### 19. Autopilot base URL + the two FULL-AUTO generators
**Severity: HIGH — autonomous publication of the wrong brand**
`SeoAutopilotService.php:41` `private const BASE_URL = 'https://gs.construction'` is used both as a SQL filter (`:203` `where('page','like', BASE_URL.'/%')` → **matches zero rows for any other tenant, so `synthesizeTitleMeta()` silently returns 0 forever while the autopilot reports healthy**) and as the prefix for every `target_url` written to the site-scoped `seo_actions` (`:175,255,354,478,531`). `ReindexApplier.php:33` then calls `seo:reindex-problem-pages --urls=https://gs.construction/...` — tenant B's autopilot pings IndexNow for tenant A's URLs. Also `public_path('sitemap.xml'):412` and `public_path('llms.txt'):520`.

`SAFE_ALLOWLIST` (`:39`) contains `title_meta` and `create_page`, i.e. **no human in the loop**:
- `TitleMetaGenerator.php:70,72,74` — `'GS Construction'` as a literal title hook and in the meta description; `", IL"` / `' in the Chicago suburbs'`. Applied via `TitleMetaApplier` → `SeoPathOverride` → published to the SERP.
- `LandingPageContentGenerator.php:66,74,127,168` — `'GS Construction'` in H1, intro and local sections; `PRICING` const (`:37-44`) hardcodes GS's remodeling price bands. `CreatePageApplier` materializes these as `LandingPage` rows, and publishes if `seo.autopilot.auto_publish_landing_pages` is on.
- **Change:** replace the const with `$this->baseUrl()` from `Site::current()->url()`; route the literals through `config('brand.name')` / `brand.state`; add a `region` key to `config/brand.php`; move `PRICING` to `site_config('remodel-costs.bands')`. **Interim: drop `create_page` from `SAFE_ALLOWLIST` for non-gsc sites until this lands.**

### 20. Public identity literals
**Severity: HIGH**
- `AiFeedController.php:93-104` — hardcoded `name: 'GS Construction'`, `phone: '+1-224-735-4200'`, `email: crew@gs.construction`, `locality: 'Arlington Heights'`, `url/$schema: https://gs.construction`. Linked from every page's `<head>` (`layouts/app.blade.php:50`), so `https://ss.systems/ai-feed.json` **asserts to ChatGPT/Perplexity/Claude that ss.systems is GS Construction** — cross-wires two entity graphs.
- `SeoService.php` — `'GS Construction & Remodeling'` (`:85`), `'GS Construction'` (`:106,200,234,322,927,945,966,969,971,1004`), `'Greg & Patryk'` (`:201`), phone `(224) 735-4200` (16 sites incl. `:219,220,283,358-388,540,572-600,759`), 42×`, IL`, 34×Chicago/Chicagoland, `$primaryDomain` default `'gs.construction'` (`:26`), and `asset('images/greg-patryk.jpg')` as the **global OG-image fallback** (`:1037` + `:203,223,236,929,947,974,1007`) — a link to jpeterson-design.com shared to Slack renders GS's owners' faces. Note `layouts/app.blade.php:52` already does it right (`config('brand.ai_description')`), which is what marks the rest as oversight.
- `routes/web.php:336,346` — atom feed titled *"GS Construction — Recently Updated Pages"*, pushed to pubsubhubbub, so the wrong brand name is what subscribed crawlers ingest.
- `layouts/app.blade.php:37-45` — `author`/`publisher`/`copyright` = GS Construction, `geo.position` = Arlington Heights, plus global favicon/webmanifest at `:77-93`.
- `Navbar.php:54` reads `config('nav.links')` directly and no tenant ships a `nav.php`, so **the bad route set is linked from the site chrome and therefore crawlable.**
- **Change:** route all through `config('brand.*')` / `site_config()`; add `og_image`, `region`, `state_name`, `owners` keys to `config/brand.php`; `SeoService.php:26` → `Site::current()->primary_host`; `Navbar` → `site_config('nav.links', [])`. Because per-site `brand.php` uses `'__replace' => true`, a tenant that forgets a key gets `null` — make `setTags()` tolerate a null OG image rather than falling back to the shared literal. Add a test asserting nav/brand/route-set are present for every active site.

### 21. Admin chrome, previews, socials defaults
**Severity: MEDIUM**
- `SocialMediaPosts.php:92-102` does `require config_path('socials.php')` **directly**, bypassing `applyRuntime()` (which had already pushed `config/sites/jpeterson/socials.php`), and memoises in a `static $defaults`. `loadSocialUrls():84-91` therefore pre-fills jpeterson's form with GS's profile URLs; the operator hits Save and `saveSocialUrls():74-79` writes them into jpeterson's `platform_settings` — **jpeterson's public footer and schema.org `sameAs` now point at GS's Instagram/Facebook/Yelp/Houzz/Angi.** The blade hardcodes the same URLs as placeholders (`social-media-posts.blade.php:112-117`). Fix: `config('socials')`, drop the static.
- `PlatformsSettings.php:720,784,799,828` — one `storage_path('app/yelp-cookies.json')`; the merge at `:788-796` keys only on `domain|path|name`, so two tenants' session cookies interleave into a hybrid file that **authenticates as neither**. Scope the path.
- `layouts/admin.blade.php:31,8,92` — sidebar says "GS Construction" with GS's logo while administering another tenant, "Back to Site" goes to the admin host. Given item 1, the URL bar is the *only* cue about which tenant you're editing — add a visible site badge.
- `layouts/admin.blade.php:13-22,121-141` — the Google Ads gtag and conversion click-listener are on the **admin panel**, so every internal test submit and `tel:`/`mailto:` click while editing any tenant reports a conversion into gsc's Ads account. Just delete it; it belongs on the public layout only.
- Preview links: `landing-pages.blade.php:70,90`, `testimonial-list.blade.php:157`, `AreaList.php:83` → `Site::current()->url(...)`.
- `SeoReports.php:879-885` non-brand CTR excludes literal `'%gs construction%'`/`'%gs builder%'`; `RecommendationEngine.php:225,256,468,512` the same. Read from `site_config('seo.brand_terms', [config('brand.name')])`.

---

## NOT WORTH DOING YET — be honest

1. **A cross-site roll-up dashboard.** Raised in finding on `Dashboard.php:52-89`. With 2 active tenants the operator can visit two URLs. Build it at 5+. The `withoutSiteScope()` escape hatch already exists when you want it.
2. **Per-site Yelp/Instagram browser profiles** (`routes/console.php:89,122,130`, `PlatformsSettings:720`, `InstagramRemoteLoginService:337`). Neither ss nor jpeterson has a Yelp or Houzz presence. Pin these `--site=gsc` and revisit when a tenant actually has an account — a shared Chromium profile under concurrent Puppeteer launches produces the "indeterminate" result the command already special-cases, so looping it is strictly worse than not.
3. **Refactoring the service catalogue into per-site config** (`ServicePage.php:28-200`, the triplicated slug whitelist in `routes/web.php:277-294,195,208,248` and `AreaPage.php:68-75`). Item 3's route gate removes the exposure for ~40 lines. The catalogue refactor is a day of work that buys nothing until a non-gsc tenant actually needs service pages — jpeterson is `is_active=0`.
4. **`SEOBuilder.php:139`'s Illinois-only state strip.** Both real tenants are IL. Cosmetic until a non-IL tenant exists.
5. **Deciding `hive:sync`'s ownership model** (`routes/console.php:63`, `HiveProjectsClient.php:21-22`). This is a *decision*, not a code task. Most likely answer: the zip counts are shared reference data — drop `BelongsToSite` from `HiveProjectZipCount` and read with `withoutSiteScope()` from the map component, keeping one global schedule entry. Don't build per-site Hive credentials speculatively (`config/services.php` isn't even in the per-site override set yet).
6. **Multiplying every `withoutOverlapping` TTL by tenant count.** At N=2–3 only the three `social:post` entries actually overflow (item 16). `seo:gsc-sync` at N×~2-3min against 60min is fine until ~20 tenants.
7. **Route-name prefixing / `Route::domain()`** (finding on `routes/web.php:56-403`). Only needed if two tenants must own the same unprefixed route name. They don't yet.
8. **Building out jpeterson's theme, nav, and per-site configs.** It's `is_active=0`. Do items 1–20 against ss (which is live and therefore actually leaking) and treat jpeterson activation as the acceptance test for the whole plan.

**Suggested cut line for a first PR:** items 1–5 (Phase 0). They are independent of each other, need no new abstractions, and each stops something that is wrong in production today.
