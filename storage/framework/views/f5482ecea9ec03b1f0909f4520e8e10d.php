<div>
    
    <?php if (isset($component)) { $__componentOriginal4f890e046689735d2e8d34b0645836b5 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4f890e046689735d2e8d34b0645836b5 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.breadcrumb-schema','data' => ['items' => [
        ['name' => 'Services', 'url' => route('services.index')],
        ['name' => $data['title']],
    ]]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('breadcrumb-schema'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute([
        ['name' => 'Services', 'url' => route('services.index')],
        ['name' => $data['title']],
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

    
    <?php if (isset($component)) { $__componentOriginal1be0e53338cb041b0bacc89e0bd16aed = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal1be0e53338cb041b0bacc89e0bd16aed = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.service-schema','data' => ['service' => $data]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('service-schema'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['service' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($data)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal1be0e53338cb041b0bacc89e0bd16aed)): ?>
<?php $attributes = $__attributesOriginal1be0e53338cb041b0bacc89e0bd16aed; ?>
<?php unset($__attributesOriginal1be0e53338cb041b0bacc89e0bd16aed); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal1be0e53338cb041b0bacc89e0bd16aed)): ?>
<?php $component = $__componentOriginal1be0e53338cb041b0bacc89e0bd16aed; ?>
<?php unset($__componentOriginal1be0e53338cb041b0bacc89e0bd16aed); ?>
<?php endif; ?>

    
    <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('main-project-hero-slider', ['projectType' => $data['projectType'],'slides' => [
            [
                'heading' => $data['heroTitle'],
                'subheading' => $data['heroSubtitle'],
                'type' => $data['projectType'],
            ],
        ],'primaryCtaText' => 'Get a Free Quote','primaryCtaUrl' => '/contact','secondaryCtaText' => 'View Our Work','secondaryCtaUrl' => '/projects']);

$key = null;

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-1114993918-0', null);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>

    
    

    
    

    
    

    
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($projects->isNotEmpty()): ?>
        <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('projects-grid', ['projectType' => $data['projectType'],'limit' => 3,'hideFilters' => true]);

$key = null;

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-1114993918-1', null);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    
    <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('testimonials-section', ['projectType' => $data['projectType']]);

$key = 'testimonials-'.$data['projectType'];

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-1114993918-2', 'testimonials-'.$data['projectType']);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>

    
    <?php if (isset($component)) { $__componentOriginal219ffd0a10bb8367a132a2fb51de569e = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal219ffd0a10bb8367a132a2fb51de569e = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.internal-links','data' => ['projects' => $projects,'currentService' => $service]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('internal-links'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['projects' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($projects),'current-service' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($service)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal219ffd0a10bb8367a132a2fb51de569e)): ?>
<?php $attributes = $__attributesOriginal219ffd0a10bb8367a132a2fb51de569e; ?>
<?php unset($__attributesOriginal219ffd0a10bb8367a132a2fb51de569e); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal219ffd0a10bb8367a132a2fb51de569e)): ?>
<?php $component = $__componentOriginal219ffd0a10bb8367a132a2fb51de569e; ?>
<?php unset($__componentOriginal219ffd0a10bb8367a132a2fb51de569e); ?>
<?php endif; ?>

    
    <?php if (isset($component)) { $__componentOriginal2424f88af0578cc9ff2355583d50a0f4 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal2424f88af0578cc9ff2355583d50a0f4 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.cta-section','data' => ['variant' => 'blue','heading' => $data['ctaHeading'],'description' => 'Get a free consultation and quote for your remodeling project. GS Construction is ready to bring your vision to life.','primaryText' => 'Get Free Quote','primaryHref' => route('contact'),'secondaryText' => 'View Our Work','secondaryHref' => route('projects.index')]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('cta-section'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['variant' => 'blue','heading' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($data['ctaHeading']),'description' => 'Get a free consultation and quote for your remodeling project. GS Construction is ready to bring your vision to life.','primaryText' => 'Get Free Quote','primaryHref' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(route('contact')),'secondaryText' => 'View Our Work','secondaryHref' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(route('projects.index'))]); ?>
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
<?php /**PATH /home/patryk/web/gsc/resources/views/livewire/service-page.blade.php ENDPATH**/ ?>