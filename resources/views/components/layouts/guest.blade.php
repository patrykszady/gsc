<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
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
    <title>{{ $title ?? 'Login' }} - {{ config('app.name', 'GS Construction') }}</title>

    {{-- Fonts --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

    {{-- Styles --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="flex min-h-screen items-center justify-center bg-zinc-100 font-sans antialiased dark:bg-zinc-900">
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
    <div class="w-full max-w-md">
        {{ $slot }}
    </div>
    @fluxScripts
</body>
</html>
