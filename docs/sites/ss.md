# SS Systems

- **Host:** ss.systems (+ www, dev.ss.systems) · **Slug:** `ss` · **Theme:** `themes/ss`
- **Role:** the platform's own site AND the admin hub host (`ss.systems/admin/{site}/…`)
- **Owner / contact:** Greg & Patryk Szady
- **Identity:** `config/sites/ss/{brand,geo,seo}.php`
- **Email on the page:** `hello@ss.systems` — **placeholder, confirm the mailbox exists**
- **Routes:** currently only `/` (its own `themes/ss/home.blade.php`). The gsc-specific
  routes (`/compare/*`, `/permits/*`, `/costs/*`, `/trades/*`, area pages) still resolve
  on this host and render GS content — see the multi-tenant audit for per-site route sets.
- **Theme notes:** dark-first, pins `class="dark"` on `<html>`; uses the shared Tailwind
  bundle. Flux `variant="filled"` is unreadable on dark; the CTA uses an inline gradient
  because `bg-linear-to-br` was not in the compiled bundle.

## Status
Live on Forge as an alias of the gs.construction site. Cert covers apex + www.
`is_active = true` in the seed migration — **not yet deployed to production**, so prod
still serves the GS site on this host until the multi-tenant code ships.
