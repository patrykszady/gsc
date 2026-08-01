# Theme: SS Systems (`ss`)

Files here affect **ss.systems only** — they override `resources/views/` for this tenant
via the view-finder overlay. Nothing here touches gs.construction.

- Preview: **`http://ss.localhost:8003/`** (or `https://dev.ss.systems/`). The Host header
  picks the tenant, so navigation stays on this site. `?site=ss` from `127.0.0.1` still
  works and now pins for the browser session.
- Nav links live in `config/sites/ss/nav.php`. This layout renders no header yet; the file
  exists so ss never silently inherits gs.construction's eleven links when it grows one.
- Identity comes from `config/sites/ss/brand.php` — use `config('brand.*')`, never literals.
- Dark-first: the layout pins `class="dark"`, so `dark:` variants ARE the design.
- Uses the shared Tailwind bundle. Two known traps: Flux `variant="filled"` is unreadable
  on dark, and `bg-linear-to-br` was not in the compiled bundle (the CTA uses an inline
  gradient instead). Verify visually with a screenshot before claiming a style works.
- Brief: `docs/sites/ss.md`
