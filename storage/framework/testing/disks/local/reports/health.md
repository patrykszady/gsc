# SEO Health

Run: 2026-09-04T03:00:42+00:00

Overall score: **33/100** (F — Critical)

| Pillar | Score |
|---|---:|
| On-page completeness | 0 |
| Internal linking | 0 |
| GBP activity | 0 |
| Local rankings | 0 |
| Freshness | 33 |

## On-page completeness (/100)

- status: no published images or service areas yet

## Internal linking (/100)

- last_full_crawl: never
- Recommended fix: Run: php artisan seo:internal-link-audit
- Note: No crawl has run for this site yet.

## GBP activity (/100)

- status: no Google Business Profile activity recorded for this site

## Local rankings (/100)

- status: no rank snapshots and no GSC page metrics yet
- Recommended fix: Run: php artisan seo:track-rankings --engine=both and ensure seo:gsc-sync is scheduled.

## Freshness (33/100)

- sitemap.xml: 0d ago
- gsc-sync log: missing
- gbp-metrics-sync log: missing
- Recommended fix: Ensure scheduler is running (php artisan schedule:work or systemd timer).
