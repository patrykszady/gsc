# GS Construction & Remodeling

- **Host:** gs.construction (+ www) · **Slug:** `gsc` · **Theme:** `themes/gsc` (empty —
  falls through to `resources/views`, which is still the canonical GS site)
- **Role:** the founding tenant and the default site (`config/sites.php` → `default`),
  so console/queue/unknown hosts resolve here.
- **Identity:** `config/brand.php` (the shared default) + the 17 `config/*.php` files.
- **Special:** carries the SEO machine — autopilot ledger, RecommendationEngine, GSC/Bing/
  GBP/Clarity ingestion, sitemap + IndexNow + WebSub. All now `site_id`-scoped.
- **Care:** `areas_served` in production is curated at 83 towns on purpose
  (homewood/orland-park deliberately removed). Never sync areas local → prod.
