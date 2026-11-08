<?php

use App\Models\House;
use App\Models\Unit;
use App\Modules\Metering\Actions\ArchiveHouse;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * UC-02, UC-03 : fiche d'une maison — mode de calcul de chaque fluide, compteurs principaux,
 * logements (ordre naturel des libellés, H-5), archivage.
 */
new class extends Component
{
    #[Locked]
    public House $house;

    public function mount(House $house): void
    {
        $this->authorize('view', $house);
        $this->house = $house->load(['mainMeters', 'pricings']);
    }

    /**
     * @return Collection<int, Unit>
     */
    public function units(): Collection
    {
        return Unit::sortNaturally($this->house->units()->with(['subMeters', 'occupancies.tenant'])->get());
    }

    public function archive(): void
    {
        $this->authorize('delete', $this->house);
        $name = $this->house->name;

        app(ArchiveHouse::class)->execute($this->house);

        session()->flash('status', "La maison « {$name} » est archivée.");
        $this->redirectRoute('maisons.index', navigate: true);
    }

    public function render(): mixed
    {
        return $this->view()->title($this->house->name);
    }
};
?>

<div class="space-y-6">
    <div>
        <a href="{{ route('maisons.index') }}" wire:navigate class="text-sm text-primary-700 hover:underline">← Mes maisons</a>
        <h1 class="mt-2 text-2xl font-bold text-sand-900">{{ $house->name }}</h1>
        @if ($house->address)
            <p class="text-sm text-sand-600">{{ $house->address }}</p>
        @endif
    </div>

    @php
        $currentMonth = now()->startOfMonth();
        $periods = $house->billingPeriods()->orderByDesc('month')->get();
    @endphp

    <x-ui.card class="space-y-3">
        <h2 class="text-base font-semibold text-sand-900">Relevés et factures</h2>
        <x-ui.button as="a" href="{{ route('periodes.show', [$house, $currentMonth->format('Y-m')]) }}" wire:navigate class="w-full">
            Saisir {{ $currentMonth->locale('fr')->isoFormat('MMMM YYYY') }}
        </x-ui.button>

        @if ($periods->isNotEmpty())
            <ul class="divide-y divide-sand-200">
                @foreach ($periods as $period)
                    <li wire:key="period-{{ $period->id }}">
                        <a href="{{ route('periodes.show', [$house, $period->month->format('Y-m')]) }}" wire:navigate
                            class="flex items-center justify-between py-2.5 text-sm hover:text-primary-700">
                            <span class="text-sand-900">{{ \Illuminate\Support\Str::ucfirst($period->label()) }}</span>
                            <x-ui.badge :tone="$period->isClosed() ? 'success' : 'neutral'" size="sm">{{ $period->isClosed() ? 'Clôturé' : 'Brouillon' }}</x-ui.badge>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    <x-ui.card class="space-y-4">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-base font-semibold text-sand-900">Calcul des participations</h2>
            <a href="{{ route('maisons.settings', $house) }}" wire:navigate class="text-sm font-medium text-primary-700 hover:underline">Modifier</a>
        </div>

        <dl class="space-y-3">
            @foreach (\App\Shared\Enums\Utility::cases() as $utility)
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-sand-500">{{ $utility->label() }}</dt>
                    <dd class="text-sm text-sand-900">
                        {{ $house->currentPricing($utility)?->rule()->summary($utility->unit()) }}
                    </dd>
                </div>
            @endforeach
        </dl>
    </x-ui.card>

    <x-ui.card class="space-y-4">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-base font-semibold text-sand-900">Compteurs principaux</h2>
            <a href="{{ route('maisons.edit', $house) }}" wire:navigate class="text-sm font-medium text-primary-700 hover:underline">Modifier</a>
        </div>

        <dl class="space-y-3">
            @foreach ($house->mainMeters->sortBy(fn ($m) => $m->utility->value === 'electricity' ? 0 : 1) as $meter)
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-sand-500">{{ $meter->referenceLabel() }}</dt>
                    <dd class="text-sm text-sand-900">{{ $meter->provider_reference ?? 'Non renseigné' }}</dd>
                </div>
            @endforeach
        </dl>
    </x-ui.card>

    <x-ui.card class="space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-base font-semibold text-sand-900">Logements</h2>
            <a href="{{ route('logements.create', $house) }}" wire:navigate class="text-sm font-medium text-primary-700 hover:underline">+ Ajouter</a>
        </div>

        @forelse ($this->units() as $unit)
            <a href="{{ route('logements.show', [$house, $unit]) }}" wire:navigate wire:key="unit-{{ $unit->ulid }}"
                class="flex items-center justify-between gap-3 rounded-[var(--radius)] border border-sand-200 p-3 hover:border-primary-300">
                <div class="min-w-0">
                    <p class="font-medium text-sand-900 truncate">{{ $unit->label }}</p>
                    <p class="text-sm truncate {{ $unit->currentOccupancy() ? 'text-sand-700' : 'text-warning' }}">
                        {{ $unit->currentOccupancy()?->tenant->name ?? 'Vacant' }}
                    </p>
                    <div class="mt-1 flex flex-wrap gap-1.5">
                        @foreach (\App\Shared\Enums\Utility::cases() as $utility)
                            @if ($unit->activeSubMeter($utility))
                                <x-ui.badge tone="primary" size="sm">{{ \Illuminate\Support\Str::ucfirst($utility->label()) }}</x-ui.badge>
                            @else
                                <x-ui.badge tone="neutral" size="sm">Pas de décompteur d'{{ $utility->label() }}</x-ui.badge>
                            @endif
                        @endforeach
                    </div>
                </div>
                <span class="text-sand-400" aria-hidden="true">›</span>
            </a>
        @empty
            <p class="text-sm text-sand-600">
                Aucun logement. Ajoutez chaque chambre, appartement ou boutique qui a son propre décompteur.
            </p>
        @endforelse
    </x-ui.card>

    <div class="pt-2">
        <button
            type="button"
            class="px-1 text-sm font-medium text-danger hover:underline"
            wire:click="archive"
            wire:confirm="Archiver « {{ $house->name }} » ? Elle n'apparaîtra plus dans vos maisons ; rien n'est supprimé."
        >
            Archiver cette maison
        </button>
    </div>
</div>
