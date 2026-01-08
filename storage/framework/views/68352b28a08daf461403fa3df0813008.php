<header class="relative z-50 bg-white dark:bg-slate-950" x-data="{ mobileMenuOpen: false, projectsOpen: true }">
    <?php
        $homeUrl = $area ? $area->url : '/';
        $contactUrl = $area ? $area->pageUrl('contact') : '/contact';
    ?>
    <nav aria-label="Global" class="mx-auto flex max-w-7xl items-center justify-between p-6 lg:px-8">
        
        <div class="flex items-center gap-x-4">
            <a href="<?php echo e($homeUrl); ?>" wire:navigate.hover class="flex items-center gap-x-3">
                <img src="<?php echo e(asset('favicon-source.png')); ?>" alt="GS Construction" class="h-12 w-auto" />
                <span class="font-heading text-xl font-bold tracking-wide text-zinc-800 dark:text-zinc-100">GS CONSTRUCTION</span>
            </a>
        </div>

        
        <div class="flex lg:hidden">
            <button
                type="button"
                @click="mobileMenuOpen = true"
                class="-m-2.5 inline-flex items-center justify-center rounded-md p-2.5 text-gray-700 dark:text-zinc-200"
            >
                <span class="sr-only">Open main menu</span>
                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
            </button>
        </div>

        
        <div class="hidden lg:flex lg:items-center lg:gap-x-8">
            
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $navLinks; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $link): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <a href="<?php echo e($link['href']); ?>" wire:navigate.hover class="text-base <?php echo e($link['bold'] ? 'font-bold text-zinc-800 dark:text-zinc-100' : 'font-medium text-zinc-700 dark:text-zinc-200'); ?> hover:text-sky-600 dark:hover:text-sky-400"><?php echo e($link['label']); ?></a>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>

        
        <div class="hidden lg:flex lg:items-center lg:gap-x-6">
            <?php if (isset($component)) { $__componentOriginalc91de951028fe2f549c3df803b776551 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc91de951028fe2f549c3df803b776551 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.buttons.cta','data' => ['href' => $contactUrl,'size' => 'sm']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('buttons.cta'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['href' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($contactUrl),'size' => 'sm']); ?>
                Start Your Project
             <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalc91de951028fe2f549c3df803b776551)): ?>
<?php $attributes = $__attributesOriginalc91de951028fe2f549c3df803b776551; ?>
<?php unset($__attributesOriginalc91de951028fe2f549c3df803b776551); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalc91de951028fe2f549c3df803b776551)): ?>
<?php $component = $__componentOriginalc91de951028fe2f549c3df803b776551; ?>
<?php unset($__componentOriginalc91de951028fe2f549c3df803b776551); ?>
<?php endif; ?>
        </div>
    </nav>

    
    <div
        x-show="mobileMenuOpen"
        x-cloak
        class="lg:hidden"
        role="dialog"
        aria-modal="true"
    >
        
        <div
            x-show="mobileMenuOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-40 bg-gray-900/50"
            @click="mobileMenuOpen = false"
        ></div>

        
        <div
            x-show="mobileMenuOpen"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            class="fixed inset-y-0 right-0 z-50 w-full overflow-y-auto bg-white px-6 py-6 sm:max-w-sm sm:ring-1 sm:ring-gray-900/10 dark:bg-slate-950 dark:ring-white/10"
        >
            <div class="flex items-center justify-between">
                <a href="<?php echo e($homeUrl); ?>" wire:navigate class="flex items-center gap-x-3">
                    <img src="<?php echo e(asset('favicon-source.png')); ?>" alt="GS Construction" class="h-10 w-auto" />
                    <span class="font-heading text-lg font-semibold uppercase tracking-wide text-zinc-800 dark:text-zinc-100">GS CONSTRUCTION</span>
                </a>
                <button type="button" @click="mobileMenuOpen = false" class="-m-2.5 rounded-md p-2.5 text-gray-700 dark:text-zinc-200">
                    <span class="sr-only">Close menu</span>
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div class="mt-6 flow-root">
                <div class="-my-6 divide-y divide-gray-500/10 dark:divide-white/10">
                    <div class="space-y-2 py-6">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $navLinks; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $link): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <a href="<?php echo e($link['href']); ?>" wire:navigate class="-mx-3 block rounded-lg px-3 py-2 text-base/7 <?php echo e($link['bold'] ? 'font-bold' : 'font-semibold'); ?> text-gray-900 hover:bg-gray-50 dark:text-zinc-100 dark:hover:bg-white/5"><?php echo e($link['label']); ?></a>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                    <div class="py-6">
                        <?php if (isset($component)) { $__componentOriginalc91de951028fe2f549c3df803b776551 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc91de951028fe2f549c3df803b776551 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.buttons.cta','data' => ['href' => $contactUrl,'size' => 'sm','class' => 'w-full']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('buttons.cta'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['href' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($contactUrl),'size' => 'sm','class' => 'w-full']); ?>
                            Start Your Project
                         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalc91de951028fe2f549c3df803b776551)): ?>
<?php $attributes = $__attributesOriginalc91de951028fe2f549c3df803b776551; ?>
<?php unset($__attributesOriginalc91de951028fe2f549c3df803b776551); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalc91de951028fe2f549c3df803b776551)): ?>
<?php $component = $__componentOriginalc91de951028fe2f549c3df803b776551; ?>
<?php unset($__componentOriginalc91de951028fe2f549c3df803b776551); ?>
<?php endif; ?>
                        <div class="mt-4 space-y-2 text-center text-sm text-zinc-600 dark:text-zinc-300">
                            <a href="tel:8474304439" class="block hover:text-zinc-900 dark:hover:text-zinc-100">(847) 430-4439</a>
                            <a href="mailto:patryk@gs.construction" class="block hover:text-zinc-900 dark:hover:text-zinc-100">patryk@gs.construction</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>
<?php /**PATH /home/patryk/web/gsc/resources/views/livewire/navbar.blade.php ENDPATH**/ ?>