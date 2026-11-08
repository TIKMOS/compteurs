<?php

use App\Models\House;
use App\Modules\Metering\Actions\CreateHouse;
use App\Modules\Metering\Livewire\Concerns\InteractsWithPricingForm;
use App\Shared\Enums\Utility;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * UC-02 / EF-01, EF-39 : création d'une maison, de ses compteurs principaux et du mode de
 * calcul de chaque fluide ; avertissement de conformité si le mode 2 est choisi (EF-48).
 */
new #[Title('Nouvelle maison')] class extends Component
{
    use InteractsWithPricingForm;

    public string $name = '';

    public string $address = '';

    /** @var array<string, string> */
    public array $references = ['electricity' => '', 'water' => ''];

    public function mount(): void
    {
        $this->authorize('create', House::class);
    }

    public function save(): void
    {
        $this->persist(confirmed: false);
    }

    public function confirmCompliance(): void
    {
        $this->persist(confirmed: true);
    }

    private function persist(bool $confirmed): void
    {
        $this->authorize('create', House::class);
        $this->validate();

        $rules = [];
        $events = [];
        foreach (Utility::cases() as $utility) {
            $rules[$utility->value] = $this->pricingRuleFor($utility);
            $events[$utility->value] = $rules[$utility->value]->acknowledgementsRequiredFrom(null);
        }

        if (! $confirmed && array_merge(...array_values($events)) !== []) {
            $this->complianceEvents = [];
            foreach (Utility::cases() as $utility) {
                if ($events[$utility->value] !== []) {
                    $this->askComplianceConfirmation($utility, $events[$utility->value]);
                }
            }

            return;
        }

        $house = app(CreateHouse::class)->execute(
            owner: auth()->user(),
            name: $this->name,
            address: $this->address,
            providerReferences: $this->references,
            pricing: $rules,
            complianceConfirmed: $confirmed,
            ipAddress: request()->ip(),
        );

        session()->flash('status', "La maison « {$house->name} » est créée.");
        $this->redirectRoute('maisons.show', $house, navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('houses', 'name')->where('user_id', auth()->id()),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'references.electricity' => ['nullable', 'string', 'max:60'],
            'references.water' => ['nullable', 'string', 'max:60'],
            'modes.*' => ['required', Rule::in(['bill_split', 'fixed_price'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'name.unique' => 'Vous avez déjà une maison de ce nom (archivée ou non).',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'name' => 'nom de la maison',
            'address' => 'adresse',
            'references.electricity' => 'n° de compteur SBEE',
            'references.water' => 'n° de police SONEB',
        ];
    }
};
?>

<div class="space-y-6">
    <div>
        <a href="{{ route('maisons.index') }}" wire:navigate class="text-sm text-primary-700 hover:underline">← Mes maisons</a>
        <h1 class="mt-2 text-2xl font-bold text-sand-900">Nouvelle maison</h1>
    </div>

    <form wire:submit="save" class="space-y-6">
        <x-ui.card class="space-y-4">
            <h2 class="text-base font-semibold text-sand-900">La maison</h2>

            <x-ui.input id="name" label="Nom" wire:model="name" :error="$errors->first('name')"
                hint="Par exemple « Cotonou Fidjrossè »" required autofocus />

            <x-ui.input id="address" label="Quartier, ville, repère (facultatif)" wire:model="address"
                :error="$errors->first('address')" />
        </x-ui.card>

        <x-ui.card class="space-y-4">
            <h2 class="text-base font-semibold text-sand-900">Compteurs principaux</h2>
            <p class="text-sm text-sand-600">Facultatif : ces numéros figurent sur vos factures.</p>

            <x-ui.input id="ref-electricity" label="N° de compteur SBEE (électricité)" wire:model="references.electricity"
                :error="$errors->first('references.electricity')" />

            <x-ui.input id="ref-water" label="N° de police SONEB (eau)" wire:model="references.water"
                :error="$errors->first('references.water')" />
        </x-ui.card>

        <x-ui.card class="space-y-6">
            <div>
                <h2 class="text-base font-semibold text-sand-900">Calcul de la participation des locataires</h2>
                <p class="text-sm text-sand-600">Vous pourrez le changer plus tard dans les paramètres de la maison.</p>
            </div>

            @foreach (\App\Shared\Enums\Utility::cases() as $utility)
                <x-pricing-fields :utility="$utility" :modes="$modes" />
            @endforeach
        </x-ui.card>

        <x-ui.button type="submit" variant="primary" class="w-full sm:w-auto">
            Créer la maison
        </x-ui.button>
    </form>

    @if ($confirmingCompliance)
        <x-compliance-dialog :events="$complianceEvents" />
    @endif
</div>
