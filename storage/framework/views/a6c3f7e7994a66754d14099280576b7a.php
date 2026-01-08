<div>
    
    <?php if (isset($component)) { $__componentOriginal4f890e046689735d2e8d34b0645836b5 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4f890e046689735d2e8d34b0645836b5 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.breadcrumb-schema','data' => ['items' => [
        ['name' => 'Areas Served'],
    ]]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('breadcrumb-schema'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute([
        ['name' => 'Areas Served'],
    ])]); ?>
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
                    <span class="ml-2 text-gray-700 dark:text-gray-300">Areas Served</span>
                </li>
            </ol>
        </nav>
    </div>

    
    <section class="relative bg-gradient-to-br from-zinc-900 to-zinc-800 py-24 sm:py-32">
        <div class="absolute inset-0 bg-[url('/images/hero-pattern.svg')] opacity-10"></div>
        <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="text-center">
                <h1 class="text-4xl font-bold tracking-tight text-white sm:text-5xl lg:text-6xl">
                    Areas We Serve
                </h1>
                <p class="mx-auto mt-6 max-w-2xl text-lg leading-8 text-zinc-300">
                    Serving homeowners throughout the Chicago Northwest Suburbs with professional kitchen, bathroom, and home remodeling services.
                </p>
            </div>
        </div>
    </section>

    
    <section class="py-16 sm:py-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="grid gap-8 sm:gap-12">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $groupedAreas; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $letter => $areas): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <div>
                        <h2 class="mb-4 text-2xl font-bold text-zinc-900 dark:text-white">
                            <?php echo e($letter); ?>

                        </h2>
                        <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $areas; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $area): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <a 
                                    href="<?php echo e($area->url); ?>" 
                                    wire:navigate
                                    class="group flex items-center gap-2 rounded-lg border border-zinc-200 bg-white p-4 transition hover:border-sky-300 hover:bg-sky-50 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-sky-600 dark:hover:bg-zinc-700"
                                >
                                    <svg class="h-5 w-5 text-sky-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    </svg>
                                    <span class="font-medium text-zinc-900 group-hover:text-sky-700 dark:text-white dark:group-hover:text-sky-400">
                                        <?php echo e($area->city); ?>

                                    </span>
                                </a>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </div>
    </section>

    
    <?php if (isset($component)) { $__componentOriginal2424f88af0578cc9ff2355583d50a0f4 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal2424f88af0578cc9ff2355583d50a0f4 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.cta-section','data' => ['variant' => 'blue','heading' => 'Don\'t See Your Area?','description' => 'We serve the entire Chicago Northwest Suburbs. Contact us to discuss your project.','primaryCtaText' => 'Contact Us','primaryCtaUrl' => '/contact']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('cta-section'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['variant' => 'blue','heading' => 'Don\'t See Your Area?','description' => 'We serve the entire Chicago Northwest Suburbs. Contact us to discuss your project.','primary-cta-text' => 'Contact Us','primary-cta-url' => '/contact']); ?>
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
</div>
<?php /**PATH /home/patryk/web/gsc/resources/views/livewire/areas-served-page.blade.php ENDPATH**/ ?>