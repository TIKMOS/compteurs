<?php

use App\Models\House;
use App\Modules\Metering\Actions\UpdateHouse;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * UC-02 : modifier le nom, l'adresse et les références fournisseur d'une maison.
 */
new class extends Component
{
    #[Locked]
    public House $house;

    public string $name = '';

    public string $address = '';

    /** @var array<string, string> */
    public array $references = ['electricity' => '', 'water' => ''];

    public function mount(House $house): void
    {
        $this->authorize('update', $house);
        $this->house = $house->load('mainMeters');
        $this->name = $house->name;
        $this->address = (string) $house->address;

        foreach ($this->house->mainMeters as $meter) {
            $this->references[$meter->utility->value] = (string) $meter->provider_reference;
        }
    }

    public function save(): void
    {
        $this->authorize('update', $this->house);
        $this->validate();

        app(UpdateHouse::class)->execute($this->house, $this->name, $this->address, $this->references);

        session()->flash('status', 'Les informations de la maison sont enregistrées.');
        $this->redirectRoute('maisons.show', $this->house, navigate: true);
    }

    public function render(): mixed
    {
        return $this->view()->title('Modifier '.$this->house->name);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('houses', 'name')->where('user_id', auth()->id())->ignore($this->house->id),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'references.electricity' => ['nullable', 'string', 'max:60'],
            'references.water' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return ['name.unique' => 'Vous avez déjà une maison de ce nom (archivée ou non).'];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['name' => 'nom de la maison', 'address' => 'adresse'];
    }
};
?>

<div class="space-y-6">
    <div>
        <a href="{{ route('maisons.show', $house) }}" wire:navigate class="text-sm text-primary-700 hover:underline">← {{ $house->name }}</a>
        <h1 class="mt-2 text-2xl font-bold text-sand-900">Modifier la maison</h1>
    </div>

    <form wire:submit="save" class="space-y-6">
        <x-ui.card class="space-y-4">
            <x-ui.input id="name" label="Nom" wire:model="name" :error="$errors->first('name')" required />
            <x-ui.input id="address" label="Quartier, ville, repère (facultatif)" wire:model="address" :error="$errors->first('address')" />
            <x-ui.input id="ref-electricity" label="N° de compteur SBEE (électricité)" wire:model="references.electricity" :error="$errors->first('references.electricity')" />
            <x-ui.input id="ref-water" label="N° de police SONEB (eau)" wire:model="references.water" :error="$errors->first('references.water')" />
        </x-ui.card>

        <x-ui.button type="submit" variant="primary" class="w-full sm:w-auto">Enregistrer</x-ui.button>
    </form>
</div>
