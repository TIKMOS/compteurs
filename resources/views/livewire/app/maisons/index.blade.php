<?php

use App\Models\House;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * UC-02 : liste des maisons du propriétaire connecté (archivées exclues).
 */
new #[Title('Mes maisons')] class extends Component
{
    /**
     * @return Collection<int, House>
     */
    #[Computed]
    public function houses(): Collection
    {
        return House::query()
            ->ownedBy(auth()->user())
            ->with('pricings')
            ->orderBy('name')
            ->get();
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between gap-3">
        <h1 class="text-2xl font-bold text-sand-900">Mes maisons</h1>

        @if ($this->houses->isNotEmpty())
            <x-ui.button as="a" href="{{ route('maisons.create') }}" wire:navigate size="sm">
                + Nouvelle maison
            </x-ui.button>
        @endif
    </div>

    @forelse ($this->houses as $house)
        <a href="{{ route('maisons.show', $house) }}" wire:navigate class="block" wire:key="house-{{ $house->ulid }}">
            <x-ui.card class="hover:border-primary-300 transition-colors">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-sand-900 truncate">{{ $house->name }}</h2>
                        @if ($house->address)
                            <p class="text-sm text-sand-600 truncate">{{ $house->address }}</p>
                        @endif
                    </div>
                    <span class="text-sand-400" aria-hidden="true">›</span>
                </div>

                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach (\App\Shared\Enums\Utility::cases() as $utility)
                        @php
                            $pricing = $house->currentPricing($utility);
                        @endphp
                        <x-ui.badge :tone="$pricing?->mode === \App\Shared\Enums\PricingMode::FixedPrice ? 'secondary' : 'neutral'">
                            {{ \Illuminate\Support\Str::ucfirst($utility->label()) }} :
                            {{ $pricing?->mode === \App\Shared\Enums\PricingMode::FixedPrice ? 'prix fixé' : 'répartition de la facture' }}
                        </x-ui.badge>
                    @endforeach
                </div>
            </x-ui.card>
        </a>
    @empty
        <x-ui.card padding="lg" class="text-center" role="status">
            <h2 class="text-lg font-semibold text-sand-900">Aucune maison pour l'instant</h2>
            <p class="mt-1 text-sm text-sand-600">
                Ajoutez votre première maison pour répartir les charges d'eau et d'électricité entre vos locataires.
            </p>
            <x-ui.button as="a" href="{{ route('maisons.create') }}" wire:navigate class="mt-5">
                Ajouter une maison
            </x-ui.button>
        </x-ui.card>
    @endforelse
</div>
