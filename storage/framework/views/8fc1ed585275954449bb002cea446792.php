<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'projects' => collect(),
    'testimonials' => collect(),
    'currentService' => null,
    'area' => null,
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
    'projects' => collect(),
    'testimonials' => collect(),
    'currentService' => null,
    'area' => null,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
use App\Models\Project;

$services = [
    'kitchen-remodeling' => ['name' => 'Kitchen Remodeling', 'route' => 'services.kitchen'],
    'bathroom-remodeling' => ['name' => 'Bathroom Remodeling', 'route' => 'services.bathroom'],
    'home-remodeling' => ['name' => 'Home Remodeling', 'route' => 'services.home'],
];

// Filter out current service
$otherServices = collect($services)->filter(fn($s, $key) => $key !== $currentService)->take(3);

// Get related projects if not provided
if ($projects->isEmpty()) {
    $projects = Project::where('is_published', true)
        ->orderBy('is_featured', 'desc')
        ->orderBy('completed_at', 'desc')
        ->take(4)
        ->get();
}
?>

<aside class="relative py-12 dark:bg-zinc-800/50">
    <div class="mx-auto max-w-7xl px-6 lg:px-8">
        <div class="relative z-10 grid gap-12 lg:grid-cols-2">
            
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($otherServices->isNotEmpty()): ?>
            <div>
                <h3 class="font-heading text-xl font-semibold text-zinc-900 dark:text-white">
                    Other Services
                </h3>
                <ul class="mt-4 space-y-3">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $otherServices; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $slug => $service): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <li>
                        <a 
                            href="<?php echo e(route($service['route'])); ?>"
                            wire:navigate
                            class="group relative z-10 flex items-center gap-3 text-zinc-600 transition hover:text-sky-600 dark:text-zinc-400 dark:hover:text-sky-400"
                        >
                            <svg class="h-5 w-5 text-sky-500 transition group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                            <?php echo e($service['name']); ?>

                        </a>
                    </li>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </ul>
            </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($projects->isNotEmpty()): ?>
            <div>
                <h3 class="font-heading text-xl font-semibold text-zinc-900 dark:text-white">
                    Recent Projects
                </h3>
                <ul class="mt-4 space-y-3">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $projects->take(2); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $project): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <li>
                        <a 
                            href="<?php echo e(route('projects.index', ['type' => $project->project_type])); ?>"
                            wire:navigate
                            class="group relative z-10 flex items-center gap-3 text-zinc-600 transition hover:text-sky-600 dark:text-zinc-400 dark:hover:text-sky-400"
                        >
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($project->images->first()): ?>
                            <img 
                                src="<?php echo e($project->images->first()->getThumbnailUrl('thumb')); ?>" 
                                alt="<?php echo e($project->title); ?>"
                                class="h-10 w-10 rounded object-cover"
                                loading="lazy"
                            >
                            <?php else: ?>
                            <div class="flex h-10 w-10 items-center justify-center rounded bg-zinc-200 dark:bg-zinc-700">
                                <svg class="h-5 w-5 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            <span class="group-hover:underline"><?php echo e($project->title); ?></span>
                        </a>
                    </li>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </ul>
            </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>

        
        <div class="mt-8 flex flex-wrap gap-4 border-t border-zinc-200 pt-8 dark:border-zinc-700">
            <a 
                href="<?php echo e(route('testimonials.index')); ?>"
                wire:navigate
                class="inline-flex items-center gap-2 text-sm font-medium text-zinc-600 hover:text-sky-600 dark:text-zinc-400 dark:hover:text-sky-400"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                </svg>
                Read Reviews
            </a>
            <a 
                href="<?php echo e(route('projects.index')); ?>"
                wire:navigate
                class="inline-flex items-center gap-2 text-sm font-medium text-zinc-600 hover:text-sky-600 dark:text-zinc-400 dark:hover:text-sky-400"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                View All Projects
            </a>
            <a 
                href="<?php echo e(route('contact')); ?>"
                wire:navigate
                class="inline-flex items-center gap-2 text-sm font-medium text-zinc-600 hover:text-sky-600 dark:text-zinc-400 dark:hover:text-sky-400"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                </svg>
                Get a Free Quote
            </a>
        </div>
    </div>
</aside>
<?php /**PATH /home/patryk/web/gsc/resources/views/components/internal-links.blade.php ENDPATH**/ ?>