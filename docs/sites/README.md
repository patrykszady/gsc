# Sites on this platform

One Laravel app serves every site. The request host selects the tenant
(`App\Http\Middleware\ResolveSite` → `Site::current()`), and each site brings
its own theme, config overlay and scoped data.

| Slug | Primary host | Theme | Status | Brief |
| --- | --- | --- | --- | --- |
| `gsc` | gs.construction | `themes/gsc` (falls through to `resources/views`) | Live | [gsc.md](gsc.md) |
| `ss` | ss.systems | `themes/ss` | Live | [ss.md](ss.md) |
| `jpeterson` | jpeterson-design.com | `themes/jpeterson` | In build | [jpeterson.md](jpeterson.md) |

## Working on one site

```bash
# preview any tenant locally — one host per site, inactive sites included.
# The Host header picks the tenant exactly as production does, so every link,
# redirect, form post and Livewire request stays on that site. Browsers map
# *.localhost to loopback themselves; no hosts file to edit.
http://gsc.localhost:8003/
http://ss.localhost:8003/
http://jpeterson.localhost:8003/

# the register: every tenant, its overrides, and what a path does on each
http://127.0.0.1:8003/_sites

# validate every tenant's theme, assets, identity and nav links
php artisan sites:check

# WSL's resolver does not know .localhost — from bash, send the Host header
curl -H "Host: jpeterson.localhost" http://127.0.0.1:8003/portfolio

# still works, and now pins for the rest of the browser session
http://127.0.0.1:8003/?site=ss

# real hostname via Cloudflare Tunnel → this dev machine
https://dev.ss.systems/

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
5. `docs/sites/{slug}.md` from the template below.
6. Cloudflare zone + Forge **alias** (never a new Forge site) + Let's Encrypt.
7. Flip `is_active` when the theme is ready — that is what makes the host resolve and become indexable.

## Brief template

```md
# {Name}

- **Host:** ...        **Slug:** ...        **Theme:** ...
- **Owner / contact:** who supplies content and approves copy
- **Content source:** where text and photos come from (must be supplied, never scraped)
- **Identity:** config/sites/{slug}/brand.php
- **Routes:** shared, or site-specific set
- **SEO:** GSC property, GBP location, IndexNow key — per site
- **Launch checklist:** DNS · Forge alias · cert · theme · is_active · sitemap · GSC verify
```
