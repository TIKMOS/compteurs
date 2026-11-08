<?php

use App\Models\House;
use App\Models\Tenant;
use App\Models\Unit;
use App\Modules\Metering\Actions\SaveTenant;
use App\Modules\Metering\Actions\StartOccupancy;
use App\Modules\Metering\Exceptions\OccupancyRejectedException;
use App\Modules\Metering\Exceptions\PeriodClosedException;
use App\Modules\Metering\Exceptions\ReadingRejectedException;
use App\Modules\Metering\Livewire\Concerns\InteractsWithReadingForm;
use App\Modules\Metering\Livewire\Concerns\InteractsWithTenantForm;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * UC-05 / EF-07, EF-08 : entrée d'un locataire, existant ou nouveau, avec un relevé d'entrée
 * par décompteur actif.
 */
new class extends Component
{
    use InteractsWithReadingForm;
    use InteractsWithTenantForm;

    #[Locked]
    public House $house;

    #[Locked]
    public Unit $unit;

    /** « existing » ou « new » */
    public string $tenantChoice = 'new';

    public string $tenantId = '';

    public function mount(House $house, Unit $unit): void
    {
        $this->authorize('update', $unit);
        $this->house = $house;
        $this->unit = $unit->load(['subMeters', 'occupancies.tenant']);
        $this->readOn = now()->toDateString();
        $this->tenantChoice = $this->tenants->isEmpty() ? 'new' : 'existing';

        if ($this->unit->currentOccupancy() !== null) {
            $this->redirectRoute('logements.show', [$house, $unit], navigate: true);
        }
    }

    /**
     * Locataires (non archivés) du propriétaire, par nom. Un locataire peut occuper plusieurs
     * logements à la fois (une chambre et une boutique) : chaque occupation est distincte.
     *
     * @return Collection<int, Tenant>
     */
    #[Computed]
    public function tenants(): Collection
    {
        return Tenant::query()->ownedBy(auth()->user())->orderBy('name')->get();
    }

    public function save(): void
    {
        $this->authorize('update', $this->unit);

        $startedOn = $this->validatedReadOn("date d'entrée");
        $indexes = $this->validatedIndexes($this->unit);
        $this->validateTenant();

        try {
            DB::transaction(function () use ($startedOn, $indexes): void {
                $tenant = $this->tenantChoice === 'existing'
                    ? Tenant::query()->ownedBy(auth()->user())->findOrFail($this->tenantId)
                    : app(SaveTenant::class)->execute(auth()->user(), $this->tenantName, $this->tenantPhone);

                app(StartOccupancy::class)->execute($this->unit, $tenant, $startedOn, $indexes);
            });
        } catch (OccupancyRejectedException|ReadingRejectedException|PeriodClosedException $e) {
            $this->rejectWith($e);
        }

        session()->flash('status', "L'entrée dans « {$this->unit->label} » est enregistrée.");
        $this->redirectRoute('logements.show', [$this->house, $this->unit], navigate: true);
    }

    public function render(): mixed
    {
        return $this->view()->title('Entrée · '.$this->unit->label);
    }

    private function validateTenant(): void
    {
        if ($this->tenantChoice === 'existing') {
            $this->validate(
                ['tenantId' => ['required', Rule::exists('tenants', 'id')->where('user_id', auth()->id())->whereNull('deleted_at')]],
                ['tenantId.required' => 'Choisissez le locataire qui entre.'],
            );

            return;
        }

        $this->validateTenantFields();
    }
};
?>

<div class="space-y-6">
    <div>
        <a href="{{ route('logements.show', [$house, $unit]) }}" wire:navigate class="text-sm text-primary-700 hover:underline">← {{ $unit->label }}</a>
        <h1 class="mt-2 text-2xl font-bold text-sand-900">Entrée d'un locataire</h1>
        <p class="text-sm text-sand-600">{{ $unit->label }} · {{ $house->name }}</p>
    </div>

    <form wire:submit="save" class="space-y-6">
        <x-ui.card class="space-y-4">
            <h2 class="text-base font-semibold text-sand-900">Locataire</h2>

            @if ($this->tenants->isNotEmpty())
                <div class="grid grid-cols-2 gap-1 p-1 rounded-full bg-sand-100" role="tablist" aria-label="Locataire">
                    @foreach (['existing' => 'Locataire existant', 'new' => 'Nouveau locataire'] as $value => $text)
                        <button type="button" role="tab" wire:click="$set('tenantChoice', '{{ $value }}')"
                            aria-selected="{{ $tenantChoice === $value ? 'true' : 'false' }}"
                            class="h-9 rounded-full text-sm font-medium transition-colors {{ $tenantChoice === $value ? 'bg-white text-sand-900 shadow-sm' : 'text-sand-500' }}">
                            {{ $text }}
                        </button>
                    @endforeach
                </div>
            @endif

            @if ($tenantChoice === 'existing')
                <div class="flex flex-col gap-1">
                    <label for="tenant" class="text-sm font-medium text-sand-800">Qui entre ?</label>
                    <select id="tenant" wire:model="tenantId"
                        class="block w-full h-11 rounded-[var(--radius)] border border-sand-300 bg-white text-base text-sand-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-300">
                        <option value="">Choisir…</option>
                        @foreach ($this->tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}{{ $tenant->phone ? ' — '.$tenant->displayPhone() : '' }}</option>
                        @endforeach
                    </select>
                    @error('tenantId') <p class="text-xs text-danger">{{ $message }}</p> @enderror
                </div>
            @else
                <x-ui.input id="tenant-name" label="Nom du locataire" wire:model="tenantName" :error="$errors->first('tenantName')" required />
                <x-ui.input id="tenant-phone" label="Téléphone (facultatif)" type="tel" inputmode="tel" wire:model="tenantPhone"
                    :error="$errors->first('tenantPhone')" hint="Pour lui envoyer son récapitulatif, ex. 01 97 00 00 00" />
            @endif
        </x-ui.card>

        <x-ui.card class="space-y-4">
            <div>
                <h2 class="text-base font-semibold text-sand-900">Relevé d'entrée</h2>
                <p class="text-sm text-sand-600">Le locataire paiera sa consommation à partir de ces index.</p>
            </div>
            <x-reading-fields :unit="$unit" date-label="Date d'entrée" :hints="$this->lastReadings()" />
        </x-ui.card>

        <x-ui.button type="submit" variant="primary" class="w-full sm:w-auto">Enregistrer l'entrée</x-ui.button>
    </form>
</div>
