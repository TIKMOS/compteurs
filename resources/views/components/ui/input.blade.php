@props([
    'type' => 'text',
    'label' => null,
    'hint' => null,
    'error' => null,
    'id' => null,
    'leadingIcon' => null,
    'trailingIcon' => null,
])

@php
    $inputId = $id ?? 'input-'.bin2hex(random_bytes(4));
    $hasError = filled($error);
    $describedBy = collect([
        $hint ? $inputId.'-hint' : null,
        $hasError ? $inputId.'-error' : null,
    ])->filter()->implode(' ');

    $base = 'block w-full h-11 rounded-[var(--radius)] border bg-white text-base text-sand-900 placeholder:text-sand-400 focus:outline-none focus:ring-2 focus:ring-primary-300';
    $borderClass = $hasError
        ? 'border-danger focus:border-danger focus:ring-danger/30'
        : 'border-sand-300 focus:border-primary-500';
    $padding = ($leadingIcon ? 'pl-10 ' : 'pl-3 ').($trailingIcon ? 'pr-10' : 'pr-3');
@endphp

<div class="flex flex-col gap-1">
    @if ($label)
        <label for="{{ $inputId }}" class="text-sm font-medium text-sand-800">
            {{ $label }}
        </label>
    @endif

    <div class="relative">
        @if ($leadingIcon)
            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-sand-500">
                {{ $leadingIcon }}
            </span>
        @endif

        <input
            id="{{ $inputId }}"
            type="{{ $type }}"
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            @if ($hasError) aria-invalid="true" @endif
            {{ $attributes->class([$base, $borderClass, $padding]) }}
        />

        @if ($trailingIcon)
            <span class="absolute inset-y-0 right-0 flex items-center pr-3 text-sand-500">
                {{ $trailingIcon }}
            </span>
        @endif
    </div>

    @if ($hint && ! $hasError)
        <p id="{{ $inputId }}-hint" class="text-xs text-sand-500">{{ $hint }}</p>
    @endif

    @if ($hasError)
        <p id="{{ $inputId }}-error" class="text-xs text-danger">{{ $error }}</p>
    @endif
</div>
