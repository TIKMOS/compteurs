@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'as' => 'button',
])

@php
    $variants = [
        'primary' => 'bg-primary-600 text-white hover:bg-primary-700 focus-visible:bg-primary-700 active:bg-primary-800 disabled:bg-primary-300',
        'secondary' => 'bg-secondary-500 text-white hover:bg-secondary-600 focus-visible:bg-secondary-600 active:bg-secondary-700 disabled:bg-secondary-300',
        'ghost' => 'bg-transparent text-primary-700 hover:bg-sand-100 focus-visible:bg-sand-100 active:bg-sand-200',
        'outline' => 'border border-sand-300 text-sand-800 bg-white hover:bg-sand-50 focus-visible:bg-sand-50',
        'danger' => 'bg-danger text-white hover:opacity-90 focus-visible:opacity-90',
    ];

    $sizes = [
        'sm' => 'h-10 px-3 text-sm gap-1.5',
        'md' => 'h-11 px-4 text-base gap-2',
        'lg' => 'h-12 px-5 text-base gap-2.5',
    ];

    $base = 'inline-flex items-center justify-center font-medium rounded-[var(--radius)] transition-colors disabled:cursor-not-allowed disabled:opacity-60 select-none';

    $classes = trim(($variants[$variant] ?? $variants['primary']).' '.($sizes[$size] ?? $sizes['md']).' '.$base);
@endphp

@if ($as === 'a')
    <a {{ $attributes->class($classes) }}>
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>
        {{ $slot }}
    </button>
@endif
