<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale())); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">

    <title><?php echo e($title ?? config('app.name', 'GS Construction')); ?></title>

    
    <meta name="description" content="<?php echo e($metaDescription ?? 'Professional kitchen, bathroom, and home remodeling services. GS Construction is a family-owned business serving the Chicagoland area.'); ?>">
    <link rel="canonical" href="<?php echo e(url()->current()); ?>">

    
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo e(url()->current()); ?>">
    <meta property="og:title" content="<?php echo e($title ?? config('app.name', 'GS Construction')); ?>">
    <meta property="og:description" content="<?php echo e($metaDescription ?? 'Professional kitchen, bathroom, and home remodeling services. GS Construction is a family-owned business serving the Chicagoland area.'); ?>">
    <meta property="og:image" content="<?php echo e(asset('images/og-image.jpg')); ?>">
    <meta property="og:locale" content="en_US">
    <meta property="og:site_name" content="GS Construction">

    
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo e($title ?? config('app.name', 'GS Construction')); ?>">
    <meta name="twitter:description" content="<?php echo e($metaDescription ?? 'Professional kitchen, bathroom, and home remodeling services. GS Construction is a family-owned business serving the Chicagoland area.'); ?>">
    <meta name="twitter:image" content="<?php echo e(asset('images/og-image.jpg')); ?>">

    
    <meta name="robots" content="index, follow">
    <meta name="author" content="GS Construction">
    <meta name="geo.region" content="US-IL">
    <meta name="geo.placename" content="Chicago">

    
    <link rel="icon" type="image/x-icon" href="<?php echo e(asset('favicon.ico')); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo e(asset('favicon-32x32.png')); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo e(asset('favicon-16x16.png')); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo e(asset('apple-touch-icon.png')); ?>">

    
    <link rel="preload" as="font" type="font/woff2" href="<?php echo e(Vite::asset('node_modules/@fontsource-variable/source-sans-3/files/source-sans-3-latin-wght-normal.woff2')); ?>" crossorigin>
    <link rel="preload" as="font" type="font/woff2" href="<?php echo e(Vite::asset('node_modules/@fontsource-variable/source-sans-3/files/source-sans-3-latin-ext-wght-normal.woff2')); ?>" crossorigin>
    <link rel="preload" as="font" type="font/woff2" href="<?php echo e(Vite::asset('node_modules/@fontsource-variable/roboto-slab/files/roboto-slab-latin-wght-normal.woff2')); ?>" crossorigin>
    <link rel="preload" as="font" type="font/woff2" href="<?php echo e(Vite::asset('node_modules/@fontsource-variable/roboto-slab/files/roboto-slab-latin-ext-wght-normal.woff2')); ?>" crossorigin>

    
    <?php echo app('Illuminate\Foundation\Vite')(['resources/css/app.css', 'resources/js/app.js']); ?>
    <?php echo app('flux')->fluxAppearance(); ?>


    
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(config('services.google.analytics_id')): ?>
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo e(config('services.google.analytics_id')); ?>"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', '<?php echo e(config('services.google.analytics_id')); ?>');
        </script>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(config('services.microsoft.clarity_id')): ?>
        <script type="text/javascript">
            (function(c,l,a,r,i,t,y){
                c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
                t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
                y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
            })(window, document, "clarity", "script", "<?php echo e(config('services.microsoft.clarity_id')); ?>");
        </script>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(config('services.google.places_api_key')): ?>
        <script>
            (g=>{var h,a,k,p="The Google Maps JavaScript API",c="google",l="importLibrary",q="__ib__",m=document,b=window;b=b[c]||(b[c]={});var d=b.maps||(b.maps={}),r=new Set,e=new URLSearchParams,u=()=>h||(h=new Promise(async(f,n)=>{await (a=m.createElement("script"));e.set("libraries",[...r]+"");for(k in g)e.set(k.replace(/[A-Z]/g,t=>"_"+t[0].toLowerCase()),g[k]);e.set("callback",c+".maps."+q);a.src=`https://maps.googleapis.com/maps/api/js?`+e;d[q]=f;a.onerror=()=>h=n(Error(p+" could not load."));a.nonce=m.querySelector("script[nonce]")?.nonce||"";m.head.append(a)}));d[l]?console.warn(p+" only loads once. Ignoring:",g):d[l]=(f,...n)=>r.add(f)&&u().then(()=>d[l](f,...n))})({
                key: "<?php echo e(config('services.google.places_api_key')); ?>",
                v: "weekly"
            });
        </script>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "HomeAndConstructionBusiness",
        "name": "GS Construction & Remodeling",
        "description": "Family-owned home remodeling company specializing in kitchen, bathroom, and whole-home renovations in the Chicagoland area.",
        "url": "<?php echo e(config('app.url')); ?>",
        "logo": "<?php echo e(asset('images/logo.png')); ?>",
        "image": "<?php echo e(asset('images/og-image.jpg')); ?>",
        "address": {
            "@type": "PostalAddress",
            "addressLocality": "Chicago",
            "addressRegion": "IL",
            "addressCountry": "US"
        },
        "areaServed": {
            "@type": "State",
            "name": "Illinois"
        },
        "priceRange": "$$",
        "sameAs": [
            "<?php echo e(config('socials.facebook.url')); ?>",
            "<?php echo e(config('socials.instagram.url')); ?>",
            "<?php echo e(config('socials.google.url')); ?>",
            "<?php echo e(config('socials.yelp.url')); ?>",
            "<?php echo e(config('socials.houzz.url')); ?>"
        ]
    }
    </script>
</head>
<body class="min-h-screen bg-white font-sans text-zinc-900 antialiased dark:bg-slate-950 dark:text-zinc-100">
    
    <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('navbar', []);

$key = null;

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-4059180110-0', null);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>

    
    <main>
        <?php echo e($slot); ?>

    </main>

    
    <?php if (isset($component)) { $__componentOriginal8a8716efb3c62a45938aca52e78e0322 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8a8716efb3c62a45938aca52e78e0322 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.footer','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('footer'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal8a8716efb3c62a45938aca52e78e0322)): ?>
<?php $attributes = $__attributesOriginal8a8716efb3c62a45938aca52e78e0322; ?>
<?php unset($__attributesOriginal8a8716efb3c62a45938aca52e78e0322); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal8a8716efb3c62a45938aca52e78e0322)): ?>
<?php $component = $__componentOriginal8a8716efb3c62a45938aca52e78e0322; ?>
<?php unset($__componentOriginal8a8716efb3c62a45938aca52e78e0322); ?>
<?php endif; ?>

    <?php app('livewire')->forceAssetInjection(); ?>
<?php echo app('flux')->scripts(); ?>


    
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

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(config('services.google.analytics_id')): ?>
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
                    gtag('event', 'generate_lead', eventData);
                });
            });
        </script>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</body>
</html>
<?php /**PATH /home/patryk/web/gsc/resources/views/components/layouts/app.blade.php ENDPATH**/ ?>