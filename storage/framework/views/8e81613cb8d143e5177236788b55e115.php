<?php if (isset($component)) { $__componentOriginal5863877a5171c196453bfa0bd807e410 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal5863877a5171c196453bfa0bd807e410 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.layouts.app','data' => ['title' => isset($area) ? 'About Us | Home Remodeling in ' . $area->city . ' | GS Construction' : 'About Us | GS Construction | Family-Owned Home Remodeling','metaDescription' => isset($area) ? 'Meet Gregory and Patryk, the father-son team behind GS Construction. Serving ' . $area->city . ' with over 40 years of combined experience in kitchen, bathroom, and home remodeling.' : 'Meet Gregory and Patryk, the father-son team behind GS Construction. Over 40 years of combined experience in kitchen, bathroom, and home remodeling in the Chicagoland area.']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('layouts.app'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(isset($area) ? 'About Us | Home Remodeling in ' . $area->city . ' | GS Construction' : 'About Us | GS Construction | Family-Owned Home Remodeling'),'metaDescription' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(isset($area) ? 'Meet Gregory and Patryk, the father-son team behind GS Construction. Serving ' . $area->city . ' with over 40 years of combined experience in kitchen, bathroom, and home remodeling.' : 'Meet Gregory and Patryk, the father-son team behind GS Construction. Over 40 years of combined experience in kitchen, bathroom, and home remodeling in the Chicagoland area.')]); ?>
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
                            <p class="text-sm font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400"><?php echo e(isset($area) ? 'About Us in ' . $area->city : 'About Us'); ?></p>
                            <h1 class="font-heading mt-2 text-4xl font-bold tracking-tight text-zinc-900 sm:text-6xl dark:text-white">
                                <?php echo e(isset($area) ? 'Trusted Family Remodelers in ' . $area->city : 'A Family Business Built on Trust'); ?>

                            </h1>
                            <p class="mt-8 text-lg font-medium text-zinc-600 sm:max-w-md sm:text-xl/8 lg:max-w-none dark:text-zinc-300">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($area)): ?>
                                Serving <?php echo e($area->city); ?> and surrounding communities, GS Construction & Remodeling is more than a business—it's a family legacy. Run by Gregory and Patryk, a father-son duo with over 40 years of combined experience, we bring heart, skill, and dedication to every <?php echo e($area->city); ?> home.
                                <?php else: ?>
                                GS Construction & Remodeling is more than a business—it's a family legacy. Run by Gregory and Patryk, a father-son duo with over 40 years of combined experience, we bring heart, skill, and dedication to every project.
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </p>
                            <p class="mt-4 text-base text-zinc-500 sm:max-w-md lg:max-w-none dark:text-zinc-400">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($area)): ?>
                                From the initial consultation to the final walkthrough, we're personally involved in your <?php echo e($area->city); ?> project. We believe in building lasting relationships with our clients, not just beautiful spaces.
                                <?php else: ?>
                                From the initial consultation to the final walkthrough, we're personally involved in your project. We believe in building lasting relationships with our clients, not just beautiful spaces.
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </p>
                        </div>
                        
                        
                        <div class="mt-14 flex justify-end gap-4 sm:-mt-44 sm:justify-start sm:pl-20 lg:mt-0 lg:pl-0">
                            <div class="ml-auto w-40 flex-none space-y-4 pt-32 sm:ml-0 sm:pt-80 lg:order-last lg:pt-36 xl:order-0 xl:pt-80">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($galleryImages->count() > 0): ?>
                                <div class="relative">
                                    <img src="<?php echo e($galleryImages[0]->getThumbnailUrl('medium')); ?>" alt="<?php echo e($galleryImages[0]->alt_text ?? 'GS Construction project'); ?>" class="aspect-square w-full rounded-xl bg-zinc-900/5 object-cover shadow-lg dark:bg-zinc-700/5" loading="lazy" />
                                    <div class="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-zinc-900/10 ring-inset dark:ring-white/10"></div>
                                </div>
                                <?php endif; ?>
                                <?php if($galleryImages->count() > 5): ?>
                                <div class="relative">
                                    <img src="<?php echo e($galleryImages[5]->getThumbnailUrl('medium')); ?>" alt="<?php echo e($galleryImages[5]->alt_text ?? 'GS Construction project'); ?>" class="aspect-square w-full rounded-xl bg-zinc-900/5 object-cover shadow-lg dark:bg-zinc-700/5" loading="lazy" />
                                    <div class="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-zinc-900/10 ring-inset dark:ring-white/10"></div>
                                </div>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                            <div class="mr-auto w-40 flex-none space-y-4 sm:mr-0 sm:pt-52 lg:pt-36">
                                <?php if($galleryImages->count() > 1): ?>
                                <div class="relative">
                                    <img src="<?php echo e($galleryImages[1]->getThumbnailUrl('medium')); ?>" alt="<?php echo e($galleryImages[1]->alt_text ?? 'GS Construction project'); ?>" class="aspect-square w-full rounded-xl bg-zinc-900/5 object-cover shadow-lg dark:bg-zinc-700/5" loading="lazy" />
                                    <div class="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-zinc-900/10 ring-inset dark:ring-white/10"></div>
                                </div>
                                <?php endif; ?>
                                <?php if($galleryImages->count() > 2): ?>
                                <div class="relative">
                                    <img src="<?php echo e($galleryImages[2]->getThumbnailUrl('medium')); ?>" alt="<?php echo e($galleryImages[2]->alt_text ?? 'GS Construction project'); ?>" class="aspect-square w-full rounded-xl bg-zinc-900/5 object-cover shadow-lg dark:bg-zinc-700/5" loading="lazy" />
                                    <div class="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-zinc-900/10 ring-inset dark:ring-white/10"></div>
                                </div>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                            <div class="w-40 flex-none space-y-4 pt-32 sm:pt-0">
                                <?php if($galleryImages->count() > 3): ?>
                                <div class="relative">
                                    <img src="<?php echo e($galleryImages[3]->getThumbnailUrl('medium')); ?>" alt="<?php echo e($galleryImages[3]->alt_text ?? 'GS Construction project'); ?>" class="aspect-square w-full rounded-xl bg-zinc-900/5 object-cover shadow-lg dark:bg-zinc-700/5" loading="lazy" />
                                    <div class="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-zinc-900/10 ring-inset dark:ring-white/10"></div>
                                </div>
                                <?php endif; ?>
                                <?php if($galleryImages->count() > 4): ?>
                                <div class="relative">
                                    <img src="<?php echo e($galleryImages[4]->getThumbnailUrl('medium')); ?>" alt="<?php echo e($galleryImages[4]->alt_text ?? 'GS Construction project'); ?>" class="aspect-square w-full rounded-xl bg-zinc-900/5 object-cover shadow-lg dark:bg-zinc-700/5" loading="lazy" />
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

        
        
        <div class="mt-10 sm:mt-12">
            <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('main-project-hero-slider', ['area' => $area ?? null]);

$key = null;

$key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-1415144451-1', null);

$__html = app('livewire')->mount($__name, $__params, $key);

echo $__html;

unset($__html);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>
        </div>
        <!-- CTA section -->
        <div class="relative isolate px-6 py-10 sm:py-12 lg:px-8">
            <div aria-hidden="true" class="absolute inset-x-0 top-1/2 -z-10 -translate-y-1/2 transform-gpu overflow-hidden opacity-20 blur-3xl">
                <div style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)" class="ml-[max(50%,38rem)] aspect-[1313/771] w-[82.0625rem] bg-linear-to-tr from-sky-300 to-sky-600"></div>
            </div>
            <div class="mx-auto max-w-2xl text-center">
                <h2 class="font-heading text-4xl font-bold tracking-tight text-zinc-900 sm:text-5xl dark:text-white">
                    <?php echo e(isset($area) ? 'Ready to Transform Your ' . $area->city . ' Home?' : 'Ready to Transform Your Home?'); ?>

                </h2>
                <p class="mx-auto mt-6 max-w-xl text-lg/8 text-zinc-600 dark:text-zinc-300">
                    Let's discuss your project. Schedule a free consultation and see why <?php echo e(isset($area) ? $area->city : 'Chicagoland'); ?> homeowners trust GS Construction.
                </p>
                <div class="mt-10 flex items-center justify-center gap-x-6">
                    <?php if (isset($component)) { $__componentOriginalc04b147acd0e65cc1a77f86fb0e81580 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::button.index','data' => ['href' => '/contact','variant' => 'primary','class' => 'font-semibold uppercase tracking-wide']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['href' => '/contact','variant' => 'primary','class' => 'font-semibold uppercase tracking-wide']); ?>
                        Schedule Free Consultation
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
                    <a href="/projects" class="text-sm/6 font-semibold text-zinc-900 dark:text-white">
                        View Our Work <span aria-hidden="true">→</span>
                    </a>
                </div>
            </div>
        </div>
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