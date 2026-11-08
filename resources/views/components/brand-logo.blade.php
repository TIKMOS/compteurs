{{-- Logo provisoire : pictogramme de compteur + nom de l'application, en SVG inline (ENF-02). --}}
<span {{ $attributes->class('inline-flex items-center gap-2 text-primary-700') }}>
    <svg viewBox="0 0 32 32" class="h-full w-auto" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="3" width="26" height="26" rx="6" />
        <path d="M9 21a7 7 0 0 1 14 0" />
        <path d="M16 21l4-5" />
        <path d="M10 25h12" />
    </svg>
    <span class="text-xl font-bold tracking-tight text-sand-900">{{ config('app.name') }}</span>
</span>
