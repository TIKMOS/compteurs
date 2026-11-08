<?php

use App\Models\House;
use App\Modules\Metering\Actions\CreateUnit;
use App\Modules\Metering\Livewire\Concerns\InteractsWithSubMeterForm;
use App\Shared\Enums\Utility;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * UC-03 / EF-03, EF-04 : ajouter un logement à une maison, avec ses décompteurs.
 */
new class extends Component
{
    use InteractsWithSubMeterForm;

    #[Locked]
    public House $house;

    public string $label = '';

    /** @var array<string, bool> le logement a-t-il un décompteur pour ce fluide ? */
    public array $hasMeter = ['electricity' => true, 'water' => true];

    public function mount(House $house): void
    {
        $this->authorize('update', $house);
        $this->house = $house;

        foreach (Utility::cases() as $utility) {
            $this->resetSubMeterFields($utility);
        }
    }

    public function save(): void
    {
        $this->authorize('update', $this->house);

        $this->validate([
            'label' => ['required', 'string', 'max:60', Rule::unique('units', 'label')->where('house_id', $this->house->id)],
        ], [
            'label.unique' => 'Cette maison a déjà un logement de ce nom (archivé ou non).',
        ], ['label' => 'nom du logement']);

        $installations = [];
        foreach (Utility::cases() as $utility) {
            $installations[$utility->value] = $this->hasMeter[$utility->value]
                ? $this->subMeterInstallationFor($utility)
                : null;
        }

        $unit = app(CreateUnit::class)->execute($this->house, $this->label, $installations);

        session()->flash('status', "Le logement « {$unit->label} » est ajouté.");
        $this->redirectRoute('maisons.show', $this->house, navigate: true);
    }

    public function render(): mixed
    {
        return $this->view()->title('Nouveau logement · '.$this->house->name);
    }
};
?>

<div class="space-y-6">
    <div>
        <a href="{{ route('maisons.show', $house) }}" wire:navigate class="text-sm text-primary-700 hover:underline">← {{ $house->name }}</a>
        <h1 class="mt-2 text-2xl font-bold text-sand-900">Nouveau logement</h1>
    </div>

    <form wire:submit="save" class="space-y-6">
        <x-ui.card class="space-y-4">
            <x-ui.input id="label" label="Nom du logement" wire:model="label" :error="$errors->first('label')"
                hint="Par exemple « Chambre 1 », « Appartement A », « Boutique »" required autofocus />
        </x-ui.card>

        @foreach (\App\Shared\Enums\Utility::cases() as $utility)
            <x-ui.card class="space-y-4" wire:key="meter-{{ $utility->value }}">
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model.live="hasMeter.{{ $utility->value }}" @checked($hasMeter[$utility->value])
                        class="mt-0.5 h-5 w-5 rounded border-sand-300 text-primary-600 focus:ring-primary-300" />
                    <span>
                        <span class="block text-base font-semibold text-sand-900">Décompteur d'{{ $utility->label() }}</span>
                        <span class="block text-xs text-sand-600">
                            Sans décompteur, le logement ne participe pas aux frais d'{{ $utility->label() }} :
                            sa consommation est payée par les autres logements.
                        </span>
                    </span>
                </label>

                @if ($hasMeter[$utility->value])
                    <x-sub-meter-fields :utility="$utility" />
                @endif
            </x-ui.card>
        @endforeach

        <x-ui.button type="submit" variant="primary" class="w-full sm:w-auto">Ajouter le logement</x-ui.button>
    </form>
</div>
