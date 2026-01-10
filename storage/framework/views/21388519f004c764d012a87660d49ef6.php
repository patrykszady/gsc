<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'heading' => 'Ready to Transform Your Home?',
    'description' => 'Let\'s discuss your project. Schedule a free consultation and see why Chicagoland homeowners trust GS Construction.',
    'primaryText' => 'Schedule Free Consultation',
    'primaryHref' => '/contact',
    'secondaryText' => 'View Our Work',
    'secondaryHref' => '/projects',
    'variant' => 'default', // 'default' or 'blue'
]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter(([
    'heading' => 'Ready to Transform Your Home?',
    'description' => 'Let\'s discuss your project. Schedule a free consultation and see why Chicagoland homeowners trust GS Construction.',
    'primaryText' => 'Schedule Free Consultation',
    'primaryHref' => '/contact',
    'secondaryText' => 'View Our Work',
    'secondaryHref' => '/projects',
    'variant' => 'default', // 'default' or 'blue'
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    $isBlue = $variant === 'blue';
    $containerClasses = $isBlue 
        ? 'bg-sky-600 py-16 sm:py-24' 
        : 'relative isolate px-6 py-12 mt-8 sm:mt-12 lg:px-8';
    $headingClasses = $isBlue
        ? 'font-heading text-3xl font-bold tracking-tight text-white sm:text-4xl'
        : 'font-heading text-4xl font-bold tracking-tight text-zinc-900 sm:text-5xl dark:text-white';
    $descriptionClasses = $isBlue
        ? 'mx-auto mt-4 max-w-2xl text-lg text-white'
        : 'mx-auto mt-6 max-w-xl text-lg/8 text-zinc-600 dark:text-zinc-300';
    $buttonVariants = $isBlue
        ? ['primary' => 'white', 'secondary' => 'white-secondary']
        : ['primary' => 'primary', 'secondary' => 'secondary'];
?>

<!-- CTA section -->
<div class="<?php echo e($containerClasses); ?>">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$isBlue): ?>
    <div aria-hidden="true" class="absolute inset-x-0 top-1/2 -z-10 -translate-y-1/2 transform-gpu overflow-hidden opacity-20 blur-3xl">
        <div style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)" class="ml-[max(50%,38rem)] aspect-[1313/771] w-[82.0625rem] bg-linear-to-tr from-sky-300 to-sky-600"></div>
    </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <div class="<?php echo e($isBlue ? 'mx-auto max-w-7xl px-4 sm:px-6 lg:px-8' : 'mx-auto max-w-2xl'); ?> text-center">
        <h2 class="<?php echo e($headingClasses); ?>">
            <?php echo e($heading); ?>

        </h2>
        <p class="<?php echo e($descriptionClasses); ?>">
            <?php echo e($description); ?>

        </p>
        <div class="<?php echo e($isBlue ? 'mt-8 flex flex-col sm:flex-row gap-4 justify-center' : 'mt-10 flex items-center justify-center gap-x-6'); ?>">
            <?php if (isset($component)) { $__componentOriginalc91de951028fe2f549c3df803b776551 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc91de951028fe2f549c3df803b776551 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.buttons.cta','data' => ['href' => $primaryHref,'variant' => $buttonVariants['primary'],'size' => 'lg']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('buttons.cta'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['href' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($primaryHref),'variant' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($buttonVariants['primary']),'size' => 'lg']); ?>
                <?php echo e($primaryText); ?>

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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.buttons.cta','data' => ['href' => $secondaryHref,'variant' => $buttonVariants['secondary'],'size' => 'lg']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('buttons.cta'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['href' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($secondaryHref),'variant' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($buttonVariants['secondary']),'size' => 'lg']); ?>
                <?php echo e($secondaryText); ?>

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
<?php /**PATH /home/patryk/web/gsc/resources/views/components/cta-section.blade.php ENDPATH**/ ?>