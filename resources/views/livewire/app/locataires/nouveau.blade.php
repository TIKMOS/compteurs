<?php

use App\Modules\Metering\Actions\SaveTenant;
use App\Modules\Metering\Livewire\Concerns\InteractsWithTenantForm;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * UC-04 / EF-06 : nouveau locataire (nom, téléphone béninois facultatif).
 */
new #[Title('Nouveau locataire')] class extends Component
{
    use InteractsWithTenantForm;

    public function save(): void
    {
        $this->validateTenantFields();

        $tenant = app(SaveTenant::class)->execute(auth()->user(), $this->tenantName, $this->tenantPhone);

        session()->flash('status', "{$tenant->name} est ajouté à vos locataires.");
        $this->redirectRoute('locataires.show', $tenant, navigate: true);
    }
};
?>

<div class="space-y-6">
    <div>
        <a href="{{ route('locataires.index') }}" wire:navigate class="text-sm text-primary-700 hover:underline">← Mes locataires</a>
        <h1 class="mt-2 text-2xl font-bold text-sand-900">Nouveau locataire</h1>
    </div>

    <form wire:submit="save" class="space-y-6">
        <x-ui.card class="space-y-4">
            <x-ui.input id="tenant-name" label="Nom" wire:model="tenantName" :error="$errors->first('tenantName')" required autofocus />
            <x-ui.input id="tenant-phone" label="Téléphone (facultatif)" type="tel" inputmode="tel" wire:model="tenantPhone"
                :error="$errors->first('tenantPhone')" hint="Pour lui envoyer son récapitulatif, ex. 01 97 00 00 00" />
        </x-ui.card>

        <x-ui.button type="submit" variant="primary" class="w-full sm:w-auto">Ajouter le locataire</x-ui.button>
    </form>
</div>
