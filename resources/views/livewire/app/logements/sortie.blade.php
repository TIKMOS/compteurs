<?php

use App\Models\House;
use App\Models\Occupancy;
use App\Models\Unit;
use App\Modules\Metering\Actions\EndOccupancy;
use App\Modules\Metering\Exceptions\OccupancyRejectedException;
use App\Modules\Metering\Exceptions\PeriodClosedException;
use App\Modules\Metering\Exceptions\ReadingRejectedException;
use App\Modules\Metering\Livewire\Concerns\InteractsWithReadingForm;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * UC-06 / EF-07, EF-08 : sortie du locataire en place, avec un relevé de sortie par
 * décompteur actif. Le logement devient vacant (EF-10).
 */
new class extends Component
{
    use InteractsWithReadingForm;

    #[Locked]
    public House $house;

    #[Locked]
    public Unit $unit;

    #[Locked]
    public ?Occupancy $occupancy = null;

    public function mount(House $house, Unit $unit): void
    {
        $this->authorize('update', $unit);
        $this->house = $house;
        $this->unit = $unit->load(['subMeters', 'occupancies.tenant']);
        $this->occupancy = $this->unit->currentOccupancy();
        $this->readOn = now()->toDateString();

        if ($this->occupancy === null) {
            $this->redirectRoute('logements.show', [$house, $unit], navigate: true);
        }
    }

    public function save(): void
    {
        $this->authorize('update', $this->unit);

        $endedOn = $this->validatedReadOn('date de sortie');
        $indexes = $this->validatedIndexes($this->unit);

        try {
            app(EndOccupancy::class)->execute($this->occupancy, $endedOn, $indexes);
        } catch (OccupancyRejectedException|ReadingRejectedException|PeriodClosedException $e) {
            $this->rejectWith($e);
        }

        session()->flash('status', "La sortie de {$this->occupancy->tenant->name} est enregistrée : {$this->unit->label} est vacant.");
        $this->redirectRoute('logements.show', [$this->house, $this->unit], navigate: true);
    }

    public function render(): mixed
    {
        return $this->view()->title('Sortie · '.$this->unit->label);
    }
};
?>

<div class="space-y-6">
    <div>
        <a href="{{ route('logements.show', [$house, $unit]) }}" wire:navigate class="text-sm text-primary-700 hover:underline">← {{ $unit->label }}</a>
        <h1 class="mt-2 text-2xl font-bold text-sand-900">Sortie de {{ $occupancy?->tenant->name }}</h1>
        <p class="text-sm text-sand-600">
            {{ $unit->label }} · {{ $house->name }} · entré le {{ $occupancy?->started_on->format('d/m/Y') }}
        </p>
    </div>

    <form wire:submit="save" class="space-y-6">
        <x-ui.card class="space-y-4">
            <div>
                <h2 class="text-base font-semibold text-sand-900">Relevé de sortie</h2>
                <p class="text-sm text-sand-600">
                    Sa participation du mois de sortie sera calculée à la clôture du mois, avec la facture.
                </p>
            </div>
            <x-reading-fields :unit="$unit" date-label="Date de sortie" :hints="$this->lastReadings()" />
        </x-ui.card>

        <x-ui.button type="submit" variant="primary" class="w-full sm:w-auto">Enregistrer la sortie</x-ui.button>
    </form>
</div>
