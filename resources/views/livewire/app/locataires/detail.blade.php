<?php

use App\Models\Tenant;
use App\Modules\Metering\Actions\ArchiveTenant;
use App\Modules\Metering\Actions\SaveTenant;
use App\Modules\Metering\Exceptions\OccupancyRejectedException;
use App\Modules\Metering\Livewire\Concerns\InteractsWithTenantForm;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * UC-04 : fiche d'un locataire — coordonnées, logements occupés (actuels et passés), archivage.
 */
new class extends Component
{
    use InteractsWithTenantForm;

    #[Locked]
    public Tenant $tenant;

    public bool $editing = false;

    public ?string $statusMessage = null;

    public function mount(Tenant $tenant): void
    {
        $this->authorize('view', $tenant);
        $this->tenant = $tenant->load('occupancies.unit.house');
        $this->fillFields();
    }

    public function save(): void
    {
        $this->authorize('update', $this->tenant);
        $this->validateTenantFields($this->tenant);

        app(SaveTenant::class)->execute(auth()->user(), $this->tenantName, $this->tenantPhone, $this->tenant);

        $this->editing = false;
        $this->fillFields();
        $this->statusMessage = 'Les coordonnées sont enregistrées.';
    }

    public function cancel(): void
    {
        $this->editing = false;
        $this->fillFields();
        $this->resetValidation();
    }

    public function archive(): void
    {
        $this->authorize('delete', $this->tenant);
        $name = $this->tenant->name;

        try {
            app(ArchiveTenant::class)->execute($this->tenant);
        } catch (OccupancyRejectedException $e) {
            $this->addError('archive', $e->getMessage());

            return;
        }

        session()->flash('status', "{$name} est archivé.");
        $this->redirectRoute('locataires.index', navigate: true);
    }

    public function render(): mixed
    {
        return $this->view()->title($this->tenant->name);
    }

    private function fillFields(): void
    {
        $this->tenantName = $this->tenant->name;
        $this->tenantPhone = (string) $this->tenant->displayPhone();
    }
};
?>

<div class="space-y-6">
    <div>
        <a href="{{ route('locataires.index') }}" wire:navigate class="text-sm text-primary-700 hover:underline">← Mes locataires</a>
        <h1 class="mt-2 text-2xl font-bold text-sand-900">{{ $tenant->name }}</h1>
    </div>

    @if ($statusMessage)
        <div class="rounded-[var(--radius)] border border-success-border bg-success-bg px-3 py-2 text-sm text-success" role="status">
            {{ $statusMessage }}
        </div>
    @endif

    <x-ui.card class="space-y-4">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-base font-semibold text-sand-900">Coordonnées</h2>
            @unless ($editing)
                <button type="button" wire:click="$set('editing', true)" class="px-1 text-sm font-medium text-primary-700 hover:underline">Modifier</button>
            @endunless
        </div>

        @if ($editing)
            <form wire:submit="save" class="space-y-4">
                <x-ui.input id="tenant-name" label="Nom" wire:model="tenantName" :error="$errors->first('tenantName')" required />
                <x-ui.input id="tenant-phone" label="Téléphone (facultatif)" type="tel" inputmode="tel" wire:model="tenantPhone" :error="$errors->first('tenantPhone')" />
                <div class="flex gap-2">
                    <x-ui.button type="submit" size="sm">Enregistrer</x-ui.button>
                    <x-ui.button variant="outline" size="sm" wire:click="cancel">Annuler</x-ui.button>
                </div>
            </form>
        @else
            <p class="text-sm text-sand-900">{{ $tenant->displayPhone() ?? 'Pas de téléphone' }}</p>
        @endif
    </x-ui.card>

    <x-ui.card class="space-y-3">
        <h2 class="text-base font-semibold text-sand-900">Logements</h2>
        @forelse ($tenant->occupancies->sortByDesc(fn ($o) => $o->started_on->toDateString()) as $occupancy)
            <a href="{{ route('logements.show', [$occupancy->unit->house, $occupancy->unit]) }}" wire:navigate wire:key="occ-{{ $occupancy->id }}"
                class="block rounded-[var(--radius)] border border-sand-200 p-3 hover:border-primary-300">
                <p class="font-medium text-sand-900">{{ $occupancy->unit->label }} · {{ $occupancy->unit->house->name }}</p>
                <p class="text-sm text-sand-600">
                    @if ($occupancy->isCurrent())
                        Depuis le {{ $occupancy->started_on->format('d/m/Y') }}
                    @else
                        Du {{ $occupancy->started_on->format('d/m/Y') }} au {{ $occupancy->ended_on->format('d/m/Y') }}
                    @endif
                </p>
            </a>
        @empty
            <p class="text-sm text-sand-600">Aucun logement : enregistrez son entrée depuis la fiche d'un logement vacant.</p>
        @endforelse
    </x-ui.card>

    <div class="pt-2">
        <button type="button" class="px-1 text-sm font-medium text-danger hover:underline" wire:click="archive"
            wire:confirm="Archiver {{ $tenant->name }} ? Son historique est conservé.">
            Archiver ce locataire
        </button>
        @error('archive') <p class="mt-1 text-sm text-danger" role="alert">{{ $message }}</p> @enderror
    </div>
</div>
