<section class="overflow-hidden bg-zinc-50 py-8 sm:py-10 dark:bg-slate-950">
    <div class="mx-auto max-w-7xl px-6 lg:px-8">
        <div class="mx-auto grid max-w-2xl grid-cols-1 gap-x-12 gap-y-8 lg:mx-0 lg:max-w-none lg:grid-cols-2 lg:items-start">
            
            <div class="lg:pr-8">
                <div class="lg:max-w-lg">
                    <p class="text-sm font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400"><?php echo e($content['label']); ?></p>
                    <h2 class="font-heading mt-2 whitespace-nowrap text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-100">
                        <?php echo e($content['heading']); ?>

                    </h2>
                    <p class="mt-4 text-lg text-zinc-700 dark:text-zinc-100">
                        <?php echo $content['intro']; ?>

                    </p>
                    <p class="mt-3 text-lg text-zinc-600 dark:text-zinc-200">
                        <?php echo e($content['body']); ?>

                    </p>

                    
                    <ul
                        x-data="{ shown: false }"
                        x-intersect:enter.once.threshold.55="setTimeout(() => shown = true, 500)"
                        class="mt-6 space-y-3 text-base text-zinc-600 dark:text-zinc-300"
                    >
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $content['features']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $index => $feature): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <li
                            x-show="shown"
                            x-transition:enter="transition ease-out duration-500 delay-<?php echo e(($index + 1) * 100); ?>"
                            x-transition:enter-start="opacity-0 translate-x-4"
                            x-transition:enter-end="opacity-100 translate-x-0"
                            class="flex items-start gap-3"
                        >
                            <svg class="mt-0.5 size-5 flex-shrink-0 text-sky-500" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd" />
                            </svg>
                            <span><?php echo e($feature); ?></span>
                        </li>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </ul>

                    
                    <div class="mt-6">
                        <?php if (isset($component)) { $__componentOriginalc04b147acd0e65cc1a77f86fb0e81580 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::button.index','data' => ['href' => ''.e($content['cta_href']).'','variant' => 'primary','class' => 'w-full font-semibold uppercase tracking-wide sm:w-auto','@click' => 'trackCTA(\''.e($content['cta_text']).'\')']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['href' => ''.e($content['cta_href']).'','variant' => 'primary','class' => 'w-full font-semibold uppercase tracking-wide sm:w-auto','@click' => 'trackCTA(\''.e($content['cta_text']).'\')']); ?>
                            <?php echo e($content['cta_text']); ?>

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
                    </div>
                </div>
            </div>

            
            <div class="lg:mt-[4.5rem] lg:pl-4">
                <img
                    src="<?php echo e(asset('images/greg-patryk.jpg')); ?>"
                    alt="Gregory and Patryk - GS Construction"
                    class="w-full max-w-lg rounded-xl shadow-xl ring-1 ring-zinc-200 dark:ring-zinc-800 lg:max-w-none"
                />
                
                <blockquote
                    x-data="{ quoteVisible: false }"
                    x-intersect:enter.once.threshold.35="setTimeout(() => quoteVisible = true, 250)"
                    x-cloak
                    :class="quoteVisible ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-2'"
                    class="mt-4 border-l-4 border-sky-500 pl-4 italic text-lg text-zinc-800 transition duration-700 ease-out dark:text-zinc-100"
                >
                    "<?php echo e($content['quote']); ?>"
                </blockquote>
            </div>
        </div>
    </div>
</section>
<?php /**PATH /home/patryk/web/gsc/resources/views/livewire/about-section.blade.php ENDPATH**/ ?>