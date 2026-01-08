<div>
    
    <?php
        $breadcrumbItems = [
            ['name' => 'Areas Served', 'url' => route('areas.index')],
            ['name' => $area->city, 'url' => $area->url],
        ];
        
        if ($page !== 'home') {
            $pageNames = [
                'contact' => 'Contact',
                'testimonials' => 'Testimonials',
                'projects' => 'Projects',
                'about' => 'About',
                'services' => 'Services',
            ];
            $breadcrumbItems[] = ['name' => $pageNames[$page] ?? ucfirst($page)];
        }
    ?>
    <?php if (isset($component)) { $__componentOriginal4f890e046689735d2e8d34b0645836b5 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4f890e046689735d2e8d34b0645836b5 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.breadcrumb-schema','data' => ['items' => $breadcrumbItems]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('breadcrumb-schema'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($breadcrumbItems)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal4f890e046689735d2e8d34b0645836b5)): ?>
<?php $attributes = $__attributesOriginal4f890e046689735d2e8d34b0645836b5; ?>
<?php unset($__attributesOriginal4f890e046689735d2e8d34b0645836b5); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal4f890e046689735d2e8d34b0645836b5)): ?>
<?php $component = $__componentOriginal4f890e046689735d2e8d34b0645836b5; ?>
<?php unset($__componentOriginal4f890e046689735d2e8d34b0645836b5); ?>
<?php endif; ?>

    
    <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
        <nav class="flex" aria-label="Breadcrumb">
            <ol role="list" class="flex items-center space-x-2 text-sm">
                <li>
                    <a href="/" wire:navigate class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">Home</a>
                </li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    <a href="<?php echo e(route('areas.index')); ?>" wire:navigate class="ml-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">Areas Served</a>
                </li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($page === 'home'): ?>
                        <span class="ml-2 text-gray-700 dark:text-gray-300"><?php echo e($area->city); ?></span>
                    <?php else: ?>
                        <a href="<?php echo e($area->url); ?>" wire:navigate class="ml-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"><?php echo e($area->city); ?></a>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </li>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($page !== 'home'): ?>
                <li class="flex items-center">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-2 text-gray-700 dark:text-gray-300"><?php echo e(ucfirst($page)); ?></span>
                </li>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </ol>
        </nav>
    </div>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php switch($page):
        case ('home'): ?>
            
            <?php
                $homeSlides = [
                    [
                        'title' => 'Kitchens',
                        'button' => 'Kitchen Remodeling',
                        'link' => $area->pageUrl('services'),
                        'projectType' => 'kitchen',
                        'alt' => "Kitchen remodeling services in {$area->city}",
                    ],
                    [
                        'title' => 'Bathrooms',
                        'button' => 'Bathroom Remodeling',
                        'link' => $area->pageUrl('services'),
                        'projectType' => 'bathroom',
                        'alt' => "Bathroom remodeling services in {$area->city}",
                    ],
                    [
                        'title' => 'Home Remodels',
                        'button' => 'Home Remodeling',
                        'link' => $area->pageUrl('services'),
                        'projectType' => 'home-remodel',
                        'alt' => "Whole home remodeling services in {$area->city}",
                    ],
                ];
            ?>
            
            <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('main-project-hero-slider', ['slides' => $homeSlides,'area' => $area,'heading' => ''.e($area->city).' Kitchen & Bathroom Remodeling','subheading' => 'Professional remodeling services for '.e($area->city).' homeowners','secondaryCtaText' => 'Schedule Free Consult','secondaryCtaUrl' => $area->pageUrl('contact')]);

$key = null;

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2703546271-0', null);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>

            <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('about-section', ['area' => $area]);

$key = null;

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2703546271-1', null);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>

            <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('timelapse-section', []);

$key = null;

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2703546271-2', null);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>

            <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('testimonials-section', ['area' => $area]);

$key = null;

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2703546271-3', null);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>
            
            <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('map-section', []);

$key = null;

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2703546271-4', null);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>

            <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('contact-section', ['area' => $area]);

$key = null;

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2703546271-5', null);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>
            <?php break; ?>

        <?php case ('contact'): ?>
            
            <?php if (isset($component)) { $__componentOriginal2424f88af0578cc9ff2355583d50a0f4 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal2424f88af0578cc9ff2355583d50a0f4 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.cta-section','data' => ['heading' => 'Let\'s Start Your '.e($area->city).' Project','description' => 'Ready to transform your '.e($area->city).' home? Schedule a free consultation with Greg & Patryk.','primaryText' => 'About GS Construction','primaryHref' => $area->pageUrl('about'),'secondaryText' => 'View Our Work','secondaryHref' => $area->pageUrl('projects')]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('cta-section'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['heading' => 'Let\'s Start Your '.e($area->city).' Project','description' => 'Ready to transform your '.e($area->city).' home? Schedule a free consultation with Greg & Patryk.','primaryText' => 'About GS Construction','primaryHref' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($area->pageUrl('about')),'secondaryText' => 'View Our Work','secondaryHref' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($area->pageUrl('projects'))]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal2424f88af0578cc9ff2355583d50a0f4)): ?>
<?php $attributes = $__attributesOriginal2424f88af0578cc9ff2355583d50a0f4; ?>
<?php unset($__attributesOriginal2424f88af0578cc9ff2355583d50a0f4); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal2424f88af0578cc9ff2355583d50a0f4)): ?>
<?php $component = $__componentOriginal2424f88af0578cc9ff2355583d50a0f4; ?>
<?php unset($__componentOriginal2424f88af0578cc9ff2355583d50a0f4); ?>
<?php endif; ?>

            <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('contact-section', ['area' => $area]);

$key = null;

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2703546271-6', null);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>

            <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('map-section', []);

$key = null;

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2703546271-7', null);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>

            <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('testimonials-section', ['area' => $area]);

$key = null;

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2703546271-8', null);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>
            <?php break; ?>

        <?php case ('testimonials'): ?>
            
            <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('testimonials-grid', ['area' => $area]);

$key = null;

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2703546271-9', null);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>

            <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('map-section', []);

$key = null;

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2703546271-10', null);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>

            <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('testimonials-section', ['area' => $area,'showHeader' => false]);

$key = null;

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2703546271-11', null);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>
            <?php break; ?>

        <?php case ('projects'): ?>
            
            <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('timelapse-section', []);

$key = null;

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2703546271-12', null);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>

            <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('projects-grid', ['area' => $area]);

$key = null;

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2703546271-13', null);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>

            <?php if (isset($component)) { $__componentOriginal2424f88af0578cc9ff2355583d50a0f4 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal2424f88af0578cc9ff2355583d50a0f4 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.cta-section','data' => ['variant' => 'blue','heading' => 'Ready to Start Your '.e($area->city).' Project?','description' => 'Let\'s discuss your vision. Schedule a free consultation with Greg & Patryk.','primaryCtaText' => 'Schedule Free Consultation','primaryCtaUrl' => $area->pageUrl('contact'),'secondaryCtaText' => 'About Us','secondaryCtaUrl' => $area->pageUrl('about')]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('cta-section'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['variant' => 'blue','heading' => 'Ready to Start Your '.e($area->city).' Project?','description' => 'Let\'s discuss your vision. Schedule a free consultation with Greg & Patryk.','primary-cta-text' => 'Schedule Free Consultation','primary-cta-url' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($area->pageUrl('contact')),'secondary-cta-text' => 'About Us','secondary-cta-url' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($area->pageUrl('about'))]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal2424f88af0578cc9ff2355583d50a0f4)): ?>
<?php $attributes = $__attributesOriginal2424f88af0578cc9ff2355583d50a0f4; ?>
<?php unset($__attributesOriginal2424f88af0578cc9ff2355583d50a0f4); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal2424f88af0578cc9ff2355583d50a0f4)): ?>
<?php $component = $__componentOriginal2424f88af0578cc9ff2355583d50a0f4; ?>
<?php unset($__componentOriginal2424f88af0578cc9ff2355583d50a0f4); ?>
<?php endif; ?>
            <?php break; ?>

        <?php case ('about'): ?>
            
            <?php
                $galleryImages = \App\Models\ProjectImage::query()
                    ->whereHas('project')
                    ->select('project_images.*')
                    ->join(
                        \DB::raw('(SELECT MIN(id) as min_id FROM project_images GROUP BY project_id ORDER BY RAND() LIMIT 6) as unique_projects'),
                        'project_images.id', '=', 'unique_projects.min_id'
                    )
                    ->inRandomOrder()
                    ->get();
            ?>
            
            <main class="isolate">
                <!-- Hero section -->
                <div class="relative isolate -z-10">
                    <div aria-hidden="true" class="absolute inset-x-0 top-1/2 -z-10 -translate-y-1/2 transform-gpu overflow-hidden opacity-30 blur-3xl">
                        <div style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)" class="ml-[max(50%,38rem)] aspect-[1313/771] w-[82.0625rem] bg-linear-to-tr from-sky-300 to-sky-600"></div>
                    </div>
                    <div aria-hidden="true" class="absolute inset-x-0 top-0 -z-10 flex transform-gpu overflow-hidden pt-32 opacity-25 blur-3xl sm:pt-40 xl:justify-end">
                        <div style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)" class="ml-[-22rem] aspect-[1313/771] w-[82.0625rem] flex-none origin-top-right rotate-[30deg] bg-linear-to-tr from-sky-300 to-sky-600 xl:mr-[calc(50%-12rem)] xl:ml-0"></div>
                    </div>
                    
                    <div class="overflow-hidden">
                        <div class="mx-auto max-w-7xl px-6 pt-12 pb-16 sm:pt-16 lg:px-8 lg:pt-12">
                            <div class="mx-auto max-w-2xl gap-x-14 lg:mx-0 lg:flex lg:max-w-none lg:items-center">
                                <div class="relative w-full lg:max-w-xl lg:shrink-0 xl:max-w-2xl">
                                    <p class="text-sm font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">About Us</p>
                                    <h1 class="font-heading mt-2 text-4xl font-bold tracking-tight text-zinc-900 sm:text-6xl dark:text-white">
                                        Serving <?php echo e($area->city); ?> with Quality Craftsmanship
                                    </h1>
                                    <p class="mt-8 text-lg font-medium text-zinc-600 sm:max-w-md sm:text-xl/8 lg:max-w-none dark:text-zinc-300">
                                        GS Construction & Remodeling is a family business serving <?php echo e($area->city); ?> homeowners. Run by Gregory and Patryk, a father-son duo with over 40 years of combined experience.
                                    </p>
                                    <p class="mt-4 text-base text-zinc-500 sm:max-w-md lg:max-w-none dark:text-zinc-400">
                                        From the initial consultation to the final walkthrough, we're personally involved in your <?php echo e($area->city); ?> project. We believe in building lasting relationships with our clients, not just beautiful spaces.
                                    </p>
                                </div>
                                
                                
                                <div class="mt-14 flex justify-end gap-4 sm:-mt-44 sm:justify-start sm:pl-20 lg:mt-0 lg:pl-0">
                                    <div class="ml-auto w-40 flex-none space-y-4 pt-32 sm:ml-0 sm:pt-80 lg:order-last lg:pt-36 xl:order-0 xl:pt-80">
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($galleryImages->count() > 0): ?>
                                        <div class="relative">
                                            <img src="<?php echo e($galleryImages[0]->getThumbnailUrl('medium')); ?>" alt="<?php echo e($galleryImages[0]->seo_alt_text); ?>" class="aspect-square w-full rounded-xl bg-zinc-900/5 object-cover shadow-lg dark:bg-zinc-700/5" loading="lazy" />
                                            <div class="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-zinc-900/10 ring-inset dark:ring-white/10"></div>
                                        </div>
                                        <?php endif; ?>
                                        <?php if($galleryImages->count() > 5): ?>
                                        <div class="relative">
                                            <img src="<?php echo e($galleryImages[5]->getThumbnailUrl('medium')); ?>" alt="<?php echo e($galleryImages[5]->seo_alt_text); ?>" class="aspect-square w-full rounded-xl bg-zinc-900/5 object-cover shadow-lg dark:bg-zinc-700/5" loading="lazy" />
                                            <div class="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-zinc-900/10 ring-inset dark:ring-white/10"></div>
                                        </div>
                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    </div>
                                    <div class="mr-auto w-40 flex-none space-y-4 sm:mr-0 sm:pt-52 lg:pt-36">
                                        <?php if($galleryImages->count() > 1): ?>
                                        <div class="relative">
                                            <img src="<?php echo e($galleryImages[1]->getThumbnailUrl('medium')); ?>" alt="<?php echo e($galleryImages[1]->seo_alt_text); ?>" class="aspect-square w-full rounded-xl bg-zinc-900/5 object-cover shadow-lg dark:bg-zinc-700/5" loading="lazy" />
                                            <div class="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-zinc-900/10 ring-inset dark:ring-white/10"></div>
                                        </div>
                                        <?php endif; ?>
                                        <?php if($galleryImages->count() > 2): ?>
                                        <div class="relative">
                                            <img src="<?php echo e($galleryImages[2]->getThumbnailUrl('medium')); ?>" alt="<?php echo e($galleryImages[2]->seo_alt_text); ?>" class="aspect-square w-full rounded-xl bg-zinc-900/5 object-cover shadow-lg dark:bg-zinc-700/5" loading="lazy" />
                                            <div class="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-zinc-900/10 ring-inset dark:ring-white/10"></div>
                                        </div>
                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    </div>
                                    <div class="w-40 flex-none space-y-4 pt-32 sm:pt-0">
                                        <?php if($galleryImages->count() > 3): ?>
                                        <div class="relative">
                                            <img src="<?php echo e($galleryImages[3]->getThumbnailUrl('medium')); ?>" alt="<?php echo e($galleryImages[3]->seo_alt_text); ?>" class="aspect-square w-full rounded-xl bg-zinc-900/5 object-cover shadow-lg dark:bg-zinc-700/5" loading="lazy" />
                                            <div class="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-zinc-900/10 ring-inset dark:ring-white/10"></div>
                                        </div>
                                        <?php endif; ?>
                                        <?php if($galleryImages->count() > 4): ?>
                                        <div class="relative">
                                            <img src="<?php echo e($galleryImages[4]->getThumbnailUrl('medium')); ?>" alt="<?php echo e($galleryImages[4]->seo_alt_text); ?>" class="aspect-square w-full rounded-xl bg-zinc-900/5 object-cover shadow-lg dark:bg-zinc-700/5" loading="lazy" />
                                            <div class="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-zinc-900/10 ring-inset dark:ring-white/10"></div>
                                        </div>
                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stats section -->
                <div class="mx-auto mt-8 max-w-7xl px-6 sm:mt-12 lg:px-8">
                    <div class="mx-auto max-w-2xl lg:mx-0 lg:max-w-none">
                        <h2 class="font-heading text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">Our Commitment to <?php echo e($area->city); ?></h2>
                        <div class="mt-6 flex flex-col gap-x-8 gap-y-20 lg:flex-row">
                            <div class="lg:w-full lg:max-w-2xl lg:flex-auto">
                                <p class="text-xl/8 text-zinc-700 dark:text-zinc-200">
                                    To transform <?php echo e($area->city); ?> houses into dream homes while building genuine relationships with every homeowner we serve.
                                </p>
                                <p class="mt-8 max-w-xl text-base/7 text-zinc-600 dark:text-zinc-400">
                                    With deep roots in <?php echo e($area->city); ?> and the greater Chicagoland area, we understand the unique needs of local homeowners. From historic home renovations to modern kitchen makeovers, we bring the same level of care and craftsmanship to every project.
                                </p>
                            </div>
                            <div class="lg:flex lg:flex-auto lg:justify-center">
                                <dl class="w-64 space-y-8 xl:w-80">
                                    <div class="flex flex-col-reverse gap-y-4">
                                        <dt class="text-base/7 text-zinc-600 dark:text-zinc-400">Years of combined experience</dt>
                                        <dd class="font-heading text-5xl font-bold tracking-tight text-zinc-900 dark:text-white">40+</dd>
                                    </div>
                                    <div class="flex flex-col-reverse gap-y-4">
                                        <dt class="text-base/7 text-zinc-600 dark:text-zinc-400">Projects completed</dt>
                                        <dd class="font-heading text-5xl font-bold tracking-tight text-zinc-900 dark:text-white">300+</dd>
                                    </div>
                                    <div class="flex flex-col-reverse gap-y-4">
                                        <dt class="text-base/7 text-zinc-600 dark:text-zinc-400">5-star reviews</dt>
                                        <dd class="font-heading text-5xl font-bold tracking-tight text-zinc-900 dark:text-white">70+</dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Greg & Patryk Section -->
                <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('about-section', ['variant' => 'team']);

$key = null;

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2703546271-14', null);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>

                <?php if (isset($component)) { $__componentOriginal2424f88af0578cc9ff2355583d50a0f4 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal2424f88af0578cc9ff2355583d50a0f4 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.cta-section','data' => ['heading' => 'Ready to Transform Your '.e($area->city).' Home?','description' => 'Let\'s discuss your project. Schedule a free consultation and see why '.e($area->city).' homeowners trust GS Construction.','primaryText' => 'Schedule Free Consultation','primaryHref' => $area->pageUrl('contact'),'secondaryText' => 'View Our Work','secondaryHref' => $area->pageUrl('projects')]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('cta-section'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['heading' => 'Ready to Transform Your '.e($area->city).' Home?','description' => 'Let\'s discuss your project. Schedule a free consultation and see why '.e($area->city).' homeowners trust GS Construction.','primaryText' => 'Schedule Free Consultation','primaryHref' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($area->pageUrl('contact')),'secondaryText' => 'View Our Work','secondaryHref' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($area->pageUrl('projects'))]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal2424f88af0578cc9ff2355583d50a0f4)): ?>
<?php $attributes = $__attributesOriginal2424f88af0578cc9ff2355583d50a0f4; ?>
<?php unset($__attributesOriginal2424f88af0578cc9ff2355583d50a0f4); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal2424f88af0578cc9ff2355583d50a0f4)): ?>
<?php $component = $__componentOriginal2424f88af0578cc9ff2355583d50a0f4; ?>
<?php unset($__componentOriginal2424f88af0578cc9ff2355583d50a0f4); ?>
<?php endif; ?>
            </main>
            <?php break; ?>

        <?php case ('services'): ?>
            
            <?php
                $serviceSlides = [
                    [
                        'heading' => $area->city . ' Kitchen Remodeling',
                        'subheading' => 'Transform your kitchen with custom cabinets, countertops, and complete renovations',
                        'type' => 'kitchen',
                    ],
                    [
                        'heading' => $area->city . ' Bathroom Remodeling',
                        'subheading' => 'Create your personal spa retreat with luxury showers, tubs, and tile work',
                        'type' => 'bathroom',
                    ],
                    [
                        'heading' => $area->city . ' Home Remodeling',
                        'subheading' => 'Complete home renovations, room additions, and open floor plans',
                        'type' => 'home-remodel',
                    ],
                ];
            ?>
            <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('main-project-hero-slider', ['projectType' => 'mixed','slides' => $serviceSlides,'primaryCtaText' => 'Get a Free Quote','primaryCtaUrl' => $area->pageUrl('contact'),'secondaryCtaText' => 'View Our Work','secondaryCtaUrl' => $area->pageUrl('projects')]);

$key = null;

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2703546271-15', null);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>

            
            <?php echo $__env->make('partials.services-grid', ['area' => $area], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            <?php break; ?>

        <?php default: ?>
            
            <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('about-section', []);

$key = null;

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2703546271-16', null);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>
    <?php endswitch; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    
    <section class="border-t border-zinc-200 bg-zinc-50 py-8 dark:border-zinc-700 dark:bg-zinc-800/50">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <p class="mb-4 text-sm font-medium text-zinc-500 dark:text-zinc-400">
                Explore <?php echo e($area->city); ?>:
            </p>
            <nav class="flex flex-wrap gap-3">
                <a href="<?php echo e($area->url); ?>" wire:navigate class="rounded-lg px-4 py-2 text-sm font-medium <?php echo e($page === 'home' ? 'bg-sky-600 text-white' : 'bg-white text-zinc-700 hover:bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700'); ?>">
                    Home
                </a>
                <a href="<?php echo e($area->pageUrl('services')); ?>" wire:navigate class="rounded-lg px-4 py-2 text-sm font-medium <?php echo e($page === 'services' ? 'bg-sky-600 text-white' : 'bg-white text-zinc-700 hover:bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700'); ?>">
                    Services
                </a>
                <a href="<?php echo e($area->pageUrl('projects')); ?>" wire:navigate class="rounded-lg px-4 py-2 text-sm font-medium <?php echo e($page === 'projects' ? 'bg-sky-600 text-white' : 'bg-white text-zinc-700 hover:bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700'); ?>">
                    Projects
                </a>
                <a href="<?php echo e($area->pageUrl('testimonials')); ?>" wire:navigate class="rounded-lg px-4 py-2 text-sm font-medium <?php echo e($page === 'testimonials' ? 'bg-sky-600 text-white' : 'bg-white text-zinc-700 hover:bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700'); ?>">
                    Testimonials
                </a>
                <a href="<?php echo e($area->pageUrl('about')); ?>" wire:navigate class="rounded-lg px-4 py-2 text-sm font-medium <?php echo e($page === 'about' ? 'bg-sky-600 text-white' : 'bg-white text-zinc-700 hover:bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700'); ?>">
                    About
                </a>
                <a href="<?php echo e($area->pageUrl('contact')); ?>" wire:navigate class="rounded-lg px-4 py-2 text-sm font-medium <?php echo e($page === 'contact' ? 'bg-sky-600 text-white' : 'bg-white text-zinc-700 hover:bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700'); ?>">
                    Contact
                </a>
            </nav>
        </div>
    </section>
</div>
<?php /**PATH /home/patryk/web/gsc/resources/views/livewire/area-page.blade.php ENDPATH**/ ?>