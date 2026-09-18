# Sites on this platform

One Laravel app serves every site. The request host selects the tenant
(`App\Http\Middleware\ResolveSite` → `Site::current()`), and each site brings
its own theme, config overlay and scoped data.

| Slug | Primary host | Theme | Status | Brief |
| --- | --- | --- | --- | --- |
| `gsc` | gs.construction | `themes/gsc` (falls through to `resources/views`) | Live | [gsc.md](gsc.md) |
| `jpeterson` | jpeterson-design.com | `themes/jpeterson` | In build | [jpeterson.md](jpeterson.md) |

## Working on one site

```bash
# preview any tenant locally — one host per site, inactive sites included.
# The Host header picks the tenant exactly as production does, so every link,
# redirect, form post and Livewire request stays on that site. Browsers map
# *.localhost to loopback themselves; no hosts file to edit.
http://gsc.localhost:8003/
http://jpeterson.localhost:8003/

# the register: every tenant, its overrides, and what a path does on each
http://127.0.0.1:8003/_sites

# validate every tenant's theme, assets, identity and nav links
php artisan sites:check

# WSL's resolver does not know .localhost — from bash, send the Host header
curl -H "Host: jpeterson.localhost" http://127.0.0.1:8003/portfolio

# still works, and now pins for the rest of the browser session
http://127.0.0.1:8003/?site=ss

# admin for a specific site
http://127.0.0.1:8003/admin/gs.construction/projects

# run a console command per tenant
php artisan tenants:run "seo:autopilot --dry-run" --site=gs.construction
php artisan tenants:run "sitemap:generate"          # every active site
```

## Adding a site

1. Row in `sites` (slug, name, theme, hosts, primary_host) — via migration, `is_active=false` until its theme ships.
2. `resources/themes/{theme}/` — override only the views that differ; everything else falls through.
3. `config/sites/{slug}/brand.php` — **must** set `'__replace' => true`. Merging inherits another business's phone, email and review-profile URLs.
4. Optional `resources/css/themes/{theme}/app.css` + a Vite input for its own styling.
5. Icons: `php artisan icons:build {slug} --from=<the client's mark.svg|png>` writes
   `public/icons/{slug}/` (favicon PNG/SVG/ICO, touch and install icons). The head, the
   `/site.webmanifest` and `/favicon.ico` routes and the schema `logo` all read that set for
   the tenant answering the host; nothing icon-shaped goes at the public root. Theme colour
   comes from `brand.theme_color` / `brand.background_color` in the site's brand.php.
6. Leads: a tenant that is not the default never inherits this deployment's
   MAIL_FROM — `App\Support\LeadInbox` sends contact-form mail to its own
   `brand.lead_email`, else its `brand.email`. Set one of them to an address the
   client actually reads; `sites:check` fails a site whose leads would land in
   gs.construction's inbox.
7. `docs/sites/{slug}.md` from the template below.
8. Cloudflare zone + Forge **alias** (never a new Forge site) + Let's Encrypt.
9. Flip `is_active` when the theme is ready — that is what makes the host resolve and become indexable.

## What goes in a tenant's sitemap

`GenerateSitemap` discovers pages from things every site shares: one global
route table and config files like `remodel-costs` or `design-partners`.
`App\Support\Seo\SitemapTenantFilter` decides which of those a given tenant
actually serves, structurally — no requests are dispatched:

- a `/services/{slug}` page needs that slug in the tenant's own
  `services-content` overlay (a shared `services` claim is not enough);
- a config-backed family (`compare`, `costs`, `insurance-claims`, `trades`,
  `permits`, `design-partners`) needs that config overridden by the tenant;
- `/portfolio` and `/testimonials` are real pages on one site and legacy
  redirects on another, so a tenant gets them when its own `nav.php` links them;
- `/blog` appears only where that tenant has a published post;
- market pages (`markets.list`) are added for the tenant that defines them.

Two consequences worth knowing. A page that is real but linked from nowhere in
`nav.php` will be left out for a non-default tenant, so link it or add a gate.
And the command refuses to write a sitemap that collapses to under a quarter of
what that tenant published last time, on the assumption that a gate broke.

## Brief template

```md
# {Name}

- **Host:** ...        **Slug:** ...        **Theme:** ...
- **Owner / contact:** who supplies content and approves copy
- **Content source:** where text and photos come from (must be supplied, never scraped)
- **Identity:** config/sites/{slug}/brand.php
- **Routes:** shared, or site-specific set
- **SEO:** GSC property, GBP location, IndexNow key — per site
- **Icons:** public/icons/{slug}/ — built from the client's mark with `icons:build`
- **Leads:** where the contact form mails (brand.lead_email / brand.email)
- **Launch checklist:** DNS · Forge alias · cert · theme · icons · is_active · sitemap · GSC verify
```

## Sites that left this platform

Listed in `config/sites.php` `'retired'`. `sites:check` skips them — there is no
theme, overlay or nav left to validate — but still fails if one is somehow
`is_active`, because its old hosts would then resolve to shared views carrying
another business's identity. The list is explicit on purpose: a brand-new tenant
also has no theme on day one and must not be mistaken for a departed one.

`ss` (ss.systems) was a tenant here until 2026-08-18. It now runs as its own
Laravel application — repo `patrykszady/ss-systems`, its own Forge site — so
its theme and config overlay were removed and its `sites` row deactivated.
The row itself is kept because it still owns tracked_404s / ai_traffic_daily
history; `Site::forHost()` matches only active sites, so the host no longer
resolves here.
