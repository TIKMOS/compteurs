{{-- Avertissement de conformité à confirmer (EF-48). Affiché par le composant Livewire parent,
     qui fournit les actions confirmCompliance() et cancelCompliance(). --}}
@props(['events' => []])

<div
    class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-sand-900/50 p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="compliance-title"
    x-data
    x-trap.noscroll="true"
    @keydown.escape.window="$wire.cancelCompliance()"
>
    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
        <div class="flex items-start gap-3">
            <svg class="h-6 w-6 shrink-0 text-warning" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />
                <path d="M12 9v4" />
                <path d="M12 17h.01" />
            </svg>
            <h2 id="compliance-title" class="text-lg font-bold text-sand-900">Avertissement</h2>
        </div>

        @if ($events !== [])
            <ul class="mt-3 space-y-1 text-sm text-sand-600">
                @foreach ($events as $event)
                    <li>{{ $event }}</li>
                @endforeach
            </ul>
        @endif

        <p class="mt-4 rounded-[var(--radius)] border border-warning-border bg-warning-bg p-3 text-sm text-sand-900">
            {{ config('legal.compliance_notice') }}
        </p>

        <p class="mt-3 text-xs text-sand-500">
            Votre confirmation est enregistrée avec la date et votre nom.
        </p>

        <div class="mt-5 flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
            <x-ui.button variant="outline" wire:click="cancelCompliance">Annuler</x-ui.button>
            <x-ui.button variant="primary" wire:click="confirmCompliance">J'ai lu et je confirme</x-ui.button>
        </div>
    </div>
</div>
