<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale())); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">

    <title><?php echo e($title ?? 'Login'); ?> - <?php echo e(config('app.name', 'GS Construction')); ?></title>

    
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

    
    <?php echo app('Illuminate\Foundation\Vite')(['resources/css/app.css', 'resources/js/app.js']); ?>
    <?php echo app('flux')->fluxAppearance(); ?>

</head>
<body class="flex min-h-screen items-center justify-center bg-zinc-100 font-sans antialiased dark:bg-zinc-900">
    <div class="w-full max-w-md">
        <?php echo e($slot); ?>

    </div>
    <?php app('livewire')->forceAssetInjection(); ?>
<?php echo app('flux')->scripts(); ?>

</body>
</html>
<?php /**PATH /home/patryk/web/gsc/resources/views/components/layouts/guest.blade.php ENDPATH**/ ?>