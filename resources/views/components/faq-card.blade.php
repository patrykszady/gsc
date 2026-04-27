@props([
    'class' => '',
])

<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-2xl border border-zinc-200/80 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900/70 ' . $class]) }}>
    {{ $slot }}
</div>