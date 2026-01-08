<?php
$classes = Flux::classes()
    ->add('data-hidden:hidden block items-center px-2 py-1.5 w-full')
    ->add('rounded-md')
    ->add('text-start text-sm font-medium')
    ->add('text-zinc-500 data-active:bg-zinc-100 dark:text-zinc-300 dark:data-active:bg-zinc-600')
    ;
?>

<ui-option-empty <?php echo e($attributes->class($classes)); ?> data-flux-listbox-empty wire:ignore>
    <?php echo e($slot); ?>

</ui-option-empty><?php /**PATH /home/patryk/web/gsc/vendor/livewire/flux-pro/stubs/resources/views/flux/pillbox/option/empty.blade.php ENDPATH**/ ?>