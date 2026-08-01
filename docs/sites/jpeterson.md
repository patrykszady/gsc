# J. Peterson Design

- **Host:** jpeterson-design.com · **Slug:** `jpeterson` · **Theme:** `themes/jpeterson`
- **Owner / contact:** Jenn Peterson (interior designer; refers clients to GS Construction)
- **Content source:** **must be supplied by Jenn.** Do not lift copy or photography from
  the existing jpeterson-design.com. The 2026-07-30 evidence-pack work exists precisely
  because copying a third party's site is the thing being defended against.
  - **Authorised exception — /about, 2026-07-30.** Patryk confirmed Jenn Peterson
    authorised reuse of her own About content. Applied as *facts* (degrees,
    certifications, office locations, founding year 2008, Das Holz Haus cabinetry
    partnership), rewritten for this page — no verbatim prose from the live site is in
    this repo. Jenn still approves the final wording before launch.
    - **Outstanding:** get that authorisation in writing (email is enough) and file it
      here. Given the open 4Ever demand letter, an undocumented verbal OK is the gap.
    - **Photography is NOT covered.** Portraits and studio images are still TODO and must
      be supplied by Jenn — do not pull images from the live site.
- **Identity:** `config/sites/jpeterson/{brand,geo,seo,socials}.php` — all `__replace`,
  all placeholders (phone `(847) 000-0000`, email `hello@jpeterson-design.com`).
- **Status:** `is_active = false`. Domain not yet on Cloudflare/Forge.
- **Pages (skeletons, all TODO-marked placeholder copy):** `/` `/portfolio` `/services`
  `/about` `/testimonials` `/contact` — all in the jpeterson theme layout.
- **Routing notes:** `/about`, `/services`, `/testimonials`, `/portfolio` are *shared
  claims* in `config/sites.php exclusive_paths` (gsc serves 200s or legacy 301s at the
  same paths). `RedirectLegacyUrls` is gsc-scoped — its /portfolio→/projects and
  /testimonials→/reviews mappings must never fire on this tenant. Contact form is a
  disabled skeleton pending per-site lead routing (audit item).

## Before launch
Real content from Jenn · brand.php with real NAP · theme build · DNS + Forge alias +
cert · `is_active = true` · sitemap · GSC property.
