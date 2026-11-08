<?php

use App\Models\House;
use App\Modules\Metering\Actions\ArchiveHouse;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * UC-02 : fiche d'une maison — compteurs principaux, mode de calcul de chaque fluide, archivage.
 * Les logements arrivent avec la tranche 2 (UC-03).
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

    <x-ui.card>
        <h2 class="text-base font-semibold text-sand-900">Logements</h2>
        <p class="mt-1 text-sm text-sand-600">La gestion des logements et de leurs décompteurs arrive à l'étape suivante.</p>
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
