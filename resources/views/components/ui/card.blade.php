@props([
    'padding' => 'md',
    'elevated' => false,
])

@php
    $paddings = [
        'none' => '',
        'sm' => 'p-3',
        'md' => 'p-4 sm:p-5',
        'lg' => 'p-6 sm:p-8',
    ];
    $shadow = $elevated ? 'shadow-md shadow-sand-900/5' : 'shadow-sm shadow-sand-900/5';
    $classes = trim('rounded-[calc(var(--radius)*2)] bg-white border border-sand-200 '.$shadow.' '.($paddings[$padding] ?? $paddings['md']));
@endphp

<div {{ $attributes->class($classes) }}>
    {{ $slot }}
</div>
