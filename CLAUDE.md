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
bulk, request indexing, or read Core Web Vitals.

**Retired towns and dead old-site paths (2026-09-12).** `/areas-served/{town}` for a
town no longer in `areas_served` 301s to the nearest served town (same spoke) via the
bundled gazetteer (`App\Support\Areas\RetiredAreaRedirect`, hooked into the `area`
route binding in `AppServiceProvider` because a failed implicit binding 404s before any
middleware can act), or to `/areas-served` when the gazetteer does not know it.
`RedirectLegacyUrls::GONE` answers 410 for old WordPress-era paths; it runs on every
request, so nothing in it may match a live page. A method-agnostic fallback route at the
end of `routes/web.php` (off `/api` and Livewire) is what lets unmatched public URLs
reach that middleware at all.
