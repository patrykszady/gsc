<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- brand.*, not app.name: this one layout administers every tenant, and
         config('app.name') is process-global. ResolveAdminSite has already
         bound the site from the URL and applied its config overlay. --}}
    <title>{{ $title ?? 'Admin' }} - {{ config('brand.display_name', config('app.name')) }}</title>

    {{-- Fonts --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    @if(config('services.google.ads_id'))
    <!-- Google Ads (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ config('services.google.ads_id') }}"></script>
    <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', '{{ config('services.google.ads_id') }}');
    </script>
    @endif
    {{-- Styles --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance

    @include('partials.admin-accent')
</head>
<body class="min-h-screen lg:h-screen lg:overflow-hidden bg-zinc-50 font-sans antialiased dark:bg-zinc-900">
    <flux:sidebar sticky collapsible class="border-r border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

        {{-- Name and logo come from the tenant being administered. The logo is
             optional: a site with no mark of its own renders the name alone,
             which is correct — showing GS Construction's logo while editing
             another business is worse than showing none. --}}
        @if (config('admin.logo'))
            <flux:sidebar.brand
                href="{{ route('admin.dashboard') }}"
                logo="{{ asset(config('admin.logo')) }}"
                logo:dark="{{ asset(config('admin.logo_dark', config('admin.logo'))) }}"
                name="{{ config('brand.name') }}" />
        @else
            <flux:sidebar.brand href="{{ route('admin.dashboard') }}" name="{{ config('brand.name') }}" />
        @endif

        <flux:sidebar.nav>
            <flux:sidebar.item icon="home" href="{{ route('admin.dashboard') }}" :current="request()->routeIs('admin.dashboard')">
                Dashboard
            </flux:sidebar.item>

            <flux:sidebar.item icon="inbox" href="{{ route('admin.leads.index') }}" :current="request()->routeIs('admin.leads.*')">
                Leads
            </flux:sidebar.item>

            <flux:sidebar.group heading="Content">
                <flux:sidebar.item icon="folder" href="{{ route('admin.projects.index') }}" :current="request()->routeIs('admin.projects.*') || request()->routeIs('admin.tags.*')">
                    Projects
                </flux:sidebar.item>

                <flux:sidebar.item icon="star" href="{{ route('admin.testimonials.index') }}" :current="request()->routeIs('admin.testimonials.*')">
                    Reviews
                </flux:sidebar.item>

                <flux:sidebar.item icon="map-pin" href="{{ route('admin.areas.index') }}" :current="request()->routeIs('admin.areas.*')">
                    Service Areas
                </flux:sidebar.item>

                <flux:sidebar.item icon="document-plus" href="{{ route('admin.landing-pages.index') }}" :current="request()->routeIs('admin.landing-pages.*')">
                    Landing Pages
                </flux:sidebar.item>
            </flux:sidebar.group>

            {{-- One SEO entry: SEO Reports is the hub (it already summarizes the
                 autopilot ledger + URL inspection); Autopilot and GSC Errors are
                 reachable from panels inside it as drill-downs. --}}
            <flux:sidebar.group heading="Marketing">
                <flux:sidebar.item icon="chart-bar" href="{{ route('admin.seo-reports.index') }}"
                    :current="request()->routeIs('admin.seo-reports.*') || request()->routeIs('admin.autopilot.*') || request()->routeIs('admin.gsc-errors.*')">
                    SEO
                </flux:sidebar.item>

                <flux:sidebar.item icon="share" href="{{ route('admin.social-media.index') }}" :current="request()->routeIs('admin.social-media.*')">
                    Social Media
                </flux:sidebar.item>

                <flux:sidebar.item icon="building-storefront" href="{{ route('admin.platforms.index') }}" :current="request()->routeIs('admin.platforms.*')">
                    Platforms
                </flux:sidebar.item>
            </flux:sidebar.group>

            <flux:sidebar.group heading="Site health">
                <flux:sidebar.item icon="presentation-chart-line" href="{{ route('admin.analytics.index') }}" :current="request()->routeIs('admin.analytics.*')">
                    Analytics
                </flux:sidebar.item>

                <flux:sidebar.item icon="bug-ant" href="{{ route('admin.js-errors.index') }}" :current="request()->routeIs('admin.js-errors.*')">
                    JS Errors
                </flux:sidebar.item>
            </flux:sidebar.group>
        </flux:sidebar.nav>

        <flux:sidebar.spacer />

        <flux:sidebar.nav>
            <flux:sidebar.item icon="arrow-left-start-on-rectangle" href="{{ route('home') }}">
                Back to Site
            </flux:sidebar.item>
        </flux:sidebar.nav>

        {{-- User menu --}}
        <flux:dropdown position="top" align="start" class="max-lg:hidden">
            {{-- Locally rendered initials in the tenant accent. Was a remote
                 ui-avatars.com PNG with background=0ea5e9 baked in, which no
                 amount of theming could recolour and which sent the signed-in
                 user's name to a third party on every page load. --}}
            <flux:sidebar.profile
                avatar="{{ \App\Support\Avatar::initials(auth()->user()?->name) }}"
                name="{{ auth()->user()?->name ?? 'Admin' }}" />

            <flux:menu>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <flux:menu.item icon="arrow-right-start-on-rectangle" type="submit">Logout</flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
    </flux:sidebar>

    {{-- Mobile header --}}
    <flux:header sticky class="lg:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
        <flux:spacer />
        <flux:profile avatar="{{ \App\Support\Avatar::initials(auth()->user()?->name) }}" />
    </flux:header>

    {{-- Main content --}}
    <flux:main class="lg:overflow-y-auto lg:min-h-0">
        {{ $slot }}
    </flux:main>
@if(config('services.google.ads_id'))
<script>
  document.addEventListener('click', function(e) {
    if (e.target.closest('button') && e.target.closest('button').innerText.includes("Send message")) {
      setTimeout(function () {
        var textToTrack = "Thank you for your message! We'll get back to you soon.";
        if (document.body.textContent.includes(textToTrack)) {
            gtag('event', 'conversion', {'send_to': '{{ config('services.google.ads_id') }}/{{ config('services.google.ads_conversions.form') }}'});
        }
      }, 3000);
    }

    if(e.target.closest('a[href^="tel:"]')){
      gtag('event', 'conversion', {'send_to': '{{ config('services.google.ads_id') }}/{{ config('services.google.ads_conversions.phone') }}'});
    }
    if(e.target.closest('a[href^="mailto:"]')){
      gtag('event', 'conversion', {'send_to': '{{ config('services.google.ads_id') }}/{{ config('services.google.ads_conversions.email') }}'});
    }
  });
</script>
@endif
    @fluxScripts
</body>
</html>
