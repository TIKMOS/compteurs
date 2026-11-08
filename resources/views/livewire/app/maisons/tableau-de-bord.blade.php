<?php

use App\Models\House;
use App\Modules\Metering\Queries\HouseBalances;
use App\Modules\Metering\Queries\HouseDashboard;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * Lot 2 : tableau de bord d'une maison — à encaisser, à rembourser, et par mois clôturé :
 * facture, participations, part du propriétaire, écart de comptage (EF-20).
 */
new class extends Component
{
    #[Locked]
    public House $house;

    public function mount(House $house): void
    {
        $this->authorize('view', $house);
        $this->house = $house;
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function months(): array
    {
        return app(HouseDashboard::class)->months($this->house);
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function balances(): array
    {
        return app(HouseBalances::class)->execute($this->house);
    }

    public function render(): mixed
    {
        return $this->view()->title('Tableau de bord · '.$this->house->name);
    }
};
?>

@php
    $fcfa = fn (?int $a): string => $a === null ? '—' : \App\Modules\Metering\Data\TenantStatement::fcfa($a);
    $qty = fn (int $m, $u): string => \App\Shared\Support\Milli::format($m).' '.$u->unit();
@endphp

<div class="space-y-6">
    <div>
        <a href="{{ route('maisons.show', $house) }}" wire:navigate class="text-sm text-primary-700 hover:underline">← {{ $house->name }}</a>
        <h1 class="mt-2 text-2xl font-bold text-sand-900">Tableau de bord</h1>
    </div>

    <div class="grid grid-cols-2 gap-3">
        <a href="{{ route('paiements.index', $house) }}" wire:navigate>
            <x-ui.card class="hover:border-primary-300">
                <p class="text-xs text-sand-600">Reste à encaisser</p>
                <p class="text-lg font-bold tabular-nums whitespace-nowrap {{ $this->balances['totalRemaining'] > 0 ? 'text-warning' : 'text-success' }}">{{ $fcfa($this->balances['totalRemaining']) }}</p>
            </x-ui.card>
        </a>
        <a href="{{ route('paiements.index', $house) }}" wire:navigate>
            <x-ui.card class="hover:border-primary-300">
                <p class="text-xs text-sand-600">À rembourser aux locataires</p>
                <p class="text-lg font-bold tabular-nums whitespace-nowrap">{{ $fcfa($this->balances['totalRefunds']) }}</p>
            </x-ui.card>
        </a>
    </div>

    <div class="flex flex-wrap gap-2">
        <x-ui.button as="a" href="{{ route('maisons.export', $house) }}" variant="outline" size="sm">Exporter l'historique (CSV)</x-ui.button>
    </div>

    @forelse ($this->months as $row)
        <x-ui.card class="space-y-3" wire:key="month-{{ $row['period']->id }}">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-base font-semibold text-sand-900">
                    <a href="{{ route('periodes.summary', [$house, $row['period']->month->format('Y-m')]) }}" wire:navigate class="hover:underline">
                        {{ \Illuminate\Support\Str::ucfirst($row['period']->label()) }}
                    </a>
                </h2>
                @if ($row['estimated'] > 0)
                    <x-ui.badge tone="warning" size="sm">{{ $row['estimated'] }} relevé{{ $row['estimated'] > 1 ? 's' : '' }} estimé{{ $row['estimated'] > 1 ? 's' : '' }}</x-ui.badge>
                @endif
            </div>

            @foreach (\App\Shared\Enums\Utility::cases() as $utility)
                @isset($row['utilities'][$utility->value])
                    @php
                        $u = $row['utilities'][$utility->value];
                    @endphp
                    <dl class="text-sm space-y-0.5" wire:key="u-{{ $row['period']->id }}-{{ $utility->value }}">
                        <dt class="font-medium text-sand-900">{{ \Illuminate\Support\Str::ucfirst($utility->label()) }}</dt>
                        <div class="flex justify-between gap-3"><dt class="text-sand-600">Facture</dt><dd class="tabular-nums whitespace-nowrap">{{ $fcfa($u['bill']) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-sand-600">Participations des locataires</dt><dd class="tabular-nums whitespace-nowrap">{{ $fcfa($u['tenants']) }}</dd></div>
                        @if ($u['owner'] !== null)
                            <div class="flex justify-between gap-3"><dt class="text-sand-600">Part du propriétaire</dt><dd class="tabular-nums whitespace-nowrap">{{ $u['owner'] < 0 ? '−'.$fcfa(-$u['owner']) : $fcfa($u['owner']) }}</dd></div>
                        @endif
                        @if ($u['gap'] !== null)
                            <div class="flex justify-between gap-3">
                                <dt class="text-sand-600">Écart de comptage</dt>
                                <dd class="tabular-nums whitespace-nowrap {{ abs((float) str_replace(',', '.', $u['gapPct'])) >= 10 ? 'text-warning font-medium' : '' }}">
                                    {{ $qty($u['gap'], $utility) }} ({{ $u['gapPct'] }} %)
                                </dd>
                            </div>
                        @endif
                    </dl>
                @endisset
            @endforeach
        </x-ui.card>
    @empty
        <x-ui.card role="status">
            <p class="text-sm text-sand-600">Aucun mois clôturé pour l'instant.</p>
        </x-ui.card>
    @endforelse

    <p class="text-xs text-sand-500">
        Écart de comptage = consommation du compteur principal − somme des décompteurs, quand elle est saisie sur la facture.
        Un écart important et durable peut signaler une fuite ou un branchement non relevé. Il ne change aucun montant.
    </p>
</div>
