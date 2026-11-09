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

    /** Lien en clair, affiché une seule fois juste après sa création (EF-38). */
    public ?string $newLink = null;

    public function createLink(): void
    {
        $this->authorize('update', $this->tenant);
        $this->newLink = app(\App\Modules\Metering\Actions\CreateTenantAccessLink::class)->execute($this->tenant, auth()->user());
        $this->statusMessage = null;
    }

    public function revokeLink(): void
    {
        $this->authorize('update', $this->tenant);
        app(\App\Modules\Metering\Actions\RevokeTenantAccessLink::class)->execute($this->tenant);
        $this->newLink = null;
        $this->statusMessage = 'Le lien personnel est révoqué : il ne fonctionne plus.';
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

    /**
     * @return list<array<string, mixed>>
     */
    #[\Livewire\Attributes\Computed]
    public function history(): array
    {
        return app(\App\Modules\Metering\Queries\ConsumptionHistory::class)->forTenant($this->tenant);
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

    @php
        $activeLink = $tenant->activeAccessLink();
    @endphp
    <x-ui.card class="space-y-3">
        <h2 class="text-base font-semibold text-sand-900">Accès du locataire</h2>
        <p class="text-sm text-sand-600">
            Un lien personnel permet à {{ $tenant->name }} de consulter ses participations des mois clôturés et leurs fiches PDF,
            sans compte et sans rien pouvoir modifier.
        </p>

        @if ($newLink)
            @php
                $message = "Bonjour {$tenant->name}, voici votre lien personnel pour consulter votre participation aux frais d'eau et d'électricité : {$newLink}";
            @endphp
            <div class="space-y-2 rounded-[var(--radius)] border border-success-border bg-success-bg p-3">
                <p class="text-sm font-medium text-sand-900">Lien créé. Envoyez-le maintenant : il ne sera plus affiché ensuite.</p>
                <input type="text" readonly value="{{ $newLink }}" onclick="this.select()" aria-label="Lien personnel"
                    class="block w-full h-11 rounded-[var(--radius)] border border-sand-300 bg-white text-sm text-sand-900" />
                <div class="grid grid-cols-2 gap-2">
                    <x-ui.button as="a" size="sm" target="_blank" rel="noopener"
                        href="https://wa.me/{{ $tenant->phone ? ltrim($tenant->phone, '+') : '' }}?text={{ rawurlencode($message) }}">Envoyer par WhatsApp</x-ui.button>
                    <x-ui.button as="a" size="sm" variant="outline" href="sms:{{ $tenant->phone }}?body={{ rawurlencode($message) }}">Envoyer par SMS</x-ui.button>
                </div>
            </div>
        @elseif ($activeLink)
            <p class="text-sm text-sand-700">
                Lien actif depuis le {{ $activeLink->created_at->timezone(config('app.timezone'))->format('d/m/Y') }}
                @if ($activeLink->last_used_at) · consulté le {{ $activeLink->last_used_at->timezone(config('app.timezone'))->format('d/m/Y') }} @endif
            </p>
        @endif

        <div class="flex flex-wrap gap-2">
            <x-ui.button size="sm" wire:click="createLink"
                :wire:confirm="$activeLink ? 'Créer un nouveau lien ? L\'ancien cessera de fonctionner.' : null">
                {{ $activeLink ? 'Nouveau lien' : 'Créer le lien personnel' }}
            </x-ui.button>
            @if ($activeLink)
                <x-ui.button size="sm" variant="outline" wire:click="revokeLink" wire:confirm="Révoquer le lien ? {{ $tenant->name }} ne pourra plus consulter ses fiches.">
                    Révoquer
                </x-ui.button>
            @endif
        </div>
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
            @if (! $occupancy->isCurrent())
                @php
                    $exitClosed = $occupancy->unit->house->billingPeriods()
                        ->where('month', $occupancy->ended_on->startOfMonth()->toDateString())
                        ->where('status', \App\Shared\Enums\PeriodStatus::Closed)->exists();
                @endphp
                @if ($exitClosed)
                    <a href="{{ route('sorties.pdf', [$occupancy->unit->house, $occupancy->id]) }}" class="-mt-1 inline-block px-1 text-sm font-medium text-primary-700 hover:underline">Fiche de sortie (PDF)</a>
                @else
                    <p class="-mt-1 px-1 text-xs text-sand-600">Fiche de sortie disponible après la clôture de {{ $occupancy->ended_on->locale('fr')->isoFormat('MMMM YYYY') }}.</p>
                @endif
            @endif
        @empty
            <p class="text-sm text-sand-600">Aucun logement : enregistrez son entrée depuis la fiche d'un logement vacant.</p>
        @endforelse
    </x-ui.card>

    <x-consumption-history :rows="$this->history" />

    <div class="pt-2">
        <button type="button" class="px-1 text-sm font-medium text-danger hover:underline" wire:click="archive"
            wire:confirm="Archiver {{ $tenant->name }} ? Son historique est conservé.">
            Archiver ce locataire
        </button>
        @error('archive') <p class="mt-1 text-sm text-danger" role="alert">{{ $message }}</p> @enderror
    </div>
</div>
