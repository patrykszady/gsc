# GSC — Claude Code instructions

@.github/copilot-instructions.md

## MCP tooling
- Laravel Boost MCP is available — use its `search-docs` tool for version-accurate Laravel, Livewire, Tailwind, and Flux UI documentation instead of relying on memory.
- Use `tinker` / `database-query` from Boost to inspect runtime state and data when debugging.

## Multi-site platform (important)

This repo is **one Laravel app serving several sites**. The request host selects the
tenant; `App\Models\Site::current()` is the ambient tenant everywhere.

- **Site register + per-site briefs:** [docs/sites/](docs/sites/README.md). Read the brief
  before doing site-specific work.
- **Which site am I changing?** Files under `resources/themes/{theme}/` and
  `config/sites/{slug}/` affect ONE site. Everything else — `resources/views/`,
  `config/*.php`, `app/` — is SHARED and changes every site.
- **Preview:** every tenant has its own local host — `http://{slug}.localhost:8003`
  (`gsc`, `ss`, `jpeterson`). The Host header picks the tenant exactly as in production,
  so links, redirects, forms and Livewire all stay on that site. No hosts-file entry
  needed: browsers map `*.localhost` to loopback themselves. Inactive sites are
  previewable this way too.
  - `http://127.0.0.1:8003/_sites` — every tenant, what it overrides, what a path does on each.
  - `http://127.0.0.1:8003/?site={slug}` still works and now pins for the browser session.
  - From WSL bash, curl cannot resolve `.localhost` — use
    `curl -H "Host: jpeterson.localhost" http://127.0.0.1:8003/…`.
  - `php artisan sites:check` validates every tenant's theme, assets, identity and nav.
  - `https://dev.ss.systems/` via Cloudflare Tunnel when a public URL is needed.
- **Console/queue has no request**, so `Site::current()` falls back to the default site.
  Anything per-tenant must run inside `App\Support\Tenancy::for()` / `::each()`, or via
  `php artisan tenants:run "<command>" --site=<slug|host>`.
- **Identity belongs in `config/brand.php`**, never hardcoded. A literal brand name, phone
  or email in shared code renders on every tenant. Per-site overrides live in
  `config/sites/{slug}/brand.php` and **must** set `'__replace' => true` — merging inherits
  another business's contact details and review-profile URLs.
- **Content for a client site must be supplied by that client.** Do not copy text or images
  from their existing site (see `docs/legal/`).

## Search Console, sitemaps and robots are PER SITE (2026-09-15)

- **Property:** `App\Support\Seo\SearchConsoleProperty::url()` — the default site's comes
  from env (`GSC_SEARCH_CONSOLE_SITE_URL`); any other site is `sc-domain:{primary_host}`
  unless its own `config/sites/{slug}/seo.php` (or `sites.settings.config.seo`) sets
  `search_console.site_url`. Never read `config('…search_console.site_url')` directly.
- **Grant:** `oauth_tokens` is site-scoped; the `.env` refresh token is the default
  site's only (`GoogleSearchConsoleService::getRefreshToken()` returns null elsewhere).
  Connect a new site from ITS admin Platforms page.
- **Crawl files:** `App\Support\Seo\CrawlFiles` — sitemaps live at
  `storage/app/private/tenants/{slug}/{sitemap,image-sitemap}.xml`, served by the
  `/sitemap.xml` and `/image-sitemap.xml` routes for the tenant that answers the host;
  `/robots.txt` renders `resources/robots/{slug}.txt` (else `default.txt`) with `{{base}}`
  = the site's own origin. **Nothing goes in `public/`** — nginx serves a static file
  there to every host of the deployment. **Known exception (2026-09-15):** Forge's shared
  nginx `site.conf` has `location = /robots.txt { access_log off; log_not_found off; }`
  (static only) plus `error_page 404 /index.php`, so the route answers **404** on prod
  with the right body. Until that block also carries
  `try_files $uri /index.php?$query_string;` (root-owned, no sudo — edit in Forge),
  `robots:publish` in the post-deploy script writes the DEFAULT site's robots.txt to
  `public/`; every other host gets that same file. Fix nginx before another site launches. `Site::forgetActive()` after flipping `is_active`
  in-process (the active set is cached per process).
- **Schedules:** every GSC / sitemap command in `routes/console.php` runs through
  `$perTenant(...)` = `tenants:run "<cmd>" --continue-on-error` (active sites only, so a
  site in build is skipped until launch). Queued inspection jobs carry `siteId`.
- **Legacy:** `jpeterson-design.on-forge.com` is an OLD separate Forge site from the
  `patrykszady/jpeterson-design` repo, not this app — its own public/robots.txt is not ours.

## Search Console in the admin (2026-09-12)

The central admin's GSC Errors page reads and writes Search Console through this
app's API (`routes/api-admin/seo.php`, `GscErrorController`): `gsc-errors/indexing` is
the Console's "why pages aren't indexed" breakdown built from every coverage row
(`gsc_coverage_states.source` = `sitemap` for the nightly sweep, `console` for URLs
imported from a Console export or inspected on demand) plus the paths Googlebot 404s
on (`tracked_404s`) and the robots.txt rules; `gsc-errors/import` takes a Console CSV
export and queues `RunGscInspectUrlsJob` (`seo:gsc-inspect-bulk --urls=… --source=console
--reason=…`); `gsc-errors/inspect` runs one URL through the URL Inspection API;
`gsc-errors/sitemaps` lists / submits / deletes the property's sitemaps
(`GoogleSearchConsoleService::listSitemaps/deleteSitemap/inspectUrl`). The pruner only
prunes `source = sitemap` rows. The API cannot export the Console's Pages report in
bulk, request indexing, start or read "Validate fix", or read Core Web Vitals; the
Indexing API is job postings and livestreams only, and the BigQuery export is
performance data only.

**URL Inspection allowance (2026-09-14).** 2,000 calls/day and 600/minute per property,
shared by the nightly sweep (`seo:gsc-inspect-bulk`), a Console CSV import and the admin's
inspect button. `App\Support\Seo\UrlInspectionQuota` counts every call per site, per
Pacific day (Google's quotas turn over at midnight Pacific): callers reserve what is
left and stop before Google refuses; a 429 marks the day spent. The sweep's default
`--include=sitemap,coverage,tracked` also refreshes coverage rows the sitemap no longer
carries and the paths Googlebot 404s on, since nothing else ever inspects those.

**Retired towns and dead old-site paths (2026-09-12).** `/areas-served/{town}` for a
town no longer in `areas_served` 301s to the nearest served town (same spoke) via the
bundled gazetteer (`App\Support\Areas\RetiredAreaRedirect`, hooked into the `area`
route binding in `AppServiceProvider` because a failed implicit binding 404s before any
middleware can act), or to `/areas-served` when the gazetteer does not know it.
`RedirectLegacyUrls::GONE` answers 410 for old WordPress-era paths; it runs on every
request, so nothing in it may match a live page. A method-agnostic fallback route at the
end of `routes/web.php` (off `/api` and Livewire) is what lets unmatched public URLs
reach that middleware at all. Since 2026-09-14 `/areas/…` and `/locations/…` 301 to
`/areas-served/…` (they used to serve the page under noindex + canonical), a review
reached by any slug other than its own 301s to the real one (route binding reads only
the trailing id), and robots.txt disallows `/*_page=` (Livewire pagination state from an
older build). **Area indexing policy (2026-09-14, Patryk's decision):** every page variant
of a town that has its own copy is indexable — `AreaSeoPolicy` flags
`seo.area_index_subpages` / `seo.area_index_service_pages` (config/seo.php, env
`SEO_AREA_INDEX_SUBPAGES` / `SEO_AREA_INDEX_SERVICE_PAGES`); set either to false to
return to the proof/demand gates. GenerateSitemap follows the same policy, so a flip
changes the sitemap on the next `sitemap:generate`. A retired town's lead-pipe page 301s
to its nearest served neighbour like every other spoke. **Pitfall:** the `area` route
binding hands every `/areas-served|areas|locations/{area}/…` closure an `AreaServed`
model; a closure that declares `string $area` gets the model coerced to its JSON (PHP
`__toString`), which silently broke ~230 legacy redirects and every lead-pipe page for two
days. Declare `AreaServed|string $area` and take `->slug`. Old id-shaped URLs answer for
good: `/projects/{id}` and id-addressed photos 301 to their slug, a review slug whose id no
longer exists answers 410 (route `missing()`), old `/testimonials/{slug}` links 301 to the
review's one real address. Search Console shows a URL's state as of its LAST crawl and recrawls
noindexed/dead URLs slowly, so its "Excluded by noindex" list lags these fixes by weeks
to months; nothing in the API purges it, and "Validate fix" on that bucket cannot pass
while deliberately noindexed area sub-pages (AreaSeoPolicy) remain — that is expected.
