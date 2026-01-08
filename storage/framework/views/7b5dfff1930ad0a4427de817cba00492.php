<div>
    
    <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('main-project-hero-slider', ['projectType' => 'mixed','slides' => [
            [
                'heading' => 'Kitchen Remodeling Contractors',
                'subheading' => 'Transform your kitchen with custom cabinets, countertops, and complete renovations',
                'type' => 'kitchen',
            ],
            [
                'heading' => 'Bathroom Remodeling Contractors',
                'subheading' => 'Create your personal spa retreat with luxury showers, tubs, and tile work',
                'type' => 'bathroom',
            ],
            [
                'heading' => 'Home Remodeling Contractors',
                'subheading' => 'Complete home renovations, room additions, and open floor plans',
                'type' => 'home-remodel',
            ],
        ],'primaryCtaText' => 'Get a Free Quote','primaryCtaUrl' => '/contact','secondaryCtaText' => 'View Our Work','secondaryCtaUrl' => '/projects']);

$key = null;

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-3673960683-0', null);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>

    
    <section class="py-16 sm:py-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-3 lg:gap-8">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $this->services; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $service): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <div class="group relative overflow-hidden rounded-2xl bg-white shadow-lg ring-1 ring-zinc-200 transition hover:shadow-xl dark:bg-zinc-800 dark:ring-zinc-700">
                        <div class="aspect-[16/9] overflow-hidden bg-gradient-to-br <?php echo e($service['gradient']); ?>">
                            <img 
                                src="<?php echo e($service['image']); ?>" 
                                alt="<?php echo e($service['title']); ?>" 
                                class="h-full w-full object-cover transition duration-300 group-hover:scale-105"
                            >
                        </div>
                        <div class="p-6 lg:p-8">
                            <h2 class="text-xl font-bold text-zinc-900 lg:text-2xl dark:text-white">
                                <?php echo e($service['title']); ?>

                            </h2>
                            <p class="mt-3 text-sm leading-6 text-zinc-600 lg:mt-4 lg:text-base lg:leading-7 dark:text-zinc-400">
                                <?php echo e($service['description']); ?>

                            </p>
                            <ul class="mt-4 space-y-2 text-sm text-zinc-600 lg:mt-6 dark:text-zinc-400">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $service['features']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $feature): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <li class="flex items-start gap-2">
                                        <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-sky-600" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                        </svg>
                                        <span><?php echo e($feature); ?></span>
                                    </li>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </ul>
                            <div class="mt-6 lg:mt-8">
                                <a 
                                    href="/services/<?php echo e($service['slug']); ?>" 
                                    wire:navigate
                                    class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-600 lg:px-6 lg:py-3"
                                >
                                    Learn More
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                    </svg>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </div>
    </section>

    
    <?php if (isset($component)) { $__componentOriginal2424f88af0578cc9ff2355583d50a0f4 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal2424f88af0578cc9ff2355583d50a0f4 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.cta-section','data' => ['variant' => 'blue','heading' => 'Ready to Start Your Project?','description' => 'Get a free consultation and quote for your remodeling project. GS Construction is ready to bring your vision to life.','primaryText' => 'Get Free Quote','primaryHref' => ''.e(route('contact')).'','secondaryText' => 'View Our Work','secondaryHref' => ''.e(route('projects.index')).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('cta-section'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['variant' => 'blue','heading' => 'Ready to Start Your Project?','description' => 'Get a free consultation and quote for your remodeling project. GS Construction is ready to bring your vision to life.','primaryText' => 'Get Free Quote','primaryHref' => ''.e(route('contact')).'','secondaryText' => 'View Our Work','secondaryHref' => ''.e(route('projects.index')).'']); ?>
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
<?php /**PATH /home/patryk/web/gsc/resources/views/livewire/services-page.blade.php ENDPATH**/ ?>