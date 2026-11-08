<?php

use App\Models\House;
use App\Models\SubMeter;
use App\Models\Unit;
use App\Modules\Metering\Actions\ArchiveUnit;
use App\Modules\Metering\Actions\InstallSubMeter;
use App\Modules\Metering\Actions\RenameUnit;
use App\Modules\Metering\Actions\UpdateSubMeter;
use App\Modules\Metering\Exceptions\ActiveSubMeterExistsException;
use App\Modules\Metering\Exceptions\OccupancyRejectedException;
use App\Modules\Metering\Livewire\Concerns\InteractsWithSubMeterForm;
use App\Shared\Enums\Utility;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * UC-03 : fiche d'un logement — nom, décompteurs (pose, correction), archivage.
 * Le remplacement d'un décompteur (EF-18) arrive avec la saisie des relevés (tranche 4).
 */
new class extends Component
{
    use InteractsWithSubMeterForm;

    #[Locked]
    public House $house;

    #[Locked]
    public Unit $unit;

    public string $label = '';

    public bool $editingLabel = false;

    /** Fluide dont le formulaire de décompteur est ouvert (pose ou correction). */
    #[Locked]
    public ?string $editingMeter = null;

    public ?string $statusMessage = null;

    public function mount(House $house, Unit $unit): void
    {
        $this->authorize('view', $unit);
        $this->house = $house;
        $this->unit = $unit->load(['subMeters', 'occupancies.tenant']);
        $this->label = $unit->label;
    }

    public function saveLabel(): void
    {
        $this->authorize('update', $this->unit);

        $this->validate([
            'label' => [
                'required', 'string', 'max:60',
                Rule::unique('units', 'label')->where('house_id', $this->house->id)->ignore($this->unit->id),
            ],
        ], ['label.unique' => 'Cette maison a déjà un logement de ce nom (archivé ou non).'], ['label' => 'nom du logement']);

        app(RenameUnit::class)->execute($this->unit, $this->label);
        $this->editingLabel = false;
        $this->statusMessage = 'Le nom du logement est enregistré.';
    }

    public function editMeter(string $utility): void
    {
        $this->authorize('update', $this->unit);
        $utility = Utility::from($utility);
        $meter = $this->unit->activeSubMeter($utility);

        $meter === null ? $this->resetSubMeterFields($utility) : $this->fillSubMeterFields($meter);
        $this->resetValidation();
        $this->editingMeter = $utility->value;
    }

    public function cancelMeter(): void
    {
        $this->editingMeter = null;
        $this->resetValidation();
    }

    public function saveMeter(): void
    {
        $this->authorize('update', $this->unit);
        if ($this->editingMeter === null) {
            return;
        }

        $utility = Utility::from($this->editingMeter);
        $installation = $this->subMeterInstallationFor($utility);
        $meter = $this->unit->activeSubMeter($utility);

        if ($meter === null) {
            try {
                app(InstallSubMeter::class)->execute($this->unit, $utility, $installation);
            } catch (ActiveSubMeterExistsException $e) {
                throw ValidationException::withMessages(["installIndexes.{$utility->value}" => $e->getMessage()]);
            }
            $this->statusMessage = "Le décompteur d'{$utility->label()} est ajouté.";
        } else {
            try {
                app(UpdateSubMeter::class)->execute($meter, $installation);
            } catch (LogicException $e) {
                throw ValidationException::withMessages(["installIndexes.{$utility->value}" => $e->getMessage()]);
            }
            $this->statusMessage = "Le décompteur d'{$utility->label()} est corrigé.";
        }

        $this->unit->load('subMeters');
        $this->editingMeter = null;
    }

    public function archive(): void
    {
        $this->authorize('delete', $this->unit);
        $label = $this->unit->label;

        try {
            app(ArchiveUnit::class)->execute($this->unit);
        } catch (OccupancyRejectedException $e) {
            $this->addError('archive', $e->getMessage());

            return;
        }

        session()->flash('status', "Le logement « {$label} » est archivé.");
        $this->redirectRoute('maisons.show', $this->house, navigate: true);
    }

    public function render(): mixed
    {
        return $this->view()->title($this->unit->label.' · '.$this->house->name);
    }
};
?>

<div class="space-y-6">
    <div>
        <a href="{{ route('maisons.show', $house) }}" wire:navigate class="text-sm text-primary-700 hover:underline">← {{ $house->name }}</a>

        @if ($editingLabel)
            <form wire:submit="saveLabel" class="mt-2 space-y-3">
                <x-ui.input id="label" label="Nom du logement" wire:model="label" :error="$errors->first('label')" required autofocus />
                <div class="flex gap-2">
                    <x-ui.button type="submit" size="sm">Enregistrer</x-ui.button>
                    <x-ui.button variant="outline" size="sm" wire:click="$set('editingLabel', false)">Annuler</x-ui.button>
                </div>
            </form>
        @else
            <div class="mt-2 flex items-center justify-between gap-3">
                <h1 class="text-2xl font-bold text-sand-900">{{ $unit->label }}</h1>
                <button type="button" wire:click="$set('editingLabel', true)" class="px-1 text-sm font-medium text-primary-700 hover:underline">
                    Renommer
                </button>
            </div>
        @endif
    </div>

    @if ($statusMessage)
        <div class="rounded-[var(--radius)] border border-success-border bg-success-bg px-3 py-2 text-sm text-success" role="status">
            {{ $statusMessage }}
        </div>
    @endif

    @php
        $current = $unit->currentOccupancy();
        $vacantSince = $unit->vacantSince();
        $hasMeters = $unit->subMeters->contains(fn ($m) => $m->isActive());
    @endphp

    <x-ui.card class="space-y-4">
        <h2 class="text-base font-semibold text-sand-900">Occupation</h2>

        @if ($current)
            <div class="flex items-start justify-between gap-3">
                <div>
                    <x-ui.badge tone="success">Occupé</x-ui.badge>
                    <p class="mt-1 font-medium text-sand-900">
                        <a href="{{ route('locataires.show', $current->tenant) }}" wire:navigate class="hover:underline">{{ $current->tenant->name }}</a>
                    </p>
                    <p class="text-sm text-sand-600">depuis le {{ $current->started_on->format('d/m/Y') }}</p>
                </div>
            </div>
            <x-ui.button as="a" href="{{ route('logements.exit', [$house, $unit]) }}" wire:navigate variant="outline" class="w-full sm:w-auto">
                Enregistrer la sortie
            </x-ui.button>
        @else
            <div>
                <x-ui.badge tone="warning">Vacant</x-ui.badge>
                <p class="mt-1 text-sm text-sand-600">
                    {{ $vacantSince ? 'Depuis le '.$vacantSince->format('d/m/Y').'. ' : '' }}La consommation d'un logement vacant est à la charge du propriétaire.
                </p>
            </div>
            @if ($hasMeters)
                <x-ui.button as="a" href="{{ route('logements.entry', [$house, $unit]) }}" wire:navigate class="w-full sm:w-auto">
                    Enregistrer une entrée
                </x-ui.button>
            @else
                <p class="text-sm text-sand-600">Ajoutez d'abord un décompteur pour enregistrer une entrée.</p>
            @endif
        @endif

        @if ($unit->occupancies->whereNotNull('ended_on')->isNotEmpty())
            <details class="text-sm">
                <summary class="cursor-pointer text-primary-700">Occupants précédents</summary>
                <ul class="mt-2 space-y-1">
                    @foreach ($unit->occupancies->whereNotNull('ended_on')->sortByDesc(fn ($o) => $o->started_on->toDateString()) as $past)
                        <li class="text-sand-700" wire:key="past-{{ $past->id }}">
                            {{ $past->tenant->name }} : du {{ $past->started_on->format('d/m/Y') }} au {{ $past->ended_on->format('d/m/Y') }}
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    </x-ui.card>

    @foreach (\App\Shared\Enums\Utility::cases() as $utility)
        @php
            $meter = $unit->activeSubMeter($utility);
        @endphp

        <x-ui.card class="space-y-4" wire:key="meter-{{ $utility->value }}">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-base font-semibold text-sand-900">Décompteur d'{{ $utility->label() }}</h2>
                @if ($editingMeter !== $utility->value)
                    <button type="button" wire:click="editMeter('{{ $utility->value }}')" class="px-1 text-sm font-medium text-primary-700 hover:underline">
                        {{ $meter ? 'Corriger' : 'Ajouter' }}
                    </button>
                @endif
            </div>

            @if ($editingMeter === $utility->value)
                <form wire:submit="saveMeter" class="space-y-4">
                    <x-sub-meter-fields :utility="$utility" />
                    <div class="flex gap-2">
                        <x-ui.button type="submit" size="sm">Enregistrer</x-ui.button>
                        <x-ui.button variant="outline" size="sm" wire:click="cancelMeter">Annuler</x-ui.button>
                    </div>
                </form>
            @elseif ($meter)
                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-sand-500">Index de pose</dt>
                        <dd class="text-sand-900">{{ \App\Models\SubMeter::formatIndex($meter->install_index) }} {{ $utility->unit() }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-sand-500">Posé le</dt>
                        <dd class="text-sand-900">{{ $meter->installed_on->format('d/m/Y') }}</dd>
                    </div>
                    <div class="col-span-2">
                        <dt class="text-xs font-medium uppercase tracking-wide text-sand-500">Numéro de série</dt>
                        <dd class="text-sand-900">{{ $meter->serial_number ?? 'Non renseigné' }}</dd>
                    </div>
                </dl>
            @else
                <p class="text-sm text-sand-600">
                    Pas de décompteur : ce logement ne participe pas aux frais d'{{ $utility->label() }}.
                </p>
            @endif
        </x-ui.card>
    @endforeach

    <div class="pt-2">
        <button
            type="button"
            class="px-1 text-sm font-medium text-danger hover:underline"
            wire:click="archive"
            wire:confirm="Archiver « {{ $unit->label }} » ? Il n'apparaîtra plus dans la maison ; ses décompteurs restent enregistrés."
        >
            Archiver ce logement
        </button>
        @error('archive') <p class="mt-1 text-sm text-danger" role="alert">{{ $message }}</p> @enderror
    </div>
</div>
