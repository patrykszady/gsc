# Project UI / Tailwind guidance (Laravel)

## Stack
- Laravel 12 + Blade
- Vite
- Tailwind CSS v4.x
- Livewire v4.x + Flux UI
- Alpine.js (for lightweight interactivity)

## UI rules
- Prefer Flux components for new UI in Blade/Livewire.
- Use Tailwind utility classes (v4 syntax) for layout/spacing/typography.
- Keep markup minimal and consistent with existing styling.

## When asked for Tailwind UI components
- We use **Tailwind UI (HTML versions)** and then adapt them to Blade + Alpine.js + Flux UI.
- If the user requests **Tailwind UI** specifically, ask them to provide the Tailwind UI HTML snippet/markup (or point to a local file) before generating large component code.
- Don’t invent proprietary Tailwind UI component code from memory.

## Tailwind UI adaptation rules
- Keep the Tailwind UI structure/classes unless asked to redesign.
- Convert JS behavior to Alpine.js (`x-data`, `x-show`, `@click`, transitions) when needed.
- Prefer Flux components when the UI maps cleanly (buttons, inputs, dropdowns), otherwise keep the Tailwind UI markup.

## Files
- Tailwind entrypoint: resources/css/app.css
- Main JS: resources/js/app.js
- Vite config: vite.config.js

## SEO report generators live in the kit (2026-09-22)

The ten `seo:*` report commands (content-decay, content-gap, cwv-template,
gbp-parity, internal-link-suggest, schema-audit, area-pages-audit,
health-check, health, clarity-health) are thin wrappers over
`SsSystems\Platform\Reports\*` in `vendor/ss-systems/platform-kit` (shared
with jpeterson-design and any future tenant). Their logic, thresholds and
markdown live in the kit; this app only provides the data through the
adapters in `app/Support/Seo/Reports/` (one per kit interface, bound in
`AppServiceProvider`) and `config/seo-reports.php` is built from the kit's
`ReportRegistry`. Change a report in the kit (`~/web/ss-platform-kit`, new
version, new zip in `packages/`), never by growing logic back into a command.
`ReportCapabilities` and `SeoReportController::regenerate()` refuse a report
this site cannot run before anything executes. `KitReportsTest` pins it.
