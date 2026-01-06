<section class="relative isolate bg-white dark:bg-gray-900">
    <div class="mx-auto grid max-w-7xl grid-cols-1 lg:grid-cols-2">
        
        <div class="relative px-6 py-8 sm:py-10 lg:px-8 lg:py-12">
            <div class="mx-auto max-w-xl lg:sticky lg:top-8 lg:mx-0 lg:max-w-lg">
                

                
                <div class="mb-4">
                    <img 
                        src="<?php echo e(asset('images/greg-patryk.jpg')); ?>" 
                        alt="Greg and Patryk - GS Construction" 
                        class="aspect-[4/3] w-full max-w-md rounded-2xl object-cover shadow-xl ring-1 ring-gray-900/10 dark:ring-white/10"
                    />
                </div>

                
                <h2 class="font-heading text-4xl font-semibold tracking-tight text-pretty text-gray-900 sm:text-5xl dark:text-white">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($detectedCity): ?>
                        <?php echo e($detectedCity); ?>'s Trusted Home Remodeling Experts
                    <?php else: ?>
                        Let's Build Beautiful Spaces Together
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </h2>
                <p class="mt-4 text-lg/8 text-gray-600 dark:text-gray-400">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($detectedCity): ?>
                        Proudly serving <?php echo e($detectedCity); ?> and surrounding communities. Whether you're dreaming of a new kitchen, a luxurious bathroom, or a complete home transformation, we're here to make it happen.
                    <?php else: ?>
                        Whether you're dreaming of a new kitchen, a luxurious bathroom, or a complete home transformation, we're here to make it happen. Contact us today to schedule your free consultation.
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </p>

                
                <dl class="mt-6 space-y-4 text-base/7 text-gray-600 dark:text-gray-300">
                    
                    <div class="flex gap-x-4">
                        <dt class="flex-none">
                            <span class="sr-only">Telephone</span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" class="h-7 w-6 text-gray-400">
                                <path d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </dt>
                        <dd><a href="tel:8474304439" class="hover:text-gray-900 dark:hover:text-white">(847) 430-4439</a></dd>
                    </div>
                    
                    <div class="flex gap-x-4">
                        <dt class="flex-none">
                            <span class="sr-only">Email</span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" class="h-7 w-6 text-gray-400">
                                <path d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </dt>
                        <dd><a href="mailto:patryk@gs.construction" class="hover:text-gray-900 dark:hover:text-white">patryk@gs.construction</a></dd>
                    </div>
                    
                    <div class="flex gap-x-4">
                        <dt class="flex-none">
                            <span class="sr-only">Service Area</span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" class="h-7 w-6 text-gray-400">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                            </svg>
                        </dt>
                        <dd>
                            <span class="font-medium text-gray-900 dark:text-white">Service Area</span><br>
                            <span>Chicagoland Northwest Suburbs</span>
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        
        <form wire:submit="submit" class="px-6 py-8 sm:py-10 lg:px-8 lg:py-12">
            <div class="mx-auto max-w-xl lg:mr-0 lg:max-w-lg">
                <div class="grid grid-cols-1 gap-x-8 gap-y-3">
                    
                    <div>
                        <label for="name" class="block text-sm/6 font-semibold text-gray-900 dark:text-white">Name</label>
                        <div class="mt-1.5">
                            <?php if (isset($component)) { $__componentOriginal26c546557cdc09040c8dd00b2090afd0 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal26c546557cdc09040c8dd00b2090afd0 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::input.index','data' => ['wire:model' => 'name','id' => 'name','type' => 'text','autocomplete' => 'name','placeholder' => 'Your full name','class' => '!bg-white dark:!bg-white/5 focus:!ring-sky-500 focus:!border-sky-500']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:model' => 'name','id' => 'name','type' => 'text','autocomplete' => 'name','placeholder' => 'Your full name','class' => '!bg-white dark:!bg-white/5 focus:!ring-sky-500 focus:!border-sky-500']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal26c546557cdc09040c8dd00b2090afd0)): ?>
<?php $attributes = $__attributesOriginal26c546557cdc09040c8dd00b2090afd0; ?>
<?php unset($__attributesOriginal26c546557cdc09040c8dd00b2090afd0); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal26c546557cdc09040c8dd00b2090afd0)): ?>
<?php $component = $__componentOriginal26c546557cdc09040c8dd00b2090afd0; ?>
<?php unset($__componentOriginal26c546557cdc09040c8dd00b2090afd0); ?>
<?php endif; ?>
                        </div>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['name'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-1 text-sm text-red-600 dark:text-red-400"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>

                    
                    <div>
                        <label for="email" class="block text-sm/6 font-semibold text-gray-900 dark:text-white">Email</label>
                        <div class="mt-1.5">
                            <?php if (isset($component)) { $__componentOriginal26c546557cdc09040c8dd00b2090afd0 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal26c546557cdc09040c8dd00b2090afd0 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::input.index','data' => ['wire:model' => 'email','id' => 'email','type' => 'email','autocomplete' => 'email','placeholder' => 'you@example.com','class' => '!bg-white dark:!bg-white/5 focus:!ring-sky-500 focus:!border-sky-500']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:model' => 'email','id' => 'email','type' => 'email','autocomplete' => 'email','placeholder' => 'you@example.com','class' => '!bg-white dark:!bg-white/5 focus:!ring-sky-500 focus:!border-sky-500']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal26c546557cdc09040c8dd00b2090afd0)): ?>
<?php $attributes = $__attributesOriginal26c546557cdc09040c8dd00b2090afd0; ?>
<?php unset($__attributesOriginal26c546557cdc09040c8dd00b2090afd0); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal26c546557cdc09040c8dd00b2090afd0)): ?>
<?php $component = $__componentOriginal26c546557cdc09040c8dd00b2090afd0; ?>
<?php unset($__componentOriginal26c546557cdc09040c8dd00b2090afd0); ?>
<?php endif; ?>
                        </div>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['email'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-1 text-sm text-red-600 dark:text-red-400"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>

                    
                    <div>
                        <label for="phone" class="block text-sm/6 font-semibold text-gray-900 dark:text-white">Cell Phone</label>
                        <div class="mt-1.5">
                            <?php if (isset($component)) { $__componentOriginal26c546557cdc09040c8dd00b2090afd0 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal26c546557cdc09040c8dd00b2090afd0 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::input.index','data' => ['wire:model' => 'phone','id' => 'phone','type' => 'tel','autocomplete' => 'tel','placeholder' => '(555) 123-4567','class' => '!bg-white dark:!bg-white/5 focus:!ring-sky-500 focus:!border-sky-500']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:model' => 'phone','id' => 'phone','type' => 'tel','autocomplete' => 'tel','placeholder' => '(555) 123-4567','class' => '!bg-white dark:!bg-white/5 focus:!ring-sky-500 focus:!border-sky-500']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal26c546557cdc09040c8dd00b2090afd0)): ?>
<?php $attributes = $__attributesOriginal26c546557cdc09040c8dd00b2090afd0; ?>
<?php unset($__attributesOriginal26c546557cdc09040c8dd00b2090afd0); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal26c546557cdc09040c8dd00b2090afd0)): ?>
<?php $component = $__componentOriginal26c546557cdc09040c8dd00b2090afd0; ?>
<?php unset($__componentOriginal26c546557cdc09040c8dd00b2090afd0); ?>
<?php endif; ?>
                        </div>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['phone'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-1 text-sm text-red-600 dark:text-red-400"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>

                    
                    <div
                        x-data="{
                            open: false,
                            query: <?php if ((object) ('address') instanceof \Livewire\WireDirective) : ?>window.Livewire.find('<?php echo e($__livewire->getId()); ?>').entangle('<?php echo e('address'->value()); ?>')<?php echo e('address'->hasModifier('live') ? '.live' : ''); ?><?php else : ?>window.Livewire.find('<?php echo e($__livewire->getId()); ?>').entangle('<?php echo e('address'); ?>')<?php endif; ?>,
                            predictions: [],
                            selectedIndex: -1,
                            placesReady: false,
                            areasServed: <?php echo \Illuminate\Support\Js::from($areasServed)->toHtml() ?>,
                            async init() {
                                await this.loadPlacesLibrary();
                            },
                            async loadPlacesLibrary() {
                                try {
                                    await google.maps.importLibrary('places');
                                    this.placesReady = true;
                                } catch (e) {
                                    console.error('Failed to load Places library:', e);
                                }
                            },
                            async search() {
                                if (!this.query || this.query.length < 3 || !this.placesReady) {
                                    this.predictions = [];
                                    this.open = false;
                                    return;
                                }
                                try {
                                    const request = {
                                        input: this.query,
                                        includedPrimaryTypes: ['street_address', 'subpremise', 'premise'],
                                        includedRegionCodes: ['us'],
                                    };
                                    const { suggestions } = await google.maps.places.AutocompleteSuggestion.fetchAutocompleteSuggestions(request);
                                    
                                    // Filter to only include addresses in served areas
                                    this.predictions = suggestions
                                        .filter(s => s.placePrediction)
                                        .map(s => ({
                                            placeId: s.placePrediction.placeId,
                                            description: s.placePrediction.text.text,
                                            mainText: s.placePrediction.mainText?.text || '',
                                            secondaryText: s.placePrediction.secondaryText?.text || ''
                                        }))
                                        .filter(p => {
                                            return this.areasServed.some(city => 
                                                p.description.toLowerCase().includes(city.toLowerCase() + ',')
                                            );
                                        })
                                        .slice(0, 5);
                                    
                                    this.open = this.predictions.length > 0;
                                    this.selectedIndex = -1;
                                } catch (e) {
                                    console.error('Autocomplete error:', e);
                                    this.predictions = [];
                                    this.open = false;
                                }
                            },
                            selectPrediction(prediction) {
                                this.query = prediction.description;
                                this.predictions = [];
                                this.open = false;
                                this.selectedIndex = -1;
                            },
                            handleKeydown(e) {
                                if (!this.open) return;
                                if (e.key === 'ArrowDown') {
                                    e.preventDefault();
                                    this.selectedIndex = Math.min(this.selectedIndex + 1, this.predictions.length - 1);
                                } else if (e.key === 'ArrowUp') {
                                    e.preventDefault();
                                    this.selectedIndex = Math.max(this.selectedIndex - 1, 0);
                                } else if (e.key === 'Enter' && this.selectedIndex >= 0) {
                                    e.preventDefault();
                                    this.selectPrediction(this.predictions[this.selectedIndex]);
                                } else if (e.key === 'Escape') {
                                    this.open = false;
                                }
                            }
                        }"
                        @click.away="open = false"
                        class="relative"
                    >
                        <label for="address-input" class="block text-sm/6 font-semibold text-gray-900 dark:text-white">Project Address</label>
                        <div class="mt-1.5">
                            <?php if (isset($component)) { $__componentOriginal26c546557cdc09040c8dd00b2090afd0 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal26c546557cdc09040c8dd00b2090afd0 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::input.index','data' => ['xModel' => 'query','@input.debounce.300ms' => 'search()','@keydown' => 'handleKeydown($event)','@focus' => 'if (predictions.length) open = true','id' => 'address-input','type' => 'text','autocomplete' => 'off','placeholder' => 'Start typing your address...','class' => '!bg-white dark:!bg-white/5 focus:!ring-sky-500 focus:!border-sky-500']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['x-model' => 'query','@input.debounce.300ms' => 'search()','@keydown' => 'handleKeydown($event)','@focus' => 'if (predictions.length) open = true','id' => 'address-input','type' => 'text','autocomplete' => 'off','placeholder' => 'Start typing your address...','class' => '!bg-white dark:!bg-white/5 focus:!ring-sky-500 focus:!border-sky-500']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal26c546557cdc09040c8dd00b2090afd0)): ?>
<?php $attributes = $__attributesOriginal26c546557cdc09040c8dd00b2090afd0; ?>
<?php unset($__attributesOriginal26c546557cdc09040c8dd00b2090afd0); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal26c546557cdc09040c8dd00b2090afd0)): ?>
<?php $component = $__componentOriginal26c546557cdc09040c8dd00b2090afd0; ?>
<?php unset($__componentOriginal26c546557cdc09040c8dd00b2090afd0); ?>
<?php endif; ?>
                        </div>
                        
                        
                        <div
                            x-show="open && predictions.length > 0"
                            x-transition:enter="transition ease-out duration-100"
                            x-transition:enter-start="opacity-0 scale-95"
                            x-transition:enter-end="opacity-100 scale-100"
                            x-transition:leave="transition ease-in duration-75"
                            x-transition:leave-start="opacity-100 scale-100"
                            x-transition:leave-end="opacity-0 scale-95"
                            class="absolute z-50 mt-1 w-full rounded-lg border border-zinc-200 bg-white py-1 shadow-lg dark:border-white/10 dark:bg-zinc-800"
                            x-cloak
                        >
                            <template x-for="(prediction, index) in predictions" :key="prediction.placeId">
                                <button
                                    type="button"
                                    @click="selectPrediction(prediction)"
                                    @mouseenter="selectedIndex = index"
                                    :class="{
                                        'bg-zinc-100 dark:bg-zinc-700': selectedIndex === index,
                                        'text-zinc-900 dark:text-white': true
                                    }"
                                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-700"
                                >
                                    <svg class="size-4 shrink-0 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                                    </svg>
                                    <span x-text="prediction.description" class="truncate"></span>
                                </button>
                            </template>
                        </div>
                        
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['address'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-1 text-sm text-red-600 dark:text-red-400"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>

                    
                    <div>
                        <label for="message" class="block text-sm/6 font-semibold text-gray-900 dark:text-white">Message</label>
                        <div class="mt-1.5">
                            <?php if (isset($component)) { $__componentOriginal0ee30026125d1a66523211147b00e4dc = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal0ee30026125d1a66523211147b00e4dc = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::textarea','data' => ['wire:model' => 'message','id' => 'message','rows' => '4','placeholder' => 'Tell us about your project...','class' => '!bg-white dark:!bg-white/5 focus:!ring-sky-500 focus:!border-sky-500']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::textarea'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:model' => 'message','id' => 'message','rows' => '4','placeholder' => 'Tell us about your project...','class' => '!bg-white dark:!bg-white/5 focus:!ring-sky-500 focus:!border-sky-500']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal0ee30026125d1a66523211147b00e4dc)): ?>
<?php $attributes = $__attributesOriginal0ee30026125d1a66523211147b00e4dc; ?>
<?php unset($__attributesOriginal0ee30026125d1a66523211147b00e4dc); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal0ee30026125d1a66523211147b00e4dc)): ?>
<?php $component = $__componentOriginal0ee30026125d1a66523211147b00e4dc; ?>
<?php unset($__componentOriginal0ee30026125d1a66523211147b00e4dc); ?>
<?php endif; ?>
                        </div>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['message'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-1 text-sm text-red-600 dark:text-red-400"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>

                    
                    <div
                        x-data="{
                            activeDate: <?php if ((object) ('selectedDateForTimes') instanceof \Livewire\WireDirective) : ?>window.Livewire.find('<?php echo e($__livewire->getId()); ?>').entangle('<?php echo e('selectedDateForTimes'->value()); ?>')<?php echo e('selectedDateForTimes'->hasModifier('live') ? '.live' : ''); ?><?php else : ?>window.Livewire.find('<?php echo e($__livewire->getId()); ?>').entangle('<?php echo e('selectedDateForTimes'); ?>')<?php endif; ?>,
                            timeSelections: <?php if ((object) ('timeSelections') instanceof \Livewire\WireDirective) : ?>window.Livewire.find('<?php echo e($__livewire->getId()); ?>').entangle('<?php echo e('timeSelections'->value()); ?>')<?php echo e('timeSelections'->hasModifier('live') ? '.live' : ''); ?><?php else : ?>window.Livewire.find('<?php echo e($__livewire->getId()); ?>').entangle('<?php echo e('timeSelections'); ?>')<?php endif; ?>,
                            times: <?php echo \Illuminate\Support\Js::from($times)->toHtml() ?>,
                            parseDateFromAriaLabel(label) {
                                const raw = (label || '').trim();
                                const parts = raw.split(',').map(p => p.trim());

                                // Expected label format: 'Wednesday, January 7, 2026'
                                if (parts.length < 3) return null;

                                const parsed = new Date(`${parts[1]}, ${parts[2]}`);
                                if (Number.isNaN(parsed.getTime())) return null;

                                const y = parsed.getFullYear();
                                const m = String(parsed.getMonth() + 1).padStart(2, '0');
                                const d = String(parsed.getDate()).padStart(2, '0');
                                return `${y}-${m}-${d}`;
                            },
                            applyActiveHighlight() {
                                if (!this.$refs.calendarWrap) return;

                                // Clear any previous active markers
                                this.$refs.calendarWrap
                                    .querySelectorAll('[data-gsc-active-date]')
                                    .forEach((button) => button.removeAttribute('data-gsc-active-date'));

                                if (!this.activeDate) return;

                                const buttons = this.$refs.calendarWrap.querySelectorAll('button[aria-label]');
                                for (const button of buttons) {
                                    if (button.disabled) continue;

                                    const dateStr = this.parseDateFromAriaLabel(button.getAttribute('aria-label'));
                                    if (dateStr && dateStr === this.activeDate) {
                                        button.dataset.gscActiveDate = 'true';
                                        break;
                                    }
                                }
                            },
                            onCalendarClick(event) {
                                const button = event.target.closest('button[aria-label]');
                                if (!button || button.disabled) return;

                                const dateStr = this.parseDateFromAriaLabel(button.getAttribute('aria-label'));
                                if (!dateStr) return;

                                // Prevent Flux from toggling the selection highlight.
                                // We only want dates with times to be selected in the calendar.
                                event.preventDefault();
                                event.stopImmediatePropagation();

                                this.activeDate = dateStr;
                                this.applyActiveHighlight();
                            },
                            formatDisplayDate(dateStr) {
                                if (!dateStr) return '';
                                const [year, month, day] = dateStr.split('-').map(Number);
                                const date = new Date(year, month - 1, day);
                                return date.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });
                            },
                            formatShortDate(dateStr) {
                                if (!dateStr) return '';
                                const [year, month, day] = dateStr.split('-').map(Number);
                                const date = new Date(year, month - 1, day);
                                return date.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
                            },
                            isTimeSelected(time) {
                                const date = this.activeDate;
                                if (!date) return false;
                                const selections = this.timeSelections || {};
                                return selections[date]?.includes(time) || false;
                            },
                            get totalSelections() {
                                const selections = this.timeSelections || {};
                                return Object.values(selections).flat().length;
                            },
                            get sortedSelectionDates() {
                                const selections = this.timeSelections || {};
                                return Object.keys(selections).filter(d => selections[d]?.length > 0).sort();
                            },
                            init() {
                                this.$nextTick(() => {
                                    if (this.$refs.calendarWrap) {
                                        const observer = new MutationObserver(() => this.applyActiveHighlight());
                                        observer.observe(this.$refs.calendarWrap, { subtree: true, childList: true });
                                    }
                                    this.applyActiveHighlight();
                                });
                                this.$watch('activeDate', () => this.applyActiveHighlight());
                                this.$watch('timeSelections', () => this.applyActiveHighlight());
                            }
                        }"
                        class="space-y-3"
                    >
                        <div>
                            <label class="block text-sm/6 font-semibold text-gray-900 dark:text-white">
                                Preferred Consultation Times
                            </label>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                Please select at least 2 different days and times you're available to meet.
                            </p>
                        </div>
                        
                        <div class="rounded-lg border border-zinc-200 bg-white dark:border-white/10 dark:bg-zinc-800/50">
                            <div class="flex flex-col divide-y divide-zinc-200 dark:divide-white/10 sm:flex-row sm:divide-x sm:divide-y-0">
                                
                                <div
                                    class="flex items-center justify-center p-6 sm:w-3/5"
                                    x-ref="calendarWrap"
                                    x-on:click.capture="onCalendarClick($event)"
                                >
                                    <?php if (isset($component)) { $__componentOriginalfdf0a8a3c2a4461ed08f33aede409d72 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalfdf0a8a3c2a4461ed08f33aede409d72 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::calendar.index','data' => ['multiple' => true,'wire:model.live' => 'selectedDates','min' => ''.e($minSelectableDate).'','unavailable' => $unavailableSundays,'fixedWeeks' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::calendar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['multiple' => true,'wire:model.live' => 'selectedDates','min' => ''.e($minSelectableDate).'','unavailable' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($unavailableSundays),'fixed-weeks' => true]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalfdf0a8a3c2a4461ed08f33aede409d72)): ?>
<?php $attributes = $__attributesOriginalfdf0a8a3c2a4461ed08f33aede409d72; ?>
<?php unset($__attributesOriginalfdf0a8a3c2a4461ed08f33aede409d72); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalfdf0a8a3c2a4461ed08f33aede409d72)): ?>
<?php $component = $__componentOriginalfdf0a8a3c2a4461ed08f33aede409d72; ?>
<?php unset($__componentOriginalfdf0a8a3c2a4461ed08f33aede409d72); ?>
<?php endif; ?>
                                </div>
                                
                                
                                <div class="p-4 sm:w-2/5">
                                    <template x-if="activeDate">
                                        <div>
                                            <div class="mb-3 flex items-center gap-2">
                                                <svg class="size-4 text-sky-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                                                </svg>
                                                <span class="text-sm font-medium text-zinc-900 dark:text-white" x-text="formatDisplayDate(activeDate)"></span>
                                            </div>
                                            <div class="grid grid-cols-2 gap-2">
                                                <template x-for="time in times" :key="time">
                                                    <button
                                                        type="button"
                                                        @click="$wire.toggleTime(activeDate, time)"
                                                        :class="{
                                                            'border-sky-500 bg-sky-50 text-sky-700 dark:bg-sky-900/30 dark:text-sky-400 dark:border-sky-500': isTimeSelected(time),
                                                            'border-zinc-200 dark:border-zinc-600 text-zinc-700 dark:text-zinc-300 hover:border-sky-300 hover:bg-sky-50 dark:hover:border-sky-600 dark:hover:bg-sky-900/20': !isTimeSelected(time)
                                                        }"
                                                        class="rounded-lg border px-3 py-2 text-sm font-medium transition-colors"
                                                        x-text="time"
                                                    ></button>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                    <template x-if="!activeDate">
                                        <div class="flex h-full items-center justify-center py-8">
                                            <p class="text-sm text-zinc-500 dark:text-zinc-400">Select a date to view times</p>
                                        </div>
                                    </template>
                                </div>
                            </div>
                            
                            
                            <template x-if="totalSelections > 0">
                                <div class="border-t border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-zinc-800">
                                    <div class="mb-2 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Selected Times</div>
                                    <div class="space-y-2">
                                        <template x-for="date in sortedSelectionDates" :key="date">
                                            <div class="grid grid-cols-[auto_1fr] items-start gap-x-3 gap-y-1">
                                                <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300 whitespace-nowrap" x-text="formatShortDate(date)"></span>
                                                <div class="flex flex-wrap gap-1">
                                                    <template x-for="time in (timeSelections[date] || [])" :key="date + time">
                                                        <span class="inline-flex items-center gap-1 rounded-full bg-sky-100 py-1 pl-2.5 pr-1 text-xs font-medium text-sky-700 dark:bg-sky-900/30 dark:text-sky-400">
                                                            <span x-text="time"></span>
                                                            <button type="button" @click="$wire.removeTimeSelection(date, time)" class="rounded-full p-0.5 hover:bg-sky-200 dark:hover:bg-sky-800">
                                                                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                                                </svg>
                                                            </button>
                                                        </span>
                                                    </template>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                
                <div class="mt-4 flex justify-end">
                    <button 
                        type="submit" 
                        class="inline-flex items-center rounded-md bg-sky-500 px-5 py-2.5 text-sm font-semibold text-white shadow-lg transition hover:bg-sky-600"
                    >
                        <span wire:loading.remove wire:target="submit">Send message</span>
                        <span wire:loading wire:target="submit">Sending...</span>
                    </button>
                </div>

                
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('success')): ?>
                    <div class="mt-6 rounded-md bg-green-50 p-4 dark:bg-green-900/20">
                        <div class="flex">
                            <div class="shrink-0">
                                <svg class="size-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-green-800 dark:text-green-200"><?php echo e(session('success')); ?></p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </form>
    </div>
</section>
<?php /**PATH /home/patryk/web/gsc/resources/views/livewire/contact-section.blade.php ENDPATH**/ ?>