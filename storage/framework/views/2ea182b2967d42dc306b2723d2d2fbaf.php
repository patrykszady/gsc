<footer class="relative z-10 bg-gray-100 dark:bg-gray-900">
    <div class="mx-auto max-w-7xl px-6 py-10 lg:px-8 lg:py-12">
        <div class="xl:grid xl:grid-cols-3 xl:gap-8">
            
            <div class="space-y-6">
                
                <a href="/" wire:navigate.hover>
                    <img src="<?php echo e(asset('favicon-source.png')); ?>" alt="GS Construction" class="h-16 w-auto" />
                </a>

                
                <p class="text-sm font-bold tracking-wide text-gray-800 uppercase dark:text-white">
                    <a href="/" wire:navigate.hover class="hover:text-sky-600 dark:hover:text-sky-400">GS Construction</a>
                </p>

                
                <p class="text-sm/6 text-balance text-gray-700 dark:text-gray-300">
                    <a href="tel:8474304439" class="hover:text-sky-600 dark:hover:text-sky-400">(847) 430-4439</a><br>
                    <a href="mailto:patryk@gs.construction" class="hover:text-sky-600 dark:hover:text-sky-400">patryk@gs.construction</a>
                </p>

                
                <div class="flex gap-x-5">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = config('socials'); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $social): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <?php if (isset($component)) { $__componentOriginalf5109f209df079b3a83484e1e6310749 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf5109f209df079b3a83484e1e6310749 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::tooltip.index','data' => ['content' => ''.e($social['label']).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::tooltip'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['content' => ''.e($social['label']).'']); ?>
                            <a href="<?php echo e($social['url']); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">
                                <span class="sr-only"><?php echo e($social['label']); ?></span>
                                <?php if (isset($component)) { $__componentOriginal511d4862ff04963c3c16115c05a86a9d = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal511d4862ff04963c3c16115c05a86a9d = $attributes; } ?>
<?php $component = Illuminate\View\DynamicComponent::resolve(['component' => 'icons.social.' . $key] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('dynamic-component'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\DynamicComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'size-6']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal511d4862ff04963c3c16115c05a86a9d)): ?>
<?php $attributes = $__attributesOriginal511d4862ff04963c3c16115c05a86a9d; ?>
<?php unset($__attributesOriginal511d4862ff04963c3c16115c05a86a9d); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal511d4862ff04963c3c16115c05a86a9d)): ?>
<?php $component = $__componentOriginal511d4862ff04963c3c16115c05a86a9d; ?>
<?php unset($__componentOriginal511d4862ff04963c3c16115c05a86a9d); ?>
<?php endif; ?>
                            </a>
                         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalf5109f209df079b3a83484e1e6310749)): ?>
<?php $attributes = $__attributesOriginalf5109f209df079b3a83484e1e6310749; ?>
<?php unset($__attributesOriginalf5109f209df079b3a83484e1e6310749); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalf5109f209df079b3a83484e1e6310749)): ?>
<?php $component = $__componentOriginalf5109f209df079b3a83484e1e6310749; ?>
<?php unset($__componentOriginalf5109f209df079b3a83484e1e6310749); ?>
<?php endif; ?>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            </div>

            
            <div class="mt-16 grid grid-cols-2 gap-8 xl:col-span-2 xl:mt-0">
                <div class="md:grid md:grid-cols-2 md:gap-8">
                    
                    <div>
                        <h3 class="text-sm/6 font-semibold text-gray-900 dark:text-white">GS Construction</h3>
                        <ul role="list" class="mt-6 space-y-4">
                            <li>
                                <a href="/projects" wire:navigate.hover class="text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Projects</a>
                            </li>
                            <li>
                                <a href="/about" wire:navigate.hover class="text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">About</a>
                            </li>
                            <li>
                                <a href="/testimonials" wire:navigate.hover class="text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Reviews</a>
                            </li>
                            <li>
                                <a href="/contact" wire:navigate.hover class="text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Contact</a>
                            </li>
                            <li>
                                <a href="/areas-served" wire:navigate.hover class="text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Areas Served</a>
                            </li>
                        </ul>
                    </div>

                    
                    <div class="mt-10 md:mt-0">
                        <h3 class="text-sm/6 font-semibold text-gray-900 dark:text-white">Projects</h3>
                        <ul role="list" class="mt-6 space-y-4">
                            <li>
                                <a href="/projects?type=kitchen" wire:navigate.hover class="text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Kitchen Projects</a>
                            </li>
                            <li>
                                <a href="/projects?type=bathroom" wire:navigate.hover class="text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Bathroom Projects</a>
                            </li>
                            <li>
                                <a href="/projects?type=home-remodel" wire:navigate.hover class="text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Home Remodel Projects</a>
                            </li>
                            <li>
                                <a href="/projects?type=mudroom" wire:navigate.hover class="text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Laundry & Mudroom Projects</a>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="md:grid md:grid-cols-2 md:gap-8">
                    
                    <div>
                        <h3 class="text-sm/6 font-semibold text-gray-900 dark:text-white">Services</h3>
                        <ul role="list" class="mt-6 space-y-4">
                            <li>
                                <a href="/services/kitchen-remodeling" wire:navigate.hover class="text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Kitchen Remodeling</a>
                            </li>
                            <li>
                                <a href="/services/bathroom-remodeling" wire:navigate.hover class="text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Bathroom Remodeling</a>
                            </li>
                            <li>
                                <a href="/services/home-remodeling" wire:navigate.hover class="text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Home Remodeling</a>
                            </li>
                        </ul>
                    </div>

                    
                    <div class="mt-10 md:mt-0">
                        <h3 class="text-sm/6 font-semibold text-gray-900 dark:text-white">About</h3>
                        <ul role="list" class="mt-6 space-y-4">
                            <li>
                                <a href="/about" wire:navigate.hover class="text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">About Greg & Patryk</a>
                            </li>
                            <li>
                                <a href="/contact" wire:navigate.hover class="text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Contact Greg & Patryk</a>
                            </li>
                            
                            <li>
                                <?php if (isset($component)) { $__componentOriginal2b4bb2cd4b8f1a3c08bae49ea918b888 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal2b4bb2cd4b8f1a3c08bae49ea918b888 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::dropdown','data' => ['position' => 'top']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::dropdown'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['position' => 'top']); ?>
                                    <?php if (isset($component)) { $__componentOriginalc04b147acd0e65cc1a77f86fb0e81580 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::button.index','data' => ['variant' => 'ghost','size' => 'sm','icon:trailing' => 'chevron-up','class' => '!px-0 !text-gray-600 hover:!text-gray-900 dark:!text-gray-400 dark:hover:!text-gray-300']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['variant' => 'ghost','size' => 'sm','icon:trailing' => 'chevron-up','class' => '!px-0 !text-gray-600 hover:!text-gray-900 dark:!text-gray-400 dark:hover:!text-gray-300']); ?>
                                        Socials
                                     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580)): ?>
<?php $attributes = $__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580; ?>
<?php unset($__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalc04b147acd0e65cc1a77f86fb0e81580)): ?>
<?php $component = $__componentOriginalc04b147acd0e65cc1a77f86fb0e81580; ?>
<?php unset($__componentOriginalc04b147acd0e65cc1a77f86fb0e81580); ?>
<?php endif; ?>
                                    <?php if (isset($component)) { $__componentOriginalf7749b857446d2788d0b6ca0c63f9d3a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf7749b857446d2788d0b6ca0c63f9d3a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::menu.index','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::menu'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = config('socials'); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $social): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                            <?php if (isset($component)) { $__componentOriginal5027d420cfeeb03dd925cfc08ae44851 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal5027d420cfeeb03dd925cfc08ae44851 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::menu.item','data' => ['href' => ''.e($social['url']).'','target' => '_blank']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::menu.item'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['href' => ''.e($social['url']).'','target' => '_blank']); ?>
                                                <span class="inline-flex items-center gap-2">
                                                    <?php if (isset($component)) { $__componentOriginal511d4862ff04963c3c16115c05a86a9d = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal511d4862ff04963c3c16115c05a86a9d = $attributes; } ?>
<?php $component = Illuminate\View\DynamicComponent::resolve(['component' => 'icons.social.' . $key] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('dynamic-component'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\DynamicComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'size-4']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal511d4862ff04963c3c16115c05a86a9d)): ?>
<?php $attributes = $__attributesOriginal511d4862ff04963c3c16115c05a86a9d; ?>
<?php unset($__attributesOriginal511d4862ff04963c3c16115c05a86a9d); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal511d4862ff04963c3c16115c05a86a9d)): ?>
<?php $component = $__componentOriginal511d4862ff04963c3c16115c05a86a9d; ?>
<?php unset($__componentOriginal511d4862ff04963c3c16115c05a86a9d); ?>
<?php endif; ?>
                                                    <?php echo e($social['label']); ?>

                                                </span>
                                             <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal5027d420cfeeb03dd925cfc08ae44851)): ?>
<?php $attributes = $__attributesOriginal5027d420cfeeb03dd925cfc08ae44851; ?>
<?php unset($__attributesOriginal5027d420cfeeb03dd925cfc08ae44851); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal5027d420cfeeb03dd925cfc08ae44851)): ?>
<?php $component = $__componentOriginal5027d420cfeeb03dd925cfc08ae44851; ?>
<?php unset($__componentOriginal5027d420cfeeb03dd925cfc08ae44851); ?>
<?php endif; ?>
                                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalf7749b857446d2788d0b6ca0c63f9d3a)): ?>
<?php $attributes = $__attributesOriginalf7749b857446d2788d0b6ca0c63f9d3a; ?>
<?php unset($__attributesOriginalf7749b857446d2788d0b6ca0c63f9d3a); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalf7749b857446d2788d0b6ca0c63f9d3a)): ?>
<?php $component = $__componentOriginalf7749b857446d2788d0b6ca0c63f9d3a; ?>
<?php unset($__componentOriginalf7749b857446d2788d0b6ca0c63f9d3a); ?>
<?php endif; ?>
                                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal2b4bb2cd4b8f1a3c08bae49ea918b888)): ?>
<?php $attributes = $__attributesOriginal2b4bb2cd4b8f1a3c08bae49ea918b888; ?>
<?php unset($__attributesOriginal2b4bb2cd4b8f1a3c08bae49ea918b888); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal2b4bb2cd4b8f1a3c08bae49ea918b888)): ?>
<?php $component = $__componentOriginal2b4bb2cd4b8f1a3c08bae49ea918b888; ?>
<?php unset($__componentOriginal2b4bb2cd4b8f1a3c08bae49ea918b888); ?>
<?php endif; ?>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        
        <div class="mt-8 border-t border-gray-900/10 pt-6 dark:border-white/10">
            <p class="text-center text-xs text-gray-500 dark:text-gray-400">
                GS Construction & Remodeling, Inc. DBA GS Construction & Remodeling. DBA GS Construction. AKA Greg & Son Construction, Co. Copyright &copy; <?php echo e(date('Y')); ?>. We're just a small Construction and Remodeling Company in Chicago.
            </p>
            <p class="mt-1 text-center text-xs text-gray-500 dark:text-gray-400">
                <span class="font-medium">Grzegorz Szady: I Love You dad!</span> <span class="italic">— You encourage and challenge me to strive every day. -Patryk Szady</span>
            </p>
        </div>

        
        <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('areas-served-accordion', []);

$key = null;

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-1948268522-0', null);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>
    </div>
</footer>
<?php /**PATH /home/patryk/web/gsc/resources/views/components/footer.blade.php ENDPATH**/ ?>