<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- brand.*, not app.name: this layout is used by the login page and the
         site picker, which are reached on any tenant's host. --}}
    <title>{{ $title ?? 'Login' }} - {{ config('brand.display_name', config('app.name')) }}</title>

    {{-- Fonts --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

    {{-- Styles: the SHARED admin bundle, not Theme::viteEntries().

         This is an admin surface, not a public page. The per-theme bundles
         only @source their own theme dir plus views/components, so
         views/livewire/admin/login.blade.php is outside them — and, more
         visibly, they define no --color-accent, so every Flux control on the
         login form fell back to Flux's stock accent instead of the tenant's.
         The shared bundle derives --color-accent from the sky ramp, which the
         partial below remaps per tenant. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance

    @include('partials.admin-accent')
</head>
<body class="flex min-h-screen items-center justify-center bg-zinc-100 font-sans antialiased dark:bg-zinc-900">
    <div class="w-full max-w-md">
        {{ $slot }}
    </div>
    @fluxScripts
</body>
</html>
