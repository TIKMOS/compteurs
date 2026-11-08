<?php

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * UC-04 : locataires du propriétaire connecté, avec le logement qu'ils occupent.
 */
new #[Title('Mes locataires')] class extends Component
{
    /**
     * @return Collection<int, Tenant>
     */
    #[Computed]
    public function tenants(): Collection
    {
        return Tenant::query()
            ->ownedBy(auth()->user())
            ->with(['occupancies' => fn ($q) => $q->whereNull('ended_on')->with('unit.house')])
            ->orderBy('name')
            ->get();
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between gap-3">
        <h1 class="text-2xl font-bold text-sand-900">Mes locataires</h1>
        <x-ui.button as="a" href="{{ route('locataires.create') }}" wire:navigate size="sm">+ Nouveau</x-ui.button>
    </div>

    @forelse ($this->tenants as $tenant)
        <a href="{{ route('locataires.show', $tenant) }}" wire:navigate class="block" wire:key="tenant-{{ $tenant->ulid }}">
            <x-ui.card class="hover:border-primary-300 transition-colors">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="font-semibold text-sand-900 truncate">{{ $tenant->name }}</h2>
                        @if ($tenant->phone)
                            <p class="text-sm text-sand-600">{{ $tenant->displayPhone() }}</p>
                        @endif
                        <p class="mt-1 text-sm text-sand-700">
                            @forelse ($tenant->occupancies as $occupancy)
                                {{ $occupancy->unit->label }} · {{ $occupancy->unit->house->name }}@if (! $loop->last), @endif
                            @empty
                                <span class="text-sand-500">Aucun logement en ce moment</span>
                            @endforelse
                        </p>
                    </div>
                    <span class="text-sand-400" aria-hidden="true">›</span>
                </div>
            </x-ui.card>
        </a>
    @empty
        <x-ui.card padding="lg" class="text-center" role="status">
            <h2 class="text-lg font-semibold text-sand-900">Aucun locataire pour l'instant</h2>
            <p class="mt-1 text-sm text-sand-600">
                Vous pouvez aussi créer un locataire directement en enregistrant son entrée dans un logement.
            </p>
        </x-ui.card>
    @endforelse
</div>
