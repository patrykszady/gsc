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

                    
                    <ul class="mt-6 space-y-3 text-base text-zinc-600 dark:text-zinc-300">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $content['features']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $feature): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <li class="flex items-start gap-3">
                            <svg class="mt-0.5 size-5 flex-shrink-0 text-sky-500" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd" />
                            </svg>
                            <span><?php echo e($feature); ?></span>
                        </li>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </ul>

                    
                    <div class="mt-6 flex flex-wrap items-center gap-4">
                        <?php if (isset($component)) { $__componentOriginalc91de951028fe2f549c3df803b776551 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc91de951028fe2f549c3df803b776551 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.buttons.cta','data' => ['href' => ''.e($content['cta_href']).'','class' => 'w-full sm:w-auto']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('buttons.cta'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['href' => ''.e($content['cta_href']).'','class' => 'w-full sm:w-auto']); ?>
                            <?php echo e($content['cta_text']); ?>

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
                        <?php if (isset($component)) { $__componentOriginalc91de951028fe2f549c3df803b776551 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc91de951028fe2f549c3df803b776551 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.buttons.cta','data' => ['href' => '/about','variant' => 'secondary','class' => 'w-full sm:w-auto']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('buttons.cta'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['href' => '/about','variant' => 'secondary','class' => 'w-full sm:w-auto']); ?>
                            About Us
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
                </div>
            </div>

            
            <div class="lg:mt-[4.5rem] lg:pl-4">
                <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('team-photo-slider', []);

$key = null;

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-3696096364-0', null);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>
                
                <blockquote class="mt-4 border-l-4 border-sky-500 pl-4 italic text-lg text-zinc-800 dark:text-zinc-100">
                    "<?php echo e($content['quote']); ?>"
                </blockquote>
            </div>
        </div>
    </div>
</section>
<?php /**PATH /home/patryk/web/gsc/resources/views/livewire/about-section.blade.php ENDPATH**/ ?>