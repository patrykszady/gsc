<div class="relative isolate bg-white pt-6 pb-6 sm:pt-10 sm:pb-10 dark:bg-gray-900">
    
    <?php if (isset($component)) { $__componentOriginal9c5f92ecf9c09c22b6615d30c4c58852 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal9c5f92ecf9c09c22b6615d30c4c58852 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.review-schema','data' => ['testimonials' => $rawTestimonials]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('review-schema'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['testimonials' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($rawTestimonials)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal9c5f92ecf9c09c22b6615d30c4c58852)): ?>
<?php $attributes = $__attributesOriginal9c5f92ecf9c09c22b6615d30c4c58852; ?>
<?php unset($__attributesOriginal9c5f92ecf9c09c22b6615d30c4c58852); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal9c5f92ecf9c09c22b6615d30c4c58852)): ?>
<?php $component = $__componentOriginal9c5f92ecf9c09c22b6615d30c4c58852; ?>
<?php unset($__componentOriginal9c5f92ecf9c09c22b6615d30c4c58852); ?>
<?php endif; ?>
    
    <div aria-hidden="true" class="absolute inset-x-0 top-1/2 -z-10 -translate-y-1/2 transform-gpu overflow-hidden opacity-30 blur-3xl">
        <div style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)" class="ml-[max(50%,38rem)] aspect-[1313/771] w-[82.0625rem] bg-gradient-to-tr from-sky-300 to-sky-600"></div>
    </div>
    <div aria-hidden="true" class="absolute inset-x-0 top-0 -z-10 flex transform-gpu overflow-hidden pt-32 opacity-25 blur-3xl sm:pt-40 xl:justify-end">
        <div style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)" class="ml-[-22rem] aspect-[1313/771] w-[82.0625rem] flex-none origin-top-right rotate-[30deg] bg-gradient-to-tr from-sky-300 to-sky-600 xl:mr-[calc(50%-12rem)] xl:ml-0"></div>
    </div>
    <div class="mx-auto max-w-7xl px-6 lg:px-8">
        
        <?php if (isset($component)) { $__componentOriginal75a5de8aceffe2b3ac09d8c29202d2fa = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal75a5de8aceffe2b3ac09d8c29202d2fa = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.testimonials-header','data' => ['area' => $area,'showSubtitle' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('testimonials-header'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['area' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($area),'show-subtitle' => true]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal75a5de8aceffe2b3ac09d8c29202d2fa)): ?>
<?php $attributes = $__attributesOriginal75a5de8aceffe2b3ac09d8c29202d2fa; ?>
<?php unset($__attributesOriginal75a5de8aceffe2b3ac09d8c29202d2fa); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal75a5de8aceffe2b3ac09d8c29202d2fa)): ?>
<?php $component = $__componentOriginal75a5de8aceffe2b3ac09d8c29202d2fa; ?>
<?php unset($__componentOriginal75a5de8aceffe2b3ac09d8c29202d2fa); ?>
<?php endif; ?>
        <?php
            // Calculate visible testimonials based on visibleRows
            // Row 1: featured (2 cols) + leftTop + rightTop = 2 from $testimonials
            // Row 2+: 4 testimonials each
            $maxVisible = 2 + (($visibleRows - 1) * 4);
            $list = $testimonials->take($maxVisible)->values();

            $leftTop = $list->get(0);
            $rightTop = $list->get(1);

            $row2 = $list->slice(2, 4)->values();
            $row3 = $list->slice(6, 4)->values();
            
            // Additional rows (row 4, 5, etc.)
            $additionalRows = [];
            for ($i = 4; $i <= $visibleRows; $i++) {
                $startIndex = 2 + (($i - 2) * 4); // row 4 starts at index 10, row 5 at 14, etc.
                $rowData = $list->slice($startIndex, 4)->values();
                if ($rowData->isNotEmpty()) {
                    $additionalRows[] = ['row' => $i, 'testimonials' => $rowData];
                }
            }
        ?>

        <div 
            x-data="{ visible: false }"
            x-intersect:enter.threshold.25="visible = true"
            :class="visible ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'"
            class="mx-auto mt-16 grid max-w-2xl grid-cols-1 gap-x-8 gap-y-8 text-sm/6 text-gray-900 transition-all duration-700 ease-out sm:mt-20 sm:grid-cols-2 lg:mx-0 lg:max-w-none lg:grid-cols-4 lg:items-stretch dark:text-gray-100"
        >
            
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($featured): ?>
                <figure class="order-first flex flex-col rounded-2xl bg-white shadow-lg ring-1 ring-gray-900/5 sm:col-span-2 lg:order-none lg:col-span-2 lg:col-start-2 lg:row-start-1 dark:bg-gray-800/75 dark:shadow-none dark:ring-white/10">
                    <blockquote class="flex-1 p-6 text-lg font-semibold tracking-tight text-gray-900 sm:p-12 sm:text-xl/8 dark:text-white">
                        <p>"<?php echo e(Str::limit($featured['description'], 220)); ?>"</p>
                    </blockquote>
                    <figcaption class="flex flex-col gap-4 border-t border-gray-900/10 px-6 py-4 dark:border-white/10">
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-4 sm:flex-nowrap">
                            <img src="<?php echo e($featured['image']); ?>" alt="<?php echo e($featured['name']); ?>" class="size-10 flex-none rounded-full bg-gray-50 object-cover dark:bg-gray-700" />
                            <div class="flex-auto">
                                <div class="font-semibold text-gray-900 dark:text-white"><?php echo e($featured['name']); ?></div>
                                <div class="text-gray-600 dark:text-gray-400">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($featured['area_slug']): ?>
                                        <a href="/areas/<?php echo e($featured['area_slug']); ?>" class="hover:text-sky-600 hover:underline dark:hover:text-sky-400"><?php echo e($featured['location']); ?></a>
                                    <?php else: ?>
                                        <?php echo e($featured['location']); ?>

                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    · <?php echo e($featured['date']); ?>

                                </div>
                            </div>
                            <img src="<?php echo e(asset('images/gs construction five starts.png')); ?>" alt="5 Stars" class="h-10 w-auto flex-none" />
                        </div>
                        <div>
                            <?php if (isset($component)) { $__componentOriginalc91de951028fe2f549c3df803b776551 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc91de951028fe2f549c3df803b776551 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.buttons.cta','data' => ['href' => ''.e(route('testimonials.show', $featured['slug'])).'','variant' => 'secondary','size' => 'sm']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('buttons.cta'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['href' => ''.e(route('testimonials.show', $featured['slug'])).'','variant' => 'secondary','size' => 'sm']); ?>
                                Show This Review
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
                    </figcaption>
                </figure>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($leftTop): ?>
                <figure class="hidden flex-col rounded-2xl bg-white p-6 shadow-lg ring-1 ring-gray-900/5 sm:flex lg:col-start-1 lg:row-start-1 dark:bg-gray-800/75 dark:shadow-none dark:ring-white/10">
                    <blockquote class="flex-1 text-gray-900 dark:text-white">
                        <p>"<?php echo e(Str::limit($leftTop['description'], 180)); ?>"</p>
                    </blockquote>
                    <figcaption class="mt-6 flex items-center gap-x-4">
                        <img src="<?php echo e($leftTop['image']); ?>" alt="<?php echo e($leftTop['name']); ?>" class="size-10 rounded-full bg-gray-50 object-cover dark:bg-gray-700" />
                        <div class="flex-auto">
                            <div class="font-semibold text-gray-900 dark:text-white"><?php echo e($leftTop['name']); ?></div>
                            <div class="text-gray-600 dark:text-gray-400">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($leftTop['area_slug']): ?>
                                    <a href="/areas/<?php echo e($leftTop['area_slug']); ?>" class="hover:text-sky-600 hover:underline dark:hover:text-sky-400"><?php echo e($leftTop['location']); ?></a>
                                <?php else: ?>
                                    <?php echo e($leftTop['location']); ?>

                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                        </div>
                    </figcaption>
                    <div class="mt-4">
                        <?php if (isset($component)) { $__componentOriginalc91de951028fe2f549c3df803b776551 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc91de951028fe2f549c3df803b776551 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.buttons.cta','data' => ['href' => ''.e(route('testimonials.show', $leftTop['slug'])).'','variant' => 'secondary','size' => 'sm']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('buttons.cta'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['href' => ''.e(route('testimonials.show', $leftTop['slug'])).'','variant' => 'secondary','size' => 'sm']); ?>
                            Show This Review
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
                </figure>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($rightTop): ?>
                <figure class="hidden flex-col rounded-2xl bg-white p-6 shadow-lg ring-1 ring-gray-900/5 lg:col-start-4 lg:row-start-1 lg:flex dark:bg-gray-800/75 dark:shadow-none dark:ring-white/10">
                    <blockquote class="flex-1 text-gray-900 dark:text-white">
                        <p>"<?php echo e(Str::limit($rightTop['description'], 180)); ?>"</p>
                    </blockquote>
                    <figcaption class="mt-6 flex items-center gap-x-4">
                        <img src="<?php echo e($rightTop['image']); ?>" alt="<?php echo e($rightTop['name']); ?>" class="size-10 rounded-full bg-gray-50 object-cover dark:bg-gray-700" />
                        <div class="flex-auto">
                            <div class="font-semibold text-gray-900 dark:text-white"><?php echo e($rightTop['name']); ?></div>
                            <div class="text-gray-600 dark:text-gray-400">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($rightTop['area_slug']): ?>
                                    <a href="/areas/<?php echo e($rightTop['area_slug']); ?>" class="hover:text-sky-600 hover:underline dark:hover:text-sky-400"><?php echo e($rightTop['location']); ?></a>
                                <?php else: ?>
                                    <?php echo e($rightTop['location']); ?>

                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                        </div>
                    </figcaption>
                    <div class="mt-4">
                        <?php if (isset($component)) { $__componentOriginalc91de951028fe2f549c3df803b776551 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc91de951028fe2f549c3df803b776551 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.buttons.cta','data' => ['href' => ''.e(route('testimonials.show', $rightTop['slug'])).'','variant' => 'secondary','size' => 'sm']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('buttons.cta'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['href' => ''.e(route('testimonials.show', $rightTop['slug'])).'','variant' => 'secondary','size' => 'sm']); ?>
                            Show This Review
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
                </figure>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $row2; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $i => $testimonial): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <figure class="<?php echo e($i >= 2 ? 'hidden lg:flex' : 'flex'); ?> flex-col rounded-2xl bg-white p-6 shadow-lg ring-1 ring-gray-900/5 lg:col-start-<?php echo e($i + 1); ?> lg:row-start-2 dark:bg-gray-800/75 dark:shadow-none dark:ring-white/10">
                    <blockquote class="flex-1 text-gray-900 dark:text-white">
                        <p>"<?php echo e(Str::limit($testimonial['description'], 190)); ?>"</p>
                    </blockquote>
                    <figcaption class="mt-6 flex items-center gap-x-4">
                        <img src="<?php echo e($testimonial['image']); ?>" alt="<?php echo e($testimonial['name']); ?>" class="size-10 rounded-full bg-gray-50 object-cover dark:bg-gray-700" />
                        <div class="flex-auto">
                            <div class="font-semibold text-gray-900 dark:text-white"><?php echo e($testimonial['name']); ?></div>
                            <div class="text-gray-600 dark:text-gray-400">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($testimonial['area_slug']): ?>
                                    <a href="/areas/<?php echo e($testimonial['area_slug']); ?>" class="hover:text-sky-600 hover:underline dark:hover:text-sky-400"><?php echo e($testimonial['location']); ?></a>
                                <?php else: ?>
                                    <?php echo e($testimonial['location']); ?>

                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                        </div>
                    </figcaption>
                    <div class="mt-4">
                        <?php if (isset($component)) { $__componentOriginalc91de951028fe2f549c3df803b776551 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc91de951028fe2f549c3df803b776551 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.buttons.cta','data' => ['href' => ''.e(route('testimonials.show', $testimonial['slug'])).'','variant' => 'secondary','size' => 'sm']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('buttons.cta'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['href' => ''.e(route('testimonials.show', $testimonial['slug'])).'','variant' => 'secondary','size' => 'sm']); ?>
                            Show This Review
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
                </figure>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $row3; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $i => $testimonial): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <figure class="hidden flex-col rounded-2xl bg-white p-6 shadow-lg ring-1 ring-gray-900/5 lg:col-start-<?php echo e($i + 1); ?> lg:row-start-3 lg:flex dark:bg-gray-800/75 dark:shadow-none dark:ring-white/10">
                    <blockquote class="flex-1 text-gray-900 dark:text-white">
                        <p>"<?php echo e(Str::limit($testimonial['description'], 190)); ?>"</p>
                    </blockquote>
                    <figcaption class="mt-6 flex items-center gap-x-4">
                        <img src="<?php echo e($testimonial['image']); ?>" alt="<?php echo e($testimonial['name']); ?>" class="size-10 rounded-full bg-gray-50 object-cover dark:bg-gray-700" />
                        <div class="flex-auto">
                            <div class="font-semibold text-gray-900 dark:text-white"><?php echo e($testimonial['name']); ?></div>
                            <div class="text-gray-600 dark:text-gray-400">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($testimonial['area_slug']): ?>
                                    <a href="/areas/<?php echo e($testimonial['area_slug']); ?>" class="hover:text-sky-600 hover:underline dark:hover:text-sky-400"><?php echo e($testimonial['location']); ?></a>
                                <?php else: ?>
                                    <?php echo e($testimonial['location']); ?>

                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                        </div>
                    </figcaption>
                    <div class="mt-4">
                        <?php if (isset($component)) { $__componentOriginalc91de951028fe2f549c3df803b776551 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc91de951028fe2f549c3df803b776551 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.buttons.cta','data' => ['href' => ''.e(route('testimonials.show', $testimonial['slug'])).'','variant' => 'secondary','size' => 'sm']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('buttons.cta'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['href' => ''.e(route('testimonials.show', $testimonial['slug'])).'','variant' => 'secondary','size' => 'sm']); ?>
                            Show This Review
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
                </figure>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $additionalRows; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $rowData): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $rowData['testimonials']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $i => $testimonial): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <figure 
                        x-data="{ shown: false }"
                        x-init="setTimeout(() => shown = true, <?php echo e($loop->parent->index * 100 + $i * 50); ?>)"
                        x-show="shown"
                        x-transition:enter="transition ease-out duration-500"
                        x-transition:enter-start="opacity-0 translate-y-8"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        class="flex flex-col rounded-2xl bg-white p-6 shadow-lg ring-1 ring-gray-900/5 lg:col-start-<?php echo e($i + 1); ?> lg:row-start-<?php echo e($rowData['row']); ?> dark:bg-gray-800/75 dark:shadow-none dark:ring-white/10"
                    >
                        <blockquote class="flex-1 text-gray-900 dark:text-white">
                            <p>"<?php echo e(Str::limit($testimonial['description'], 190)); ?>"</p>
                        </blockquote>
                        <figcaption class="mt-6 flex items-center gap-x-4">
                            <img src="<?php echo e($testimonial['image']); ?>" alt="<?php echo e($testimonial['name']); ?>" class="size-10 rounded-full bg-gray-50 object-cover dark:bg-gray-700" />
                            <div class="flex-auto">
                                <div class="font-semibold text-gray-900 dark:text-white"><?php echo e($testimonial['name']); ?></div>
                                <div class="text-gray-600 dark:text-gray-400">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($testimonial['area_slug']): ?>
                                        <a href="/areas/<?php echo e($testimonial['area_slug']); ?>" class="hover:text-sky-600 hover:underline dark:hover:text-sky-400"><?php echo e($testimonial['location']); ?></a>
                                    <?php else: ?>
                                        <?php echo e($testimonial['location']); ?>

                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                            </div>
                        </figcaption>
                        <div class="mt-4">
                            <?php if (isset($component)) { $__componentOriginalc91de951028fe2f549c3df803b776551 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc91de951028fe2f549c3df803b776551 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.buttons.cta','data' => ['href' => ''.e(route('testimonials.show', $testimonial['slug'])).'','variant' => 'secondary','size' => 'sm']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('buttons.cta'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['href' => ''.e(route('testimonials.show', $testimonial['slug'])).'','variant' => 'secondary','size' => 'sm']); ?>
                                Show This Review
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
                    </figure>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>

        
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($hasMore): ?>
        <div class="mt-12 text-center">
            <button 
                wire:click="loadMore"
                class="inline-flex items-center justify-center rounded-lg bg-sky-500 px-6 py-3 text-base font-semibold uppercase tracking-wide text-white shadow-lg transition hover:bg-sky-600"
            >
                Show More Reviews
            </button>
        </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
</div>
<?php /**PATH /home/patryk/web/gsc/resources/views/livewire/testimonials-grid.blade.php ENDPATH**/ ?>