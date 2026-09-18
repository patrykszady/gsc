{{--
    This site's icons — one clear candidate per consumer, on stable URLs.

    Google shows one favicon per host and picks it from the candidates it is
    given: a square PNG that is a multiple of 48px, on a URL that does not
    change (it does not read SVG). Bing reads rel=icon and the root
    /favicon.ico, which a route serves per site. Browsers take the SVG; the
    manifest carries the 192/512 install icons. Every path is the current
    tenant's own set under public/icons/{slug}/ — see App\Support\SiteIcons.
--}}
<link rel="icon" type="image/png" sizes="96x96" href="{{ \App\Support\SiteIcons::url('favicon-96x96.png') }}">
<link rel="icon" type="image/svg+xml" href="{{ \App\Support\SiteIcons::url('favicon.svg') }}">
<link rel="shortcut icon" href="{{ \App\Support\SiteIcons::url('favicon.ico') }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ \App\Support\SiteIcons::url('apple-touch-icon.png') }}">
<link rel="manifest" href="{{ route('site.manifest') }}">
<meta name="theme-color" content="{{ \App\Support\SiteIcons::themeColor() }}">
