# Theme: J. Peterson Design (`jpeterson`)

Files here affect **jpeterson-design.com only**. Skeleton pages exist for
`/` `/portfolio` `/services` `/about` `/testimonials` `/contact` — layout shared via
`components/layouts/app.blade.php` in this directory. All copy is TODO-placeholder.

- Preview: **`http://jpeterson.localhost:8003/`**. `Site::forDevHost()` deliberately
  ignores `is_active`, so this tenant is previewable before launch even though
  `jpeterson-design.com` does not resolve yet. Clicking through the nav stays on this
  site, unlike the old `?site=` override (which still works from `127.0.0.1` and now
  pins for the browser session).
- Nav links live in `config/sites/jpeterson/nav.php`, not in the layout — this theme owns
  how they look, that file owns which exist. `php artisan sites:check` fails if one 404s.
- Identity: `config/sites/jpeterson/brand.php` — all placeholders. Use `config('brand.*')`.
- **Content must come from Jenn Peterson.** Do not copy anything from the live
  jpeterson-design.com. See `docs/legal/` for why this matters here specifically.
  - One authorised exception, `/about` (2026-07-30): Jenn approved reuse of her own About
    content. Applied as facts, rewritten for the page — no verbatim prose was brought
    across, and **photography is still excluded.** See `docs/sites/jpeterson.md`. This
    exception does not generalise to other pages or to any other tenant.
- Brief: `docs/sites/jpeterson.md`
