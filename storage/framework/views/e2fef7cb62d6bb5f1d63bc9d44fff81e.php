<?php
    $citySuffix = $area ? ' in ' . $area->city : '';
    $isServiceMode = $mode === 'service';
?>
<div
    x-data="{
        currentSlide: 0,
        areaCity: <?php echo \Illuminate\Support\Js::from($area?->city)->toHtml() ?>,
        mode: <?php echo \Illuminate\Support\Js::from($mode)->toHtml() ?>,
        projectTypeFilter: <?php echo \Illuminate\Support\Js::from($projectType)->toHtml() ?>,
        slides: <?php echo \Illuminate\Support\Js::from($renderedSlides)->toHtml() ?>,
        autoplay: null,
        isHovered: false,
        isVisible: true,
        isTabVisible: true,
        startAutoplay() {
            if (!this.isVisible || !this.isTabVisible || this.isHovered) return;
            this.stopAutoplay();
            this.autoplay = setInterval(() => this.next(), <?php echo e($isServiceMode ? 4000 : 5000); ?>);
        },
        stopAutoplay() {
            if (this.autoplay) {
                clearInterval(this.autoplay);
                this.autoplay = null;
            }
        },
        next() {
            this.currentSlide = (this.currentSlide + 1) % this.slides.length;
        },
        handleVisibility(isVisible) {
            this.isVisible = isVisible;
            if (isVisible && this.isTabVisible && !this.isHovered) {
                this.startAutoplay();
            } else {
                this.stopAutoplay();
            }
        },
        handleTabVisibility() {
            this.isTabVisible = !document.hidden;
            if (this.isTabVisible && this.isVisible && !this.isHovered) {
                this.startAutoplay();
            } else {
                this.stopAutoplay();
            }
        }
    }"
    x-init="
        startAutoplay();
        document.addEventListener('visibilitychange', () => handleTabVisibility());
    "
    x-intersect:enter.threshold.40="handleVisibility(true)"
    x-intersect:leave.threshold.40="handleVisibility(false)"
    class="relative w-full overflow-hidden"
>
    
    <div class="relative h-[500px] sm:h-[600px] lg:h-[700px]">
        <template x-for="(slide, index) in slides" :key="index">
            <div
                x-show="currentSlide === index"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="absolute inset-0"
            >
                
                <img
                    :src="slide.image"
                    :alt="slide.heading ? slide.heading : slide.alt"
                    class="absolute inset-0 h-full w-full object-cover"
                />

                
                <div class="absolute inset-0 bg-black/20"></div>
            </div>
        </template>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($isServiceMode): ?>
        
        <template x-for="(slide, index) in slides" :key="'content-' + index">
            <div
                x-show="currentSlide === index"
                x-transition:enter="transition-opacity ease-out duration-150"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition-opacity ease-in duration-100"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="absolute inset-0 flex items-end pb-16 sm:pb-20 lg:pb-24"
            >
                <div 
                    class="mx-auto w-full max-w-7xl px-6 lg:px-8"
                    @mouseenter="isHovered = true; stopAutoplay()"
                    @mouseleave="isHovered = false; startAutoplay()"
                >
                    <div class="lg:max-w-[50%]">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($label): ?>
                        <span x-show="index === 0" class="inline-flex items-center rounded-full bg-sky-500 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-white shadow-lg">
                            <?php echo e($label); ?>

                        </span>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        <h1 
                            class="mt-3 font-heading text-4xl font-bold text-white drop-shadow-lg sm:text-5xl lg:text-6xl"
                            x-text="slide.heading"
                        ></h1>
                        <p 
                            x-show="slide.subheading"
                            x-text="slide.subheading"
                            class="mt-4 text-lg text-white drop-shadow-lg sm:text-xl"
                        ></p>
                        <div class="mt-8 flex flex-wrap items-center gap-x-6 gap-y-4">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($primaryCtaUrl && $primaryCtaText): ?>
                            <?php if (isset($component)) { $__componentOriginalc91de951028fe2f549c3df803b776551 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc91de951028fe2f549c3df803b776551 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.buttons.cta','data' => ['href' => ''.e($primaryCtaUrl).'','size' => 'lg']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('buttons.cta'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['href' => ''.e($primaryCtaUrl).'','size' => 'lg']); ?>
                                <?php echo e($primaryCtaText); ?>

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
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($secondaryCtaUrl && $secondaryCtaText): ?>
                            <?php if (isset($component)) { $__componentOriginalc91de951028fe2f549c3df803b776551 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc91de951028fe2f549c3df803b776551 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.buttons.cta','data' => ['href' => ''.e($secondaryCtaUrl).'','variant' => 'secondary','size' => 'lg','onDark' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('buttons.cta'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['href' => ''.e($secondaryCtaUrl).'','variant' => 'secondary','size' => 'lg','onDark' => true]); ?>
                                <?php echo e($secondaryCtaText); ?>

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
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </template>
        <?php else: ?>
        
        <template x-for="(slide, index) in slides" :key="'content-' + index">
            <div
                x-show="currentSlide === index"
                x-transition:enter="transition-opacity ease-out duration-150"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition-opacity ease-in duration-100"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="absolute inset-0 flex items-end pb-16 sm:pb-20 lg:pb-24"
            >
                <div 
                    class="mx-auto w-full max-w-7xl px-6 lg:px-8"
                    @mouseenter="isHovered = true; stopAutoplay()"
                    @mouseleave="isHovered = false; startAutoplay()"
                >
                    <h2
                        class="font-heading text-4xl font-bold text-white drop-shadow-lg sm:text-5xl lg:text-6xl"
                        x-html="slide.title.replace('\n', '<br>')"
                    ></h2>
                    <p 
                        x-show="areaCity" 
                        x-text="'in ' + areaCity"
                        class="mt-2 text-xl font-medium text-white drop-shadow-lg sm:text-2xl"
                    ></p>
                    <div class="mt-8 flex flex-wrap items-center gap-x-6 gap-y-4">
                        <a
                            :href="slide.link"
                            class="inline-flex items-center justify-center rounded-lg bg-sky-500 px-6 py-3 text-base font-semibold uppercase tracking-wide text-white shadow-lg transition hover:bg-sky-600"
                            x-text="slide.button"
                        ></a>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($secondaryCtaUrl && $secondaryCtaText): ?>
                        <?php if (isset($component)) { $__componentOriginalc91de951028fe2f549c3df803b776551 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc91de951028fe2f549c3df803b776551 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.buttons.cta','data' => ['href' => ''.e($secondaryCtaUrl).'','variant' => 'secondary','size' => 'lg','onDark' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('buttons.cta'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['href' => ''.e($secondaryCtaUrl).'','variant' => 'secondary','size' => 'lg','onDark' => true]); ?>
                            <?php echo e($secondaryCtaText); ?>

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
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                </div>
            </div>
        </template>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>

    
    <div class="absolute bottom-6 left-1/2 z-10 flex -translate-x-1/2 gap-2">
        <template x-for="(slide, index) in slides" :key="'dot-' + index">
            <div
                :class="currentSlide === index ? 'bg-white w-8' : 'bg-white/50 w-3'"
                class="h-3 rounded-full transition-all duration-300"
            ></div>
        </template>
    </div>
</div>
<?php /**PATH /home/patryk/web/gsc/resources/views/livewire/main-project-hero-slider.blade.php ENDPATH**/ ?>