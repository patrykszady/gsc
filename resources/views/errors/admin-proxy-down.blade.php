<!DOCTYPE html>
<html lang="en">
{{--
    Rendered by AdminProxyController when ss-systems (the central admin
    this /admin/* proxies to) cannot be reached. Deliberately standalone —
    NOT <x-layouts.app> — so it has no dependency on the admin backend it is
    reporting the absence of, and nothing here can itself fail to render.

    Every site shares this file, so its look comes from config: the site's
    name and logo, the admin accent ramp for the button (Tailwind's sky when
    the site sets none), and the `admin.proxy_down` palette. It used to
    hardcode J. Peterson's serif-and-teal studio look, which gs.construction
    then showed as its own.
--}}
@php
    $look = (array) config('admin.proxy_down', []);
    $accent = (array) (config('admin.accent') ?: []);
    $button = $accent[500] ?? '#0ea5e9';
    $buttonHover = $accent[600] ?? '#0284c7';
    $name = config('brand.display_name', config('brand.name', config('app.name')));
    $logo = config('admin.logo') ? asset(config('admin.logo')) : null;
@endphp
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Admin unavailable — {{ $name }}</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: {{ $look['background'] ?? '#fafafa' }};
            color: {{ $look['text'] ?? '#52525b' }};
            font-family: {{ $look['font'] ?? "-apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif" }};
            padding: 1.5rem;
        }
        .card {
            max-width: 28rem;
            text-align: center;
        }
        .logo {
            display: block;
            max-width: 11rem;
            max-height: 3.5rem;
            margin: 0 auto 1.25rem;
        }
        .eyebrow {
            font-size: 0.7rem;
            letter-spacing: 0.3em;
            text-transform: uppercase;
            color: {{ $look['eyebrow_color'] ?? '#a1a1aa' }};
            margin: 0 0 0.75rem;
        }
        h1 {
            font-family: {{ $look['heading_font'] ?? 'inherit' }};
            font-weight: {{ $look['heading_weight'] ?? '600' }};
            font-size: 1.75rem;
            line-height: 1.2;
            margin: 0 0 0.75rem;
            color: {{ $look['heading'] ?? '#18181b' }};
        }
        p {
            font-size: 0.95rem;
            line-height: 1.6;
            margin: 0 0 1.5rem;
        }
        a {
            display: inline-block;
            font-size: 0.85rem;
            font-weight: 500;
            letter-spacing: 0.02em;
            color: #fff;
            background: {{ $button }};
            text-decoration: none;
            padding: 0.7rem 1.5rem;
            border-radius: {{ $look['radius'] ?? '0.5rem' }};
        }
        a:hover { background: {{ $buttonHover }}; }
    </style>
</head>
<body>
    <div class="card">
        @if($logo)
            <img class="logo" src="{{ $logo }}" alt="{{ $name }}" />
        @else
            <p class="eyebrow">{{ $look['eyebrow'] ?? 'Admin' }}</p>
        @endif
        <h1>Admin is temporarily unavailable</h1>
        <p>
            We could not reach the admin service just now. Nothing on the
            public site is affected — please try again in a few minutes.
        </p>
        <a href="/">Return to the site</a>
    </div>
</body>
</html>
