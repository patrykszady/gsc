<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- SEOTools: Meta, OpenGraph, Twitter --}}
    {!! \Artesaos\SEOTools\Facades\SEOMeta::generate() !!}
    {!! \Artesaos\SEOTools\Facades\OpenGraph::generate() !!}
    {!! \Artesaos\SEOTools\Facades\TwitterCard::generate() !!}

    {{-- Additional SEO --}}
    <meta name="author" content="GS Construction">
    <meta name="geo.region" content="US-IL">
    <meta name="geo.placename" content="Chicago">

    {{-- Hreflang for bilingual support --}}
    <x-hreflang />

    {{-- Favicons --}}
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

    {{-- Fonts (local + preloaded - only Latin subset, ext loaded on demand via CSS) --}}
    <link rel="preload" as="font" type="font/woff2" href="{{ Vite::asset('node_modules/@fontsource-variable/source-sans-3/files/source-sans-3-latin-wght-normal.woff2') }}" crossorigin>
    <link rel="preload" as="font" type="font/woff2" href="{{ Vite::asset('node_modules/@fontsource-variable/roboto-slab/files/roboto-slab-latin-wght-normal.woff2') }}" crossorigin>

    {{-- Styles --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance

    {{-- Initialize image cache for LQIP (must be before any components) --}}
    <script>
        window.imageCache = window.imageCache || new Map();
    </script>

    {{-- Dynamic head content (preload links, etc.) --}}
    @stack('head')

    {{-- Comprehensive Schema.org Structured Data --}}
    <x-schema-org />
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
    <script>
        // Track CTA button clicks with GA4 recommended parameters
        window.trackCTA = function(buttonText, buttonLocation) {
            const eventData = {
                button_text: buttonText,
                button_location: buttonLocation || 'unknown',
                page_path: window.location.pathname,
                page_title: document.title
            };
            console.log('[GA Event] cta_click', eventData);
            if (typeof gtag !== 'undefined') {
                gtag('event', 'cta_click', eventData);
            }
        };

        // Track form interactions
        window.trackFormStart = function(formName) {
            const eventData = {
                form_name: formName,
                page_path: window.location.pathname
            };
            console.log('[GA Event] form_start', eventData);
            if (typeof gtag !== 'undefined') {
                gtag('event', 'form_start', eventData);
            }
        };
    </script>

    {{-- Deferred Third-Party Scripts (loaded after main content for better LCP) --}}
    <script>
        // Load analytics after page is interactive
        function loadDeferredScripts() {
            @if(config('services.google.analytics_id'))
            // Google Analytics
            const gaScript = document.createElement('script');
            gaScript.async = true;
            gaScript.src = 'https://www.googletagmanager.com/gtag/js?id={{ config('services.google.analytics_id') }}';
            document.head.appendChild(gaScript);
            
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            window.gtag = gtag;
            gtag('js', new Date());
            gtag('config', '{{ config('services.google.analytics_id') }}', {
                'custom_map': {
                    'dimension1': 'entry_domain',
                    'dimension2': 'domain_source'
                }
            });
            @if(isset($domainSource) && $domainSource !== 'direct')
            gtag('event', 'domain_entry', {
                'entry_domain': '{{ session('entry_domain', request()->getHost()) }}',
                'domain_source': '{{ $domainSource }}',
                'event_category': 'acquisition',
                'event_label': '{{ $domainSource }}'
            });
            @endif
            @endif

            @if(config('services.microsoft.clarity_id'))
            // Microsoft Clarity
            (function(c,l,a,r,i,t,y){
                c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
                t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
                y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
            })(window, document, "clarity", "script", "{{ config('services.microsoft.clarity_id') }}");
            @endif

            @if(config('services.google.places_api_key'))
            // Google Places API (only loads when needed via importLibrary)
            (g=>{var h,a,k,p="The Google Maps JavaScript API",c="google",l="importLibrary",q="__ib__",m=document,b=window;b=b[c]||(b[c]={});var d=b.maps||(b.maps={}),r=new Set,e=new URLSearchParams,u=()=>h||(h=new Promise(async(f,n)=>{await (a=m.createElement("script"));e.set("libraries",[...r]+"");for(k in g)e.set(k.replace(/[A-Z]/g,t=>"_"+t[0].toLowerCase()),g[k]);e.set("callback",c+".maps."+q);a.src=`https://maps.googleapis.com/maps/api/js?`+e;d[q]=f;a.onerror=()=>h=n(Error(p+" could not load."));a.nonce=m.querySelector("script[nonce]")?.nonce||"";m.head.append(a)}));d[l]?console.warn(p+" only loads once. Ignoring:",g):d[l]=(f,...n)=>r.add(f)&&u().then(()=>d[l](f,...n))})({
                key: "{{ config('services.google.places_api_key') }}",
                v: "weekly"
            });
            @endif
        }
        
        // Use requestIdleCallback for best performance, fallback to setTimeout
        if ('requestIdleCallback' in window) {
            requestIdleCallback(loadDeferredScripts, { timeout: 3000 });
        } else {
            setTimeout(loadDeferredScripts, 1500);
        }
    </script>

    @if(config('services.google.analytics_id'))
        <script>
            document.addEventListener('livewire:init', () => {
                // Track successful form submission (GA4 recommended event)
                Livewire.on('contact-form-submitted', () => {
                    const eventData = {
                        form_name: 'contact',
                        page_path: window.location.pathname,
                        currency: 'USD',
                        value: 100 // Estimated lead value
                    };
                    console.log('[GA Event] generate_lead', eventData);
                    if (typeof gtag !== 'undefined') {
                        gtag('event', 'generate_lead', eventData);
                    }
                });
            });
        </script>
    @endif
</body>
</html>
