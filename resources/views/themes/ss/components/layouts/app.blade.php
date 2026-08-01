<!DOCTYPE html>
<html lang="en" class="dark">
{{-- SS Systems layout. Overrides components/layouts/app for the ss theme only
     (view-finder overlay) — gs.construction keeps its own layout untouched.
     Dark-first by design: `class="dark"` is pinned so the shared Tailwind
     bundle's dark: variants ARE the design system here. --}}
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />

    <title>{{ $title ?? config('brand.display_name') . ' — websites, AI, and the systems behind them' }}</title>
    <meta name="description" content="{{ $description ?? config('geo.site_description') }}" />
    <link rel="canonical" href="{{ config('geo.site_url') }}{{ request()->getPathInfo() === '/' ? '' : request()->getPathInfo() }}" />
    <meta property="og:site_name" content="{{ config('brand.display_name') }}" />
    <meta property="og:title" content="{{ $title ?? config('brand.display_name') }}" />
    <meta property="og:description" content="{{ $description ?? config('geo.site_description') }}" />

    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Crect width='100' height='100' rx='20' fill='%230ea5e9'/%3E%3Ctext x='50' y='68' font-size='52' font-family='monospace' font-weight='bold' text-anchor='middle' fill='white'%3ESS%3C/text%3E%3C/svg%3E" />

    <link rel="preload" as="font" type="font/woff2" href="{{ Vite::asset('node_modules/@fontsource-variable/source-sans-3/files/source-sans-3-latin-wght-normal.woff2') }}" crossorigin>
    @vite(\App\Support\Theme::viteEntries(\App\Models\Site::current()))
    @fluxAppearance
</head>
<body class="min-h-screen bg-zinc-950 font-sans text-zinc-300 antialiased">
    {{ $slot }}

    @fluxScripts
</body>
</html>
