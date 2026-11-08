@props([
    'tone' => 'neutral',
    'size' => 'md',
])

@php
    $tones = [
        'neutral' => 'bg-sand-100 text-sand-800',
        'primary' => 'bg-primary-50 text-primary-800',
        'secondary' => 'bg-secondary-50 text-secondary-800',
        'success' => 'bg-success-bg text-success',
        'warning' => 'bg-warning-bg text-warning',
        'danger' => 'bg-danger-bg text-danger',
        'info' => 'bg-info-bg text-info',
    ];

    $sizes = [
        'sm' => 'text-xs px-1.5 py-0.5',
        'md' => 'text-xs px-2 py-0.5',
        'lg' => 'text-sm px-2.5 py-1',
    ];

    $classes = trim(
        'inline-flex items-center gap-1 font-medium rounded-full whitespace-nowrap '.
        ($tones[$tone] ?? $tones['neutral']).' '.
        ($sizes[$size] ?? $sizes['md'])
    );
@endphp

<span {{ $attributes->class($classes) }}>
    {{ $slot }}
</span>
