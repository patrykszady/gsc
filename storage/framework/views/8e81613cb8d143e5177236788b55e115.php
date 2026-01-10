<?php if (isset($component)) { $__componentOriginal5863877a5171c196453bfa0bd807e410 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal5863877a5171c196453bfa0bd807e410 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.layouts.app','data' => ['title' => 'About Us | GS Construction | Family-Owned Home Remodeling','metaDescription' => 'Meet Gregory and Patryk, the father-son team behind GS Construction. Over 40 years of combined experience in kitchen, bathroom, and home remodeling in the Chicagoland area.']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('layouts.app'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'About Us | GS Construction | Family-Owned Home Remodeling','metaDescription' => 'Meet Gregory and Patryk, the father-son team behind GS Construction. Over 40 years of combined experience in kitchen, bathroom, and home remodeling in the Chicagoland area.']); ?>
    
    <?php if (isset($component)) { $__componentOriginal4f890e046689735d2e8d34b0645836b5 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4f890e046689735d2e8d34b0645836b5 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.breadcrumb-schema','data' => ['items' => [
        ['name' => 'About'],
    ]]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('breadcrumb-schema'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute([
        ['name' => 'About'],
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
            <ol class="flex items-center space-x-2 text-sm">
                <li>
                    <a href="/" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Home</a>
                </li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-2 text-gray-700 dark:text-gray-300">About</span>
                </li>
            </ol>
        </nav>
    </div>

    <?php
        // Get one image from each of 6 different projects
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
                                A Family Business Built on Trust
                            </h1>
                            <p class="mt-8 text-lg font-medium text-zinc-600 sm:max-w-md sm:text-xl/8 lg:max-w-none dark:text-zinc-300">
                                GS Construction & Remodeling is more than a business—it's a family legacy. Run by Gregory and Patryk, a father-son duo with over 40 years of combined experience, we bring heart, skill, and dedication to every project.
                            </p>
                            <p class="mt-4 text-base text-zinc-500 sm:max-w-md lg:max-w-none dark:text-zinc-400">
                                From the initial consultation to the final walkthrough, we're personally involved in your project. We believe in building lasting relationships with our clients, not just beautiful spaces.
                            </p>
                        </div>
                        
                        
                        <div class="mt-14 flex justify-end gap-4 sm:-mt-44 sm:justify-start sm:pl-20 lg:mt-0 lg:pl-0">
                            <div class="ml-auto w-40 flex-none space-y-4 pt-32 sm:ml-0 sm:pt-80 lg:order-last lg:pt-36 xl:order-0 xl:pt-80">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($galleryImages->count() > 0): ?>
                                <div class="relative">
                                    <?php if (isset($component)) { $__componentOriginal6852e54d6130f6149b8bb6077330a894 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6852e54d6130f6149b8bb6077330a894 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.lqip-image','data' => ['image' => $galleryImages[0],'size' => 'medium','aspectRatio' => 'square','rounded' => 'xl','class' => 'w-full shadow-lg']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('lqip-image'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['image' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($galleryImages[0]),'size' => 'medium','aspectRatio' => 'square','rounded' => 'xl','class' => 'w-full shadow-lg']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6852e54d6130f6149b8bb6077330a894)): ?>
<?php $attributes = $__attributesOriginal6852e54d6130f6149b8bb6077330a894; ?>
<?php unset($__attributesOriginal6852e54d6130f6149b8bb6077330a894); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6852e54d6130f6149b8bb6077330a894)): ?>
<?php $component = $__componentOriginal6852e54d6130f6149b8bb6077330a894; ?>
<?php unset($__componentOriginal6852e54d6130f6149b8bb6077330a894); ?>
<?php endif; ?>
                                    <div class="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-zinc-900/10 ring-inset dark:ring-white/10"></div>
                                </div>
                                <?php endif; ?>
                                <?php if($galleryImages->count() > 5): ?>
                                <div class="relative">
                                    <?php if (isset($component)) { $__componentOriginal6852e54d6130f6149b8bb6077330a894 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6852e54d6130f6149b8bb6077330a894 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.lqip-image','data' => ['image' => $galleryImages[5],'size' => 'medium','aspectRatio' => 'square','rounded' => 'xl','class' => 'w-full shadow-lg']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('lqip-image'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['image' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($galleryImages[5]),'size' => 'medium','aspectRatio' => 'square','rounded' => 'xl','class' => 'w-full shadow-lg']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6852e54d6130f6149b8bb6077330a894)): ?>
<?php $attributes = $__attributesOriginal6852e54d6130f6149b8bb6077330a894; ?>
<?php unset($__attributesOriginal6852e54d6130f6149b8bb6077330a894); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6852e54d6130f6149b8bb6077330a894)): ?>
<?php $component = $__componentOriginal6852e54d6130f6149b8bb6077330a894; ?>
<?php unset($__componentOriginal6852e54d6130f6149b8bb6077330a894); ?>
<?php endif; ?>
                                    <div class="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-zinc-900/10 ring-inset dark:ring-white/10"></div>
                                </div>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                            <div class="mr-auto w-40 flex-none space-y-4 sm:mr-0 sm:pt-52 lg:pt-36">
                                <?php if($galleryImages->count() > 1): ?>
                                <div class="relative">
                                    <?php if (isset($component)) { $__componentOriginal6852e54d6130f6149b8bb6077330a894 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6852e54d6130f6149b8bb6077330a894 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.lqip-image','data' => ['image' => $galleryImages[1],'size' => 'medium','aspectRatio' => 'square','rounded' => 'xl','class' => 'w-full shadow-lg']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('lqip-image'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['image' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($galleryImages[1]),'size' => 'medium','aspectRatio' => 'square','rounded' => 'xl','class' => 'w-full shadow-lg']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6852e54d6130f6149b8bb6077330a894)): ?>
<?php $attributes = $__attributesOriginal6852e54d6130f6149b8bb6077330a894; ?>
<?php unset($__attributesOriginal6852e54d6130f6149b8bb6077330a894); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6852e54d6130f6149b8bb6077330a894)): ?>
<?php $component = $__componentOriginal6852e54d6130f6149b8bb6077330a894; ?>
<?php unset($__componentOriginal6852e54d6130f6149b8bb6077330a894); ?>
<?php endif; ?>
                                    <div class="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-zinc-900/10 ring-inset dark:ring-white/10"></div>
                                </div>
                                <?php endif; ?>
                                <?php if($galleryImages->count() > 2): ?>
                                <div class="relative">
                                    <?php if (isset($component)) { $__componentOriginal6852e54d6130f6149b8bb6077330a894 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6852e54d6130f6149b8bb6077330a894 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.lqip-image','data' => ['image' => $galleryImages[2],'size' => 'medium','aspectRatio' => 'square','rounded' => 'xl','class' => 'w-full shadow-lg']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('lqip-image'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['image' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($galleryImages[2]),'size' => 'medium','aspectRatio' => 'square','rounded' => 'xl','class' => 'w-full shadow-lg']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6852e54d6130f6149b8bb6077330a894)): ?>
<?php $attributes = $__attributesOriginal6852e54d6130f6149b8bb6077330a894; ?>
<?php unset($__attributesOriginal6852e54d6130f6149b8bb6077330a894); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6852e54d6130f6149b8bb6077330a894)): ?>
<?php $component = $__componentOriginal6852e54d6130f6149b8bb6077330a894; ?>
<?php unset($__componentOriginal6852e54d6130f6149b8bb6077330a894); ?>
<?php endif; ?>
                                    <div class="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-zinc-900/10 ring-inset dark:ring-white/10"></div>
                                </div>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                            <div class="w-40 flex-none space-y-4 pt-32 sm:pt-0">
                                <?php if($galleryImages->count() > 3): ?>
                                <div class="relative">
                                    <?php if (isset($component)) { $__componentOriginal6852e54d6130f6149b8bb6077330a894 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6852e54d6130f6149b8bb6077330a894 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.lqip-image','data' => ['image' => $galleryImages[3],'size' => 'medium','aspectRatio' => 'square','rounded' => 'xl','class' => 'w-full shadow-lg']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('lqip-image'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['image' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($galleryImages[3]),'size' => 'medium','aspectRatio' => 'square','rounded' => 'xl','class' => 'w-full shadow-lg']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6852e54d6130f6149b8bb6077330a894)): ?>
<?php $attributes = $__attributesOriginal6852e54d6130f6149b8bb6077330a894; ?>
<?php unset($__attributesOriginal6852e54d6130f6149b8bb6077330a894); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6852e54d6130f6149b8bb6077330a894)): ?>
<?php $component = $__componentOriginal6852e54d6130f6149b8bb6077330a894; ?>
<?php unset($__componentOriginal6852e54d6130f6149b8bb6077330a894); ?>
<?php endif; ?>
                                    <div class="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-zinc-900/10 ring-inset dark:ring-white/10"></div>
                                </div>
                                <?php endif; ?>
                                <?php if($galleryImages->count() > 4): ?>
                                <div class="relative">
                                    <?php if (isset($component)) { $__componentOriginal6852e54d6130f6149b8bb6077330a894 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6852e54d6130f6149b8bb6077330a894 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.lqip-image','data' => ['image' => $galleryImages[4],'size' => 'medium','aspectRatio' => 'square','rounded' => 'xl','class' => 'w-full shadow-lg']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('lqip-image'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['image' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($galleryImages[4]),'size' => 'medium','aspectRatio' => 'square','rounded' => 'xl','class' => 'w-full shadow-lg']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6852e54d6130f6149b8bb6077330a894)): ?>
<?php $attributes = $__attributesOriginal6852e54d6130f6149b8bb6077330a894; ?>
<?php unset($__attributesOriginal6852e54d6130f6149b8bb6077330a894); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6852e54d6130f6149b8bb6077330a894)): ?>
<?php $component = $__componentOriginal6852e54d6130f6149b8bb6077330a894; ?>
<?php unset($__componentOriginal6852e54d6130f6149b8bb6077330a894); ?>
<?php endif; ?>
                                    <div class="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-zinc-900/10 ring-inset dark:ring-white/10"></div>
                                </div>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mission section -->
        <div class="mx-auto mt-8 max-w-7xl px-6 sm:mt-12 lg:px-8">
            <div class="mx-auto max-w-2xl lg:mx-0 lg:max-w-none">
                <h2 class="font-heading text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl dark:text-white"><?php echo e(isset($area) ? 'Our Mission in ' . $area->city : 'Our Mission'); ?></h2>
                <div class="mt-6 flex flex-col gap-x-8 gap-y-20 lg:flex-row">
                    <div class="lg:w-full lg:max-w-2xl lg:flex-auto">
                        <p class="text-xl/8 text-zinc-700 dark:text-zinc-200">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($area)): ?>
                            To transform <?php echo e($area->city); ?> houses into dream homes while building genuine relationships with every homeowner we serve. We believe that a remodel should be an exciting journey, not a stressful ordeal.
                            <?php else: ?>
                            To transform houses into dream homes while building genuine relationships with every homeowner we serve. We believe that a remodel should be an exciting journey, not a stressful ordeal.
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </p>
                        <p class="mt-8 max-w-xl text-base/7 text-zinc-600 dark:text-zinc-400">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($area)): ?>
                            With deep roots in <?php echo e($area->city); ?> and the greater Chicagoland area, we understand the unique needs of local homeowners. From historic home renovations to modern kitchen makeovers, we bring the same level of care and craftsmanship to every project—big or small.
                            <?php else: ?>
                            With roots in the Chicagoland area, we understand the unique needs of local homeowners. From historic home renovations to modern kitchen makeovers, we bring the same level of care and craftsmanship to every project—big or small.
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </p>
                        <p class="mt-4 max-w-xl text-base/7 text-zinc-600 dark:text-zinc-400">
                            Our approach is simple: treat every home as if it were our own. That means attention to detail, transparent communication, and always being on-site to ensure everything meets our high standards.
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
[$__name, $__params] = $__split('about-section', ['variant' => 'team','area' => $area ?? null]);

$key = null;

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-1415144451-0', null);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>

        <!-- Values section -->
        <div class="mx-auto mt-10 max-w-7xl px-6 sm:mt-12 lg:px-8">
            <div class="mx-auto max-w-2xl lg:mx-0">
                <h2 class="font-heading text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl dark:text-white"><?php echo e(isset($area) ? 'Our Values Serving ' . $area->city : 'Our Values'); ?></h2>
                <p class="mt-6 text-lg/8 text-zinc-600 dark:text-zinc-300">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($area)): ?>
                    These principles guide everything we do for <?php echo e($area->city); ?> homeowners, from the first phone call to the final nail.
                    <?php else: ?>
                    These principles guide everything we do, from the first phone call to the final nail.
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </p>
            </div>
            <dl class="mx-auto mt-10 grid max-w-2xl grid-cols-1 gap-x-8 gap-y-10 text-base/7 sm:grid-cols-2 lg:mx-0 lg:max-w-none lg:grid-cols-3">
                <div>
                    <dt class="font-semibold text-zinc-900 dark:text-white">Quality Craftsmanship</dt>
                    <dd class="mt-1 text-zinc-600 dark:text-zinc-400">We never cut corners. Every joint, every finish, every detail matters. Our reputation is built on work that stands the test of time.</dd>
                </div>
                <div>
                    <dt class="font-semibold text-zinc-900 dark:text-white">Transparent Communication</dt>
                    <dd class="mt-1 text-zinc-600 dark:text-zinc-400">No surprises, no hidden costs. We keep you informed at every stage, so you always know exactly what's happening with your project.</dd>
                </div>
                <div>
                    <dt class="font-semibold text-zinc-900 dark:text-white">Respect for Your Home</dt>
                    <dd class="mt-1 text-zinc-600 dark:text-zinc-400">We treat your home like our own. That means protecting your belongings, cleaning up daily, and minimizing disruption to your life.</dd>
                </div>
                <div>
                    <dt class="font-semibold text-zinc-900 dark:text-white">Personal Involvement</dt>
                    <dd class="mt-1 text-zinc-600 dark:text-zinc-400">Gregory or Patryk is on-site for every <?php echo e(isset($area) ? $area->city : ''); ?> project. You'll always have a direct line to the owners, not a middleman.</dd>
                </div>
                <div>
                    <dt class="font-semibold text-zinc-900 dark:text-white">Honest Pricing</dt>
                    <dd class="mt-1 text-zinc-600 dark:text-zinc-400">We provide detailed, upfront quotes. If something changes, we discuss it with you first. No surprise invoices, ever.</dd>
                </div>
                <div>
                    <dt class="font-semibold text-zinc-900 dark:text-white">Community First</dt>
                    <dd class="mt-1 text-zinc-600 dark:text-zinc-400">We're your neighbors. We live and work in the communities we serve, and we take pride in making <?php echo e(isset($area) ? $area->city : 'Chicagoland'); ?> homes beautiful.</dd>
                </div>
            </dl>
        </div>

        <?php if (isset($component)) { $__componentOriginal2424f88af0578cc9ff2355583d50a0f4 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal2424f88af0578cc9ff2355583d50a0f4 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.cta-section','data' => ['heading' => 'Ready to Transform Your Home?','description' => 'Let\'s discuss your project. Schedule a free consultation and see why Chicagoland homeowners trust GS Construction.','primaryText' => 'Schedule Free Consultation','primaryHref' => '/contact','secondaryText' => 'View Our Work','secondaryHref' => '/projects']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('cta-section'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['heading' => 'Ready to Transform Your Home?','description' => 'Let\'s discuss your project. Schedule a free consultation and see why Chicagoland homeowners trust GS Construction.','primaryText' => 'Schedule Free Consultation','primaryHref' => '/contact','secondaryText' => 'View Our Work','secondaryHref' => '/projects']); ?>
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
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal5863877a5171c196453bfa0bd807e410)): ?>
<?php $attributes = $__attributesOriginal5863877a5171c196453bfa0bd807e410; ?>
<?php unset($__attributesOriginal5863877a5171c196453bfa0bd807e410); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal5863877a5171c196453bfa0bd807e410)): ?>
<?php $component = $__componentOriginal5863877a5171c196453bfa0bd807e410; ?>
<?php unset($__componentOriginal5863877a5171c196453bfa0bd807e410); ?>
<?php endif; ?>
<?php /**PATH /home/patryk/web/gsc/resources/views/about.blade.php ENDPATH**/ ?>