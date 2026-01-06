<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('app.name', 'GS Construction') }}</title>

    {{-- SEO Meta Tags --}}
    <meta name="description" content="{{ $metaDescription ?? 'Professional kitchen, bathroom, and home remodeling services. GS Construction is a family-owned business serving the Chicagoland area.' }}">
    <link rel="canonical" href="{{ url()->current() }}">

    {{-- Open Graph --}}
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:title" content="{{ $title ?? config('app.name', 'GS Construction') }}">
    <meta property="og:description" content="{{ $metaDescription ?? 'Professional kitchen, bathroom, and home remodeling services. GS Construction is a family-owned business serving the Chicagoland area.' }}">
    <meta property="og:image" content="{{ asset('images/og-image.jpg') }}">
    <meta property="og:locale" content="en_US">
    <meta property="og:site_name" content="GS Construction">

    {{-- Twitter Card --}}
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title ?? config('app.name', 'GS Construction') }}">
    <meta name="twitter:description" content="{{ $metaDescription ?? 'Professional kitchen, bathroom, and home remodeling services. GS Construction is a family-owned business serving the Chicagoland area.' }}">
    <meta name="twitter:image" content="{{ asset('images/og-image.jpg') }}">

    {{-- Additional SEO --}}
    <meta name="robots" content="index, follow">
    <meta name="author" content="GS Construction">
    <meta name="geo.region" content="US-IL">
    <meta name="geo.placename" content="Chicago">

    {{-- Favicons --}}
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

    {{-- Fonts (local + preloaded) --}}
    <link rel="preload" as="font" type="font/woff2" href="{{ Vite::asset('node_modules/@fontsource-variable/source-sans-3/files/source-sans-3-latin-wght-normal.woff2') }}" crossorigin>
    <link rel="preload" as="font" type="font/woff2" href="{{ Vite::asset('node_modules/@fontsource-variable/source-sans-3/files/source-sans-3-latin-ext-wght-normal.woff2') }}" crossorigin>
    <link rel="preload" as="font" type="font/woff2" href="{{ Vite::asset('node_modules/@fontsource-variable/roboto-slab/files/roboto-slab-latin-wght-normal.woff2') }}" crossorigin>
    <link rel="preload" as="font" type="font/woff2" href="{{ Vite::asset('node_modules/@fontsource-variable/roboto-slab/files/roboto-slab-latin-ext-wght-normal.woff2') }}" crossorigin>

    {{-- Styles --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance

    {{-- Google Analytics --}}
    @if(config('services.google.analytics_id'))
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ config('services.google.analytics_id') }}"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', '{{ config('services.google.analytics_id') }}');
        </script>
    @endif

    {{-- Microsoft Clarity --}}
    @if(config('services.microsoft.clarity_id'))
        <script type="text/javascript">
            (function(c,l,a,r,i,t,y){
                c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
                t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
                y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
            })(window, document, "clarity", "script", "{{ config('services.microsoft.clarity_id') }}");
        </script>
    @endif

    {{-- Google Places API for address autocomplete (new async loading pattern) --}}
    @if(config('services.google.places_api_key'))
        <script>
            (g=>{var h,a,k,p="The Google Maps JavaScript API",c="google",l="importLibrary",q="__ib__",m=document,b=window;b=b[c]||(b[c]={});var d=b.maps||(b.maps={}),r=new Set,e=new URLSearchParams,u=()=>h||(h=new Promise(async(f,n)=>{await (a=m.createElement("script"));e.set("libraries",[...r]+"");for(k in g)e.set(k.replace(/[A-Z]/g,t=>"_"+t[0].toLowerCase()),g[k]);e.set("callback",c+".maps."+q);a.src=`https://maps.googleapis.com/maps/api/js?`+e;d[q]=f;a.onerror=()=>h=n(Error(p+" could not load."));a.nonce=m.querySelector("script[nonce]")?.nonce||"";m.head.append(a)}));d[l]?console.warn(p+" only loads once. Ignoring:",g):d[l]=(f,...n)=>r.add(f)&&u().then(()=>d[l](f,...n))})({
                key: "{{ config('services.google.places_api_key') }}",
                v: "weekly"
            });
        </script>
    @endif

    {{-- JSON-LD Structured Data --}}
    <script type="application/ld+json">
    {
        "@@context": "https://schema.org",
        "@@type": "HomeAndConstructionBusiness",
        "name": "GS Construction & Remodeling",
        "description": "Family-owned home remodeling company specializing in kitchen, bathroom, and whole-home renovations in the Chicagoland area.",
        "url": "{{ config('app.url') }}",
        "logo": "{{ asset('images/logo.png') }}",
        "image": "{{ asset('images/og-image.jpg') }}",
        "address": {
            "@@type": "PostalAddress",
            "addressLocality": "Chicago",
            "addressRegion": "IL",
            "addressCountry": "US"
        },
        "areaServed": {
            "@@type": "State",
            "name": "Illinois"
        },
        "priceRange": "$$",
        "sameAs": [
            "{{ config('socials.facebook.url') }}",
            "{{ config('socials.instagram.url') }}",
            "{{ config('socials.google.url') }}",
            "{{ config('socials.yelp.url') }}",
            "{{ config('socials.houzz.url') }}"
        ]
    }
    </script>
</head>
<body class="min-h-screen bg-white font-sans text-zinc-900 antialiased dark:bg-slate-950 dark:text-zinc-100">
    {{-- Navbar --}}
    <livewire:navbar />

    {{-- Main content --}}
    <main>
        {{ $slot }}
    </main>

    {{-- Footer --}}
    <x-footer />

    @fluxScripts

    {{-- Analytics event tracking --}}
    @if(config('services.google.analytics_id'))
        <script>
            document.addEventListener('livewire:init', () => {
                Livewire.on('contact-form-submitted', () => {
                    console.log('[GA Event] form_submission - Contact Form');
                    gtag('event', 'form_submission', {
                        event_category: 'Contact',
                        event_label: 'Contact Form'
                    });
                });
            });
        </script>
    @endif

    {{-- Debug: Log CTA clicks to console --}}
    <script>
        window.trackCTA = function(label) {
            console.log('[GA Event] cta_click -', label);
            if (typeof gtag !== 'undefined') {
                gtag('event', 'cta_click', {
                    event_category: 'CTA',
                    event_label: label
                });
            }
        };
    </script>
</body>
</html>
