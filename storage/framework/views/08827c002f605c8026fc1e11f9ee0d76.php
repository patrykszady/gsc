<section
    x-data="{
        frames: <?php echo \Illuminate\Support\Js::from($frames ?? [])->toHtml() ?>,
        position: 1,
        timer: null,
        started: false,
        inView: false,
        hovering: false,
        dragging: false,
        intervalMs: 650,
        preloaded: false,
        src() {
            if (!this.frames.length) return null;
            return this.frames[this.position - 1] ?? this.frames[0] ?? null;
        },
        tick() {
            if (!this.frames.length) return;
            this.position = (this.position % this.frames.length) + 1;
        },
        start() {
            if (!this.inView || this.hovering || this.dragging || this.timer || this.frames.length < 2) return;
            this.timer = setInterval(() => this.tick(), this.intervalMs);
        },
        stop() {
            if (!this.timer) return;
            clearInterval(this.timer);
            this.timer = null;
        },
        preloadInitial() {
            // Preload first 3 frames immediately on init
            this.frames.slice(0, 3).forEach(src => {
                const img = new Image();
                img.src = src;
            });
        },
        preloadAll() {
            if (this.preloaded) return;
            this.preloaded = true;
            // Preload remaining frames in background
            this.frames.slice(3).forEach(src => {
                const img = new Image();
                img.src = src;
            });
        },
        play() {
            this.inView = true;
            this.started = true;
            this.stop();
            this.start();
            this.preloadAll();
        },
        pause() {
            this.inView = false;
            this.stop();
        },
        beginHover() {
            this.hovering = true;
            this.stop();
        },
        endHover() {
            this.hovering = false;
            this.start();
        },
        beginDrag() {
            this.dragging = true;
            this.stop();
        },
        endDrag() {
            this.dragging = false;
            this.start();
        },
    }"
    x-init="preloadInitial()"
    @pointerup.window="endDrag()"
    @pointercancel.window="endDrag()"
    x-intersect:enter.full="play()"
    x-intersect:leave.full="pause()"
    class="relative w-full overflow-hidden bg-white dark:bg-slate-950"
>
    <div class="relative h-[375px] sm:h-[450px] lg:h-[525px]">
        <img
            x-show="frames.length"
            :src="src()"
            alt="Project timelapse"
            class="absolute inset-0 h-full w-full object-cover"
        />

        
        <div class="absolute inset-0 bg-black/20"></div>

        
        <div class="absolute inset-x-0 bottom-6 z-10">
            <div class="mx-auto w-full max-w-md px-6">
                <div
                    class="rounded-xl p-4 text-white backdrop-blur-sm shadow-lg ring-2 ring-white/50 **:text-white **:fill-white **:stroke-white"
                    @mouseenter="beginHover()"
                    @mouseleave="endHover()"
                    @focusin="beginHover()"
                    @focusout="endHover()"
                    @pointerdown.capture="beginDrag()"
                >
                    <?php if (isset($component)) { $__componentOriginal85b48c5b92acde663860d1e821a7dd8e = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal85b48c5b92acde663860d1e821a7dd8e = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::slider.index','data' => ['min' => '1','max' => ''.e($frameCount).'','xModel.number' => 'position']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::slider'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['min' => '1','max' => ''.e($frameCount).'','x-model.number' => 'position']); ?>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php for($i = 1; $i <= $frameCount; $i++): ?>
                            <?php if (isset($component)) { $__componentOriginal1ce78ddbf92e7e50c2214f01ffade7b8 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal1ce78ddbf92e7e50c2214f01ffade7b8 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::slider.tick','data' => ['value' => ''.e($i).'','class' => '!text-white drop-shadow-sm font-medium']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::slider.tick'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['value' => ''.e($i).'','class' => '!text-white drop-shadow-sm font-medium']); ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($i === 1): ?>
                                    Before
                                <?php elseif($i === $middleTick): ?>
                                    Construction
                                <?php elseif($i === $frameCount): ?>
                                    After
                                <?php else: ?>
                                    <span class="sr-only">Frame <?php echo e($i); ?></span>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                             <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal1ce78ddbf92e7e50c2214f01ffade7b8)): ?>
<?php $attributes = $__attributesOriginal1ce78ddbf92e7e50c2214f01ffade7b8; ?>
<?php unset($__attributesOriginal1ce78ddbf92e7e50c2214f01ffade7b8); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal1ce78ddbf92e7e50c2214f01ffade7b8)): ?>
<?php $component = $__componentOriginal1ce78ddbf92e7e50c2214f01ffade7b8; ?>
<?php unset($__componentOriginal1ce78ddbf92e7e50c2214f01ffade7b8); ?>
<?php endif; ?>
                        <?php endfor; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal85b48c5b92acde663860d1e821a7dd8e)): ?>
<?php $attributes = $__attributesOriginal85b48c5b92acde663860d1e821a7dd8e; ?>
<?php unset($__attributesOriginal85b48c5b92acde663860d1e821a7dd8e); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal85b48c5b92acde663860d1e821a7dd8e)): ?>
<?php $component = $__componentOriginal85b48c5b92acde663860d1e821a7dd8e; ?>
<?php unset($__componentOriginal85b48c5b92acde663860d1e821a7dd8e); ?>
<?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
<?php /**PATH /home/patryk/web/gsc/resources/views/livewire/timelapse-section.blade.php ENDPATH**/ ?>